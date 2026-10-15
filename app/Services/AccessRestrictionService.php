<?php

namespace App\Services;

use App\Models\Hub;
use App\Models\SecurityEvent;
use App\Models\SecurityMonitoringSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Hub-managed geo / IP access rules (NFR-SEC-FW companion).
 *
 * Modes are mutually exclusive; env deny-list and temporary blocks still apply
 * first via FirewallService.
 */
class AccessRestrictionService
{
    public const MODE_OFF = 'off';

    public const MODE_UK_ONLY = 'uk_only';

    public const MODE_UK_CITIES = 'uk_cities';

    public const MODE_RESTRICT_COUNTRIES = 'restrict_countries';

    public const MODE_ALLOW_COUNTRIES = 'allow_countries';

    public const MODE_RESTRICT_IPS = 'restrict_ips';

    public const MODE_ALLOW_IPS = 'allow_ips';

    /** @var list<string> */
    public const MODES = [
        self::MODE_OFF,
        self::MODE_UK_ONLY,
        self::MODE_UK_CITIES,
        self::MODE_RESTRICT_COUNTRIES,
        self::MODE_ALLOW_COUNTRIES,
        self::MODE_RESTRICT_IPS,
        self::MODE_ALLOW_IPS,
    ];

    /** ISO codes treated as United Kingdom for uk_only / uk_cities. */
    private const UK_COUNTRY_CODES = ['GB'];

    /** Suggested UK cities for the admin UI (not exhaustive). */
    public const SUGGESTED_UK_CITIES = [
        'London', 'Birmingham', 'Manchester', 'Leeds', 'Glasgow', 'Edinburgh',
        'Liverpool', 'Bristol', 'Sheffield', 'Newcastle upon Tyne', 'Nottingham',
        'Cardiff', 'Belfast', 'Leicester', 'Coventry', 'Bradford', 'Southampton',
        'Brighton', 'Reading', 'Milton Keynes', 'Aberdeen', 'Cambridge', 'Oxford',
        'York', 'Bath', 'Norwich', 'Exeter', 'Plymouth', 'Swansea', 'Dundee',
    ];

    private const SETTINGS_CACHE_PREFIX = 'security_firewall:access_settings:hub:';

    public function __construct(
        private readonly HubService $hubs,
        private readonly GeoIpLookupService $geoIp,
        private readonly SecurityMonitoringService $securityMonitoring,
    ) {}

    public function forgetSettingsCache(?int $hubId): void
    {
        if ($hubId) {
            Cache::forget(self::SETTINGS_CACHE_PREFIX.$hubId);
        }
    }

    /**
     * Whether the request should be blocked by hub access rules.
     */
    public function isRequestDenied(Request $request): bool
    {
        if (! (bool) config('security_firewall.enabled', true)) {
            return false;
        }

        $ip = (string) $request->ip();

        // Local / private clients skip hub geo rules by default so admins are not
        // locked out from localhost or office LANs when GeoIP headers are absent.
        // Env deny-list and temporary blocks still apply via FirewallService.
        if ((bool) config('security_firewall.access_restriction_bypass_private_ips', true)
            && $ip !== ''
            && $this->geoIp->isPrivateOrLocal($ip)
        ) {
            return false;
        }

        try {
            $hub = $this->hubs->current();
        } catch (\Throwable) {
            return false;
        }

        if (! $hub->hasSecurityMonitoringFunctionality()) {
            return false;
        }

        $settings = $this->cachedSettings($hub);
        if ($settings === null) {
            return false;
        }

        $mode = (string) ($settings['mode'] ?? self::MODE_OFF);
        if ($mode === self::MODE_OFF || ! in_array($mode, self::MODES, true)) {
            return false;
        }

        $decision = $this->evaluate($mode, $settings, $ip, $request);

        return $decision['denied'];
    }

    /**
     * @param  array{mode: string, countries: list<string>, cities: list<string>, ips: list<string>}  $settings
     * @return array{denied: bool, reason: string, country: ?string, city: ?string}
     */
    public function evaluate(string $mode, array $settings, string $ip, ?Request $request = null): array
    {
        $geo = ['country' => null, 'city' => null, 'source' => 'none'];

        if (in_array($mode, [
            self::MODE_UK_ONLY,
            self::MODE_UK_CITIES,
            self::MODE_RESTRICT_COUNTRIES,
            self::MODE_ALLOW_COUNTRIES,
        ], true)) {
            $geo = $this->geoIp->lookup($ip !== '' ? $ip : null, $request);
        }

        return match ($mode) {
            self::MODE_UK_ONLY => $this->decideUkOnly($geo),
            self::MODE_UK_CITIES => $this->decideUkCities($geo, $settings['cities'] ?? []),
            self::MODE_RESTRICT_COUNTRIES => $this->decideRestrictCountries($geo, $settings['countries'] ?? []),
            self::MODE_ALLOW_COUNTRIES => $this->decideAllowCountries($geo, $settings['countries'] ?? []),
            self::MODE_RESTRICT_IPS => $this->decideRestrictIps($ip, $settings['ips'] ?? []),
            self::MODE_ALLOW_IPS => $this->decideAllowIps($ip, $settings['ips'] ?? []),
            default => ['denied' => false, 'reason' => 'off', 'country' => $geo['country'], 'city' => $geo['city']],
        };
    }

    public function recordDeniedRequest(Request $request, string $reason = 'access_restriction'): void
    {
        if (! config('security_firewall.record_security_events', true)) {
            return;
        }

        try {
            $hub = $this->hubs->current();
            if (! $hub || ! $hub->hasSecurityMonitoringFunctionality()) {
                return;
            }

            $ip = (string) $request->ip();
            $geo = $this->geoIp->lookup($ip !== '' ? $ip : null, $request);

            $this->securityMonitoring->recordEvent($hub, [
                'category' => SecurityEvent::CATEGORY_AUTHORIZATION,
                'event_type' => 'fw.access_restricted',
                'severity' => SecurityEvent::SEVERITY_MEDIUM,
                'title' => 'Request blocked by access restriction',
                'description' => sprintf(
                    'Blocked API request from %s (%s/%s) to %s %s — rule: %s.',
                    $ip !== '' ? $ip : 'unknown',
                    $geo['country'] ?? '??',
                    $geo['city'] ?? '??',
                    $request->method(),
                    '/'.$request->path(),
                    $reason
                ),
                'actor' => $request->user(),
                'request' => $request,
                'properties' => [
                    'source_ip' => $ip,
                    'path' => '/'.$request->path(),
                    'reason' => $reason,
                    'country' => $geo['country'],
                    'city' => $geo['city'],
                ],
            ]);
        } catch (\Throwable) {
            // Monitoring must not break the 403 response.
        }
    }

    /**
     * @return array{mode: string, countries: list<string>, cities: list<string>, ips: list<string>}|null
     */
    private function cachedSettings(Hub $hub): ?array
    {
        $hubId = (int) $hub->id;

        try {
            return Cache::remember(self::SETTINGS_CACHE_PREFIX.$hubId, 60, function () use ($hub) {
                $row = SecurityMonitoringSetting::query()
                    ->where('hub_id', $hub->id)
                    ->first();

                if (! $row) {
                    return [
                        'mode' => self::MODE_OFF,
                        'countries' => [],
                        'cities' => [],
                        'ips' => [],
                    ];
                }

                return [
                    'mode' => (string) ($row->access_restriction_mode ?: self::MODE_OFF),
                    'countries' => array_values(array_filter(array_map(
                        fn ($c) => $this->geoIp->normalizeCountry($c),
                        (array) ($row->access_restriction_countries ?? [])
                    ))),
                    'cities' => array_values(array_filter(array_map(
                        fn ($c) => $this->geoIp->normalizeCity($c),
                        (array) ($row->access_restriction_cities ?? [])
                    ))),
                    'ips' => array_values(array_filter(array_map(
                        fn ($ip) => trim((string) $ip),
                        (array) ($row->access_restriction_ips ?? [])
                    ), fn ($ip) => $ip !== '')),
                ];
            });
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array{country: ?string, city: ?string, source: string}  $geo
     * @return array{denied: bool, reason: string, country: ?string, city: ?string}
     */
    private function decideUkOnly(array $geo): array
    {
        $country = $geo['country'];
        $denied = $country === null || ! in_array($country, self::UK_COUNTRY_CODES, true);

        return [
            'denied' => $denied,
            'reason' => $denied ? 'uk_only' : 'allowed',
            'country' => $country,
            'city' => $geo['city'],
        ];
    }

    /**
     * @param  array{country: ?string, city: ?string, source: string}  $geo
     * @param  list<string>  $cities
     * @return array{denied: bool, reason: string, country: ?string, city: ?string}
     */
    private function decideUkCities(array $geo, array $cities): array
    {
        $country = $geo['country'];
        $city = $geo['city'];

        if ($country === null || ! in_array($country, self::UK_COUNTRY_CODES, true)) {
            return [
                'denied' => true,
                'reason' => 'uk_cities_country',
                'country' => $country,
                'city' => $city,
            ];
        }

        if ($cities === []) {
            return [
                'denied' => true,
                'reason' => 'uk_cities_empty',
                'country' => $country,
                'city' => $city,
            ];
        }

        $denied = $city === null || ! $this->cityMatches($city, $cities);

        return [
            'denied' => $denied,
            'reason' => $denied ? 'uk_cities' : 'allowed',
            'country' => $country,
            'city' => $city,
        ];
    }

    /**
     * @param  array{country: ?string, city: ?string, source: string}  $geo
     * @param  list<string>  $countries
     * @return array{denied: bool, reason: string, country: ?string, city: ?string}
     */
    private function decideRestrictCountries(array $geo, array $countries): array
    {
        $country = $geo['country'];
        if ($countries === [] || $country === null) {
            return [
                'denied' => false,
                'reason' => 'allowed',
                'country' => $country,
                'city' => $geo['city'],
            ];
        }

        $denied = in_array($country, $countries, true);

        return [
            'denied' => $denied,
            'reason' => $denied ? 'restrict_countries' : 'allowed',
            'country' => $country,
            'city' => $geo['city'],
        ];
    }

    /**
     * @param  array{country: ?string, city: ?string, source: string}  $geo
     * @param  list<string>  $countries
     * @return array{denied: bool, reason: string, country: ?string, city: ?string}
     */
    private function decideAllowCountries(array $geo, array $countries): array
    {
        $country = $geo['country'];
        if ($countries === []) {
            return [
                'denied' => true,
                'reason' => 'allow_countries_empty',
                'country' => $country,
                'city' => $geo['city'],
            ];
        }

        $denied = $country === null || ! in_array($country, $countries, true);

        return [
            'denied' => $denied,
            'reason' => $denied ? 'allow_countries' : 'allowed',
            'country' => $country,
            'city' => $geo['city'],
        ];
    }

    /**
     * @param  list<string>  $ips
     * @return array{denied: bool, reason: string, country: ?string, city: ?string}
     */
    private function decideRestrictIps(string $ip, array $ips): array
    {
        if ($ip === '' || $ips === []) {
            return ['denied' => false, 'reason' => 'allowed', 'country' => null, 'city' => null];
        }

        $denied = $this->ipInList($ip, $ips);

        return [
            'denied' => $denied,
            'reason' => $denied ? 'restrict_ips' : 'allowed',
            'country' => null,
            'city' => null,
        ];
    }

    /**
     * @param  list<string>  $ips
     * @return array{denied: bool, reason: string, country: ?string, city: ?string}
     */
    private function decideAllowIps(string $ip, array $ips): array
    {
        if ($ips === []) {
            return ['denied' => true, 'reason' => 'allow_ips_empty', 'country' => null, 'city' => null];
        }

        if ($ip === '') {
            return ['denied' => true, 'reason' => 'allow_ips', 'country' => null, 'city' => null];
        }

        $denied = ! $this->ipInList($ip, $ips);

        return [
            'denied' => $denied,
            'reason' => $denied ? 'allow_ips' : 'allowed',
            'country' => null,
            'city' => null,
        ];
    }

    /**
     * @param  list<string>  $allowed
     */
    private function cityMatches(string $city, array $allowed): bool
    {
        $needle = mb_strtolower(trim($city));
        foreach ($allowed as $entry) {
            if (mb_strtolower(trim((string) $entry)) === $needle) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $list
     */
    public function ipInList(string $ip, array $list): bool
    {
        foreach ($list as $entry) {
            $entry = trim((string) $entry);
            if ($entry === '') {
                continue;
            }
            if ($this->ipMatches($ip, $entry)) {
                return true;
            }
        }

        return false;
    }

    public function ipMatches(string $ip, string $entry): bool
    {
        if (! str_contains($entry, '/')) {
            return strcasecmp($ip, $entry) === 0;
        }

        [$subnet, $mask] = array_pad(explode('/', $entry, 2), 2, null);
        if ($subnet === null || $mask === null || ! is_numeric($mask)) {
            return false;
        }

        $mask = (int) $mask;
        $ipBytes = @inet_pton($ip);
        $subnetBytes = @inet_pton($subnet);
        if ($ipBytes === false || $subnetBytes === false || strlen($ipBytes) !== strlen($subnetBytes)) {
            return false;
        }

        $len = strlen($ipBytes);
        $bits = $len * 8;
        if ($mask < 0 || $mask > $bits) {
            return false;
        }

        $fullBytes = intdiv($mask, 8);
        $remain = $mask % 8;

        if ($fullBytes > 0 && substr($ipBytes, 0, $fullBytes) !== substr($subnetBytes, 0, $fullBytes)) {
            return false;
        }

        if ($remain === 0) {
            return true;
        }

        $maskByte = (~(0xFF >> $remain)) & 0xFF;

        return (ord($ipBytes[$fullBytes]) & $maskByte) === (ord($subnetBytes[$fullBytes]) & $maskByte);
    }

    /**
     * @return list<array{key: string, label: string, description: string}>
     */
    public static function modeOptions(): array
    {
        return [
            [
                'key' => self::MODE_OFF,
                'label' => 'No restriction',
                'description' => 'Anyone can access the application (env deny-list and temporary blocks still apply).',
            ],
            [
                'key' => self::MODE_UK_ONLY,
                'label' => 'UK only',
                'description' => 'Allow requests only from United Kingdom IP addresses.',
            ],
            [
                'key' => self::MODE_UK_CITIES,
                'label' => 'Specific UK cities',
                'description' => 'Allow requests only from selected UK cities (must also resolve as GB).',
            ],
            [
                'key' => self::MODE_RESTRICT_COUNTRIES,
                'label' => 'Restrict countries',
                'description' => 'Block requests from the selected countries; all others are allowed.',
            ],
            [
                'key' => self::MODE_ALLOW_COUNTRIES,
                'label' => 'Allow countries',
                'description' => 'Allow requests only from the selected countries.',
            ],
            [
                'key' => self::MODE_RESTRICT_IPS,
                'label' => 'Restrict IPs',
                'description' => 'Block the listed IPs / CIDR ranges; all others are allowed.',
            ],
            [
                'key' => self::MODE_ALLOW_IPS,
                'label' => 'Allow IPs',
                'description' => 'Allow only the listed IPs / CIDR ranges.',
            ],
        ];
    }
}
