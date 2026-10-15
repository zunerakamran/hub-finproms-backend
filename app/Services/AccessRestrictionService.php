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
 * Rules are layered: any combination can be enabled; a request must pass
 * every enabled rule. Env deny-list / temporary blocks still apply first
 * via FirewallService.
 */
class AccessRestrictionService
{
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
        if ($settings === null || ! $this->anyRuleEnabled($settings)) {
            return false;
        }

        $decision = $this->evaluate($settings, $ip, $request);

        return $decision['denied'];
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array{denied: bool, reason: string, country: ?string, city: ?string}
     */
    public function evaluate(array $settings, string $ip, ?Request $request = null): array
    {
        $needsGeo = ! empty($settings['uk_only'])
            || ! empty($settings['uk_cities_enabled'])
            || ! empty($settings['restrict_countries_enabled'])
            || ! empty($settings['allow_countries_enabled']);

        $geo = ['country' => null, 'city' => null, 'source' => 'none'];
        if ($needsGeo) {
            $geo = $this->geoIp->lookup($ip !== '' ? $ip : null, $request);
        }

        // 1) Blocked IPs always deny.
        if (! empty($settings['restrict_ips_enabled'])) {
            $deniedIps = (array) ($settings['restricted_ips'] ?? []);
            if ($ip !== '' && $deniedIps !== [] && $this->ipInList($ip, $deniedIps)) {
                return $this->deny('restrict_ips', $geo);
            }
        }

        // 2) Allow-only IPs: when enabled, IP must be on the list.
        if (! empty($settings['allow_ips_enabled'])) {
            $allowedIps = (array) ($settings['allowed_ips'] ?? []);
            if ($allowedIps === [] || $ip === '' || ! $this->ipInList($ip, $allowedIps)) {
                return $this->deny('allow_ips', $geo);
            }
        }

        // 3) UK only.
        if (! empty($settings['uk_only'])) {
            $country = $geo['country'];
            if ($country === null || ! in_array($country, self::UK_COUNTRY_CODES, true)) {
                return $this->deny('uk_only', $geo);
            }
        }

        // 4) Specific UK cities (implies GB).
        if (! empty($settings['uk_cities_enabled'])) {
            $cities = (array) ($settings['uk_cities'] ?? []);
            $country = $geo['country'];
            $city = $geo['city'];

            if ($country === null || ! in_array($country, self::UK_COUNTRY_CODES, true)) {
                return $this->deny('uk_cities_country', $geo);
            }
            if ($cities === [] || $city === null || ! $this->cityMatches($city, $cities)) {
                return $this->deny('uk_cities', $geo);
            }
        }

        // 5) Allow-only countries.
        if (! empty($settings['allow_countries_enabled'])) {
            $allowed = (array) ($settings['allowed_countries'] ?? []);
            $country = $geo['country'];
            if ($allowed === [] || $country === null || ! in_array($country, $allowed, true)) {
                return $this->deny('allow_countries', $geo);
            }
        }

        // 6) Restricted countries.
        if (! empty($settings['restrict_countries_enabled'])) {
            $restricted = (array) ($settings['restricted_countries'] ?? []);
            $country = $geo['country'];
            if ($country !== null && $restricted !== [] && in_array($country, $restricted, true)) {
                return $this->deny('restrict_countries', $geo);
            }
        }

        return [
            'denied' => false,
            'reason' => 'allowed',
            'country' => $geo['country'],
            'city' => $geo['city'],
        ];
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
     * @param  array<string, mixed>  $settings
     */
    public function anyRuleEnabled(array $settings): bool
    {
        return ! empty($settings['uk_only'])
            || ! empty($settings['uk_cities_enabled'])
            || ! empty($settings['restrict_countries_enabled'])
            || ! empty($settings['allow_countries_enabled'])
            || ! empty($settings['restrict_ips_enabled'])
            || ! empty($settings['allow_ips_enabled']);
    }

    /**
     * @return array<string, mixed>|null
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
                    return $this->emptySettings();
                }

                return $this->normalizeFromModel($row);
            });
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function normalizeFromModel(SecurityMonitoringSetting $row): array
    {
        return [
            'uk_only' => (bool) ($row->access_uk_only ?? false),
            'uk_cities_enabled' => (bool) ($row->access_uk_cities_enabled ?? false),
            'uk_cities' => array_values(array_filter(array_map(
                fn ($c) => $this->geoIp->normalizeCity($c),
                (array) ($row->access_restriction_cities ?? [])
            ))),
            'restrict_countries_enabled' => (bool) ($row->access_restrict_countries_enabled ?? false),
            'restricted_countries' => array_values(array_filter(array_map(
                fn ($c) => $this->geoIp->normalizeCountry($c),
                (array) ($row->access_restricted_countries ?? [])
            ))),
            'allow_countries_enabled' => (bool) ($row->access_allow_countries_enabled ?? false),
            'allowed_countries' => array_values(array_filter(array_map(
                fn ($c) => $this->geoIp->normalizeCountry($c),
                (array) ($row->access_allowed_countries ?? [])
            ))),
            'restrict_ips_enabled' => (bool) ($row->access_restrict_ips_enabled ?? false),
            'restricted_ips' => $this->normalizeIpList($row->access_restricted_ips ?? []),
            'allow_ips_enabled' => (bool) ($row->access_allow_ips_enabled ?? false),
            'allowed_ips' => $this->normalizeIpList($row->access_allowed_ips ?? []),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function emptySettings(): array
    {
        return [
            'uk_only' => false,
            'uk_cities_enabled' => false,
            'uk_cities' => [],
            'restrict_countries_enabled' => false,
            'restricted_countries' => [],
            'allow_countries_enabled' => false,
            'allowed_countries' => [],
            'restrict_ips_enabled' => false,
            'restricted_ips' => [],
            'allow_ips_enabled' => false,
            'allowed_ips' => [],
        ];
    }

    /**
     * @param  list<mixed>|mixed  $list
     * @return list<string>
     */
    private function normalizeIpList(mixed $list): array
    {
        return array_values(array_filter(array_map(
            fn ($ip) => trim((string) $ip),
            (array) $list
        ), fn ($ip) => $ip !== ''));
    }

    /**
     * @param  array{country: ?string, city: ?string, source?: string}  $geo
     * @return array{denied: bool, reason: string, country: ?string, city: ?string}
     */
    private function deny(string $reason, array $geo): array
    {
        return [
            'denied' => true,
            'reason' => $reason,
            'country' => $geo['country'] ?? null,
            'city' => $geo['city'] ?? null,
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
    public static function ruleOptions(): array
    {
        return [
            [
                'key' => 'uk_only',
                'label' => 'UK only',
                'description' => 'Allow requests only from United Kingdom IP addresses.',
            ],
            [
                'key' => 'uk_cities',
                'label' => 'Specific UK cities',
                'description' => 'Allow requests only from selected UK cities (must also resolve as GB).',
            ],
            [
                'key' => 'restrict_countries',
                'label' => 'Restrict countries',
                'description' => 'Block requests from the selected countries.',
            ],
            [
                'key' => 'allow_countries',
                'label' => 'Allow countries',
                'description' => 'Allow requests only from the selected countries.',
            ],
            [
                'key' => 'restrict_ips',
                'label' => 'Restrict IPs',
                'description' => 'Block the listed IPs / CIDR ranges.',
            ],
            [
                'key' => 'allow_ips',
                'label' => 'Allow IPs',
                'description' => 'Allow only the listed IPs / CIDR ranges.',
            ],
        ];
    }
}
