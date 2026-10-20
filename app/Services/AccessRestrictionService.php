<?php

namespace App\Services;

use App\Models\Hub;
use App\Models\SecurityEvent;
use App\Models\SecurityMonitoringSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Hub-managed geo / IP / VPN access rules (NFR-SEC-FW companion).
 *
 * Precedence (Option B):
 * 1. Restrict IPs → hard deny
 * 2. Allow IPs → hard allow (overrides rules below)
 * 3. Restrict VPN → deny when VPN/proxy detected
 * 4. Allow VPN → hard allow when VPN detected (overrides geo below)
 * 5. Restrict countries (Allow countries can unblock a listed country)
 * 6. UK only / UK cities (Allow countries can override)
 * 7. Allow countries exclusive allowlist / Allow IPs alone / Allow VPN alone
 *
 * Env deny-list / temporary blocks still apply first via FirewallService.
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
     * @return array{denied: bool, reason: string, country: ?string, city: ?string, vpn: ?bool}
     */
    public function evaluate(array $settings, string $ip, ?Request $request = null): array
    {
        $ukOnly = ! empty($settings['uk_only']);
        $ukCitiesEnabled = ! empty($settings['uk_cities_enabled']);
        $restrictCountriesEnabled = ! empty($settings['restrict_countries_enabled']);
        $allowCountriesEnabled = ! empty($settings['allow_countries_enabled']);
        $restrictIpsEnabled = ! empty($settings['restrict_ips_enabled']);
        $allowIpsEnabled = ! empty($settings['allow_ips_enabled']);
        $restrictVpnEnabled = ! empty($settings['restrict_vpn_enabled']);
        $allowVpnEnabled = ! empty($settings['allow_vpn_enabled']);

        $needsGeo = $ukOnly || $ukCitiesEnabled || $restrictCountriesEnabled || $allowCountriesEnabled;
        $needsVpn = $restrictVpnEnabled || $allowVpnEnabled;

        $geo = ['country' => null, 'city' => null, 'vpn' => null, 'source' => 'none'];
        if ($needsGeo || $needsVpn) {
            $geo = $this->geoIp->lookup($ip !== '' ? $ip : null, $request, $needsVpn);
        }

        $allowedIps = (array) ($settings['allowed_ips'] ?? []);
        $restrictedIps = (array) ($settings['restricted_ips'] ?? []);
        $allowedCountries = (array) ($settings['allowed_countries'] ?? []);
        $restrictedCountries = (array) ($settings['restricted_countries'] ?? []);
        $ukCities = (array) ($settings['uk_cities'] ?? []);

        $ipOnAllowList = $allowIpsEnabled
            && $ip !== ''
            && $allowedIps !== []
            && $this->ipInList($ip, $allowedIps);

        // 1) Restricted IPs — hard deny (cannot be overridden by allow lists).
        if ($restrictIpsEnabled
            && $ip !== ''
            && $restrictedIps !== []
            && $this->ipInList($ip, $restrictedIps)
        ) {
            return $this->deny('restrict_ips', $geo);
        }

        // 2) Allowed IPs — hard allow / exception that overrides VPN + geo below.
        if ($ipOnAllowList) {
            return $this->allow('allow_ips_override', $geo);
        }

        $isVpn = ($geo['vpn'] ?? null) === true;

        // 3) Restrict VPN — block detected VPN/proxy (Allow IP already overrode above).
        //    Unknown VPN signal does not block (fail open on detection gaps).
        if ($restrictVpnEnabled && $isVpn) {
            return $this->deny('restrict_vpn', $geo);
        }

        // 4) Allow VPN — hard allow when VPN detected (overrides country / UK / city).
        if ($allowVpnEnabled && $isVpn) {
            return $this->allow('allow_vpn_override', $geo);
        }

        $country = $geo['country'];
        $city = $geo['city'];
        $countryOnAllowList = $allowCountriesEnabled
            && $country !== null
            && $allowedCountries !== []
            && in_array($country, $allowedCountries, true);

        $isUk = $country !== null && in_array($country, self::UK_COUNTRY_CODES, true);
        $ukCityOk = $isUk
            && $city !== null
            && $ukCities !== []
            && $this->cityMatches($city, $ukCities);

        // 5) Restricted countries — unless Allow countries explicitly lists that country.
        if ($restrictCountriesEnabled
            && $country !== null
            && $restrictedCountries !== []
            && in_array($country, $restrictedCountries, true)
            && ! $countryOnAllowList
        ) {
            return $this->deny('restrict_countries', $geo);
        }

        // 6) UK only — Allow countries can override for listed non-UK countries.
        if ($ukOnly && ! $isUk && ! $countryOnAllowList) {
            return $this->deny('uk_only', $geo);
        }

        // 7) UK cities — Allow countries can override for a whole listed country.
        if ($ukCitiesEnabled && ! $ukCityOk && ! $countryOnAllowList) {
            return $this->deny($isUk ? 'uk_cities' : 'uk_cities_country', $geo);
        }

        // 8) Allow countries as exclusive allowlist when the request was not already
        //    admitted by UK-only / UK-cities (those paths stay open without needing
        //    the country on the allow list).
        if ($allowCountriesEnabled && ! $countryOnAllowList) {
            $admittedByUkPath = ($ukOnly && $isUk) || ($ukCitiesEnabled && $ukCityOk);
            if (! $admittedByUkPath) {
                return $this->deny('allow_countries', $geo);
            }
        }

        $hasGeoRules = $ukOnly || $ukCitiesEnabled || $restrictCountriesEnabled || $allowCountriesEnabled;

        // 9) Exclusive allow modes when no geo rules admitted the request:
        //    Allow IPs / Allow VPN alone (or together) require a positive match.
        //    Matches already returned above; reaching here means no match.
        if (! $hasGeoRules && ($allowIpsEnabled || $allowVpnEnabled)) {
            if ($allowIpsEnabled && $allowVpnEnabled) {
                return $this->deny('allow_ips_or_vpn', $geo);
            }
            if ($allowIpsEnabled) {
                return $this->deny('allow_ips', $geo);
            }

            return $this->deny('allow_vpn', $geo);
        }

        return $this->allow('allowed', $geo);
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
                    'vpn' => $geo['vpn'] ?? null,
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
            || ! empty($settings['allow_ips_enabled'])
            || ! empty($settings['restrict_vpn_enabled'])
            || ! empty($settings['allow_vpn_enabled']);
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
            'restrict_vpn_enabled' => (bool) ($row->access_restrict_vpn_enabled ?? false),
            'allow_vpn_enabled' => (bool) ($row->access_allow_vpn_enabled ?? false),
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
            'restrict_vpn_enabled' => false,
            'allow_vpn_enabled' => false,
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
     * @param  array{country: ?string, city: ?string, vpn?: ?bool, source?: string}  $geo
     * @return array{denied: bool, reason: string, country: ?string, city: ?string, vpn: ?bool}
     */
    private function deny(string $reason, array $geo): array
    {
        return [
            'denied' => true,
            'reason' => $reason,
            'country' => $geo['country'] ?? null,
            'city' => $geo['city'] ?? null,
            'vpn' => $geo['vpn'] ?? null,
        ];
    }

    /**
     * @param  array{country: ?string, city: ?string, vpn?: ?bool, source?: string}  $geo
     * @return array{denied: bool, reason: string, country: ?string, city: ?string, vpn: ?bool}
     */
    private function allow(string $reason, array $geo): array
    {
        return [
            'denied' => false,
            'reason' => $reason,
            'country' => $geo['country'] ?? null,
            'city' => $geo['city'] ?? null,
            'vpn' => $geo['vpn'] ?? null,
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
                'description' => 'Allow requests only from United Kingdom IP addresses. Allow IPs / Allow countries can override.',
            ],
            [
                'key' => 'uk_cities',
                'label' => 'Specific UK cities',
                'description' => 'Allow requests only from selected UK cities (must also resolve as GB). Allow IPs / Allow countries can override.',
            ],
            [
                'key' => 'restrict_countries',
                'label' => 'Restrict countries',
                'description' => 'Block requests from the selected countries. A matching Allow IP still gets through; Allow countries can unblock a listed country.',
            ],
            [
                'key' => 'allow_countries',
                'label' => 'Allow countries',
                'description' => 'Allow these countries (and use as exceptions to UK-only / restrict-countries). Combine with Allow IPs for per-IP exceptions.',
            ],
            [
                'key' => 'restrict_ips',
                'label' => 'Restrict IPs',
                'description' => 'Always block these IPs / CIDRs (highest priority — not overridden by allow lists).',
            ],
            [
                'key' => 'allow_ips',
                'label' => 'Allow IPs',
                'description' => 'These IPs are always allowed and override VPN / country / UK / city blocks. Alone, only these IPs may access.',
            ],
            [
                'key' => 'restrict_vpn',
                'label' => 'Restrict VPN',
                'description' => 'Block requests detected as VPN, proxy, or hosting/datacenter exit. Allow IPs can still override. Detection is best-effort.',
            ],
            [
                'key' => 'allow_vpn',
                'label' => 'Allow VPN',
                'description' => 'Detected VPN/proxy exits are always allowed and override country / UK / city blocks. Alone, only VPN traffic may access.',
            ],
        ];
    }
}
