<?php

namespace App\Services;

use App\Models\Hub;
use App\Models\SecurityEvent;
use App\Models\SecurityMonitoringSetting;
use App\Models\User;
use App\Models\UserAccessRestrictionSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Hub + optional per-user geo / IP / VPN access rules (NFR-SEC-FW companion).
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
 * Hub rules apply to every request. User rules merge in after the caller is identified.
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

    private const USER_SETTINGS_CACHE_PREFIX = 'security_firewall:access_settings:hub_user:';

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

    public function forgetUserSettingsCache(?int $hubId, ?int $userId): void
    {
        if ($hubId && $userId) {
            Cache::forget(self::USER_SETTINGS_CACHE_PREFIX.$hubId.':'.$userId);
        }
    }

    /**
     * Whether the request should be blocked by hub (+ user when known) access rules.
     */
    public function isRequestDenied(Request $request): bool
    {
        return (bool) ($this->checkRequest($request)['denied'] ?? false);
    }

    /**
     * Evaluate access rules for the request.
     *
     * @return array{
     *   denied: bool,
     *   would_block: bool,
     *   reason: string,
     *   mode: string,
     *   country: ?string,
     *   city: ?string,
     *   vpn: ?bool
     * }
     */
    public function checkRequest(Request $request): array
    {
        $pass = [
            'denied' => false,
            'would_block' => false,
            'reason' => 'allowed',
            'mode' => 'enforce',
            'country' => null,
            'city' => null,
            'vpn' => null,
        ];

        if (! (bool) config('security_firewall.enabled', true)) {
            return $pass;
        }

        $ip = (string) $request->ip();

        // Local / private clients skip hub geo rules by default so admins are not
        // locked out from localhost or office LANs when GeoIP headers are absent.
        if ((bool) config('security_firewall.access_restriction_bypass_private_ips', true)
            && $ip !== ''
            && $this->geoIp->isPrivateOrLocal($ip)
        ) {
            return $pass;
        }

        try {
            $hub = $this->hubs->current();
        } catch (\Throwable) {
            return $pass;
        }

        if (! $hub->hasSecurityMonitoringFunctionality()) {
            return $pass;
        }

        $user = $this->resolveRequestUser($request);

        return $this->decideForHub($hub, $ip, $user, $request);
    }

    /**
     * Dry-run the production decision engine for an IP (and optional user / geo hints).
     * Never blocks traffic and never writes security events.
     *
     * @param  array{country?: ?string, city?: ?string, vpn?: ?bool}  $geoOverrides
     * @return array{
     *   result: string,
     *   denied: bool,
     *   would_block: bool,
     *   reason: string,
     *   reason_label: string,
     *   mode: string,
     *   ip: string,
     *   country: ?string,
     *   city: ?string,
     *   vpn: ?bool,
     *   private_ip_bypass: bool,
     *   hub_rules_active: bool,
     *   user_rules_active: bool,
     *   user: ?array{id: int, name: ?string, email: ?string}
     * }
     */
    public function simulateForHub(Hub $hub, string $ip, ?User $asUser = null, array $geoOverrides = []): array
    {
        $ip = trim($ip);
        $synthetic = Request::create('/', 'GET', server: ['REMOTE_ADDR' => $ip !== '' ? $ip : '127.0.0.1']);

        $country = isset($geoOverrides['country'])
            ? $this->geoIp->normalizeCountry($geoOverrides['country'])
            : null;
        $city = array_key_exists('city', $geoOverrides)
            ? $this->geoIp->normalizeCity($geoOverrides['city'])
            : null;
        if ($country !== null) {
            $synthetic->headers->set('X-App-Geo-Country', $country);
        }
        if ($city !== null && $city !== '') {
            $synthetic->headers->set('X-App-Geo-City', $city);
        }
        if (array_key_exists('vpn', $geoOverrides) && $geoOverrides['vpn'] !== null) {
            $synthetic->headers->set(
                'X-App-Geo-Vpn',
                filter_var($geoOverrides['vpn'], FILTER_VALIDATE_BOOLEAN) ? '1' : '0'
            );
        }

        $privateBypass = (bool) config('security_firewall.access_restriction_bypass_private_ips', true)
            && $ip !== ''
            && $this->geoIp->isPrivateOrLocal($ip);

        if ($privateBypass || ! (bool) config('security_firewall.enabled', true) || ! $hub->hasSecurityMonitoringFunctionality()) {
            $hubSettings = $this->cachedSettings($hub) ?? $this->emptySettings();
            $userSettings = $asUser ? ($this->cachedUserSettings($hub, $asUser) ?? $this->emptySettings()) : null;
            $reason = $privateBypass
                ? 'private_ip_bypass'
                : (! (bool) config('security_firewall.enabled', true) ? 'firewall_disabled' : 'module_disabled');

            return $this->formatSimulationResult(
                [
                    'denied' => false,
                    'would_block' => false,
                    'reason' => $reason,
                    'mode' => SecurityMonitoringSetting::normalizedEnforcementMode(
                        $this->mergeSettings($hubSettings, $userSettings)['enforcement_mode'] ?? 'enforce'
                    ),
                    'country' => $country,
                    'city' => $city,
                    'vpn' => array_key_exists('vpn', $geoOverrides)
                        ? (filter_var($geoOverrides['vpn'], FILTER_VALIDATE_BOOLEAN) ?: false)
                        : null,
                ],
                $ip,
                $hubSettings,
                $userSettings,
                $asUser,
                $privateBypass
            );
        }

        $decision = $this->decideForHub($hub, $ip, $asUser, $synthetic);
        $hubSettings = $this->cachedSettings($hub) ?? $this->emptySettings();
        $userSettings = $asUser ? ($this->cachedUserSettings($hub, $asUser) ?? $this->emptySettings()) : null;

        return $this->formatSimulationResult(
            $decision,
            $ip,
            $hubSettings,
            $userSettings,
            $asUser,
            false
        );
    }

    /**
     * @return array{
     *   denied: bool,
     *   would_block: bool,
     *   reason: string,
     *   mode: string,
     *   country: ?string,
     *   city: ?string,
     *   vpn: ?bool
     * }
     */
    private function decideForHub(Hub $hub, string $ip, ?User $user, Request $request): array
    {
        $pass = [
            'denied' => false,
            'would_block' => false,
            'reason' => 'allowed',
            'mode' => 'enforce',
            'country' => null,
            'city' => null,
            'vpn' => null,
        ];

        $hubSettings = $this->cachedSettings($hub) ?? $this->emptySettings();
        $userSettings = $user ? $this->cachedUserSettings($hub, $user) : null;
        $settings = $this->mergeSettings($hubSettings, $userSettings);
        $mode = SecurityMonitoringSetting::normalizedEnforcementMode(
            $settings['enforcement_mode'] ?? 'enforce'
        );

        if (! $this->anyRuleEnabled($settings)) {
            return array_merge($pass, ['mode' => $mode]);
        }

        $decision = $this->evaluate($settings, $ip, $request);
        if (! ($decision['denied'] ?? false)) {
            return array_merge($pass, [
                'mode' => $mode,
                'reason' => $decision['reason'] ?? 'allowed',
                'country' => $decision['country'] ?? null,
                'city' => $decision['city'] ?? null,
                'vpn' => $decision['vpn'] ?? null,
            ]);
        }

        // Monitor: log-only; Enforce: hard block.
        if ($mode === 'monitor') {
            return [
                'denied' => false,
                'would_block' => true,
                'reason' => $decision['reason'] ?? 'access_restriction',
                'mode' => 'monitor',
                'country' => $decision['country'] ?? null,
                'city' => $decision['city'] ?? null,
                'vpn' => $decision['vpn'] ?? null,
            ];
        }

        // Access managers: never hard-lock themselves out (still logged as would_block).
        if ($user && $this->userCanManageAccess($hub, $user)) {
            return [
                'denied' => false,
                'would_block' => true,
                'reason' => $decision['reason'] ?? 'access_restriction',
                'mode' => 'enforce',
                'admin_escape' => true,
                'country' => $decision['country'] ?? null,
                'city' => $decision['city'] ?? null,
                'vpn' => $decision['vpn'] ?? null,
            ];
        }

        return [
            'denied' => true,
            'would_block' => false,
            'reason' => $decision['reason'] ?? 'access_restriction',
            'mode' => 'enforce',
            'country' => $decision['country'] ?? null,
            'city' => $decision['city'] ?? null,
            'vpn' => $decision['vpn'] ?? null,
        ];
    }

    /**
     * Evaluate an explicit settings array (for save preview / proposed rules).
     *
     * @param  array<string, mixed>  $settings
     * @return array{denied: bool, would_block: bool, reason: string, mode: string, country: ?string, city: ?string, vpn: ?bool}
     */
    public function decideWithSettings(array $settings, string $ip, ?Request $request = null): array
    {
        $pass = [
            'denied' => false,
            'would_block' => false,
            'reason' => 'allowed',
            'mode' => 'enforce',
            'country' => null,
            'city' => null,
            'vpn' => null,
        ];

        $mode = SecurityMonitoringSetting::normalizedEnforcementMode(
            $settings['enforcement_mode'] ?? 'enforce'
        );

        if (! $this->anyRuleEnabled($settings)) {
            return array_merge($pass, ['mode' => $mode]);
        }

        $decision = $this->evaluate($settings, $ip, $request);
        if (! ($decision['denied'] ?? false)) {
            return array_merge($pass, [
                'mode' => $mode,
                'reason' => $decision['reason'] ?? 'allowed',
                'country' => $decision['country'] ?? null,
                'city' => $decision['city'] ?? null,
                'vpn' => $decision['vpn'] ?? null,
            ]);
        }

        if ($mode === 'monitor') {
            return [
                'denied' => false,
                'would_block' => true,
                'reason' => $decision['reason'] ?? 'access_restriction',
                'mode' => 'monitor',
                'country' => $decision['country'] ?? null,
                'city' => $decision['city'] ?? null,
                'vpn' => $decision['vpn'] ?? null,
            ];
        }

        return [
            'denied' => true,
            'would_block' => false,
            'reason' => $decision['reason'] ?? 'access_restriction',
            'mode' => 'enforce',
            'country' => $decision['country'] ?? null,
            'city' => $decision['city'] ?? null,
            'vpn' => $decision['vpn'] ?? null,
        ];
    }

    private function userCanManageAccess(Hub $hub, User $user): bool
    {
        if (! (bool) config('security_firewall.access_restriction_admin_escape', true)) {
            return false;
        }

        try {
            return app(CapabilitiesMatrixService::class)->userCan($hub, $user, 'secmon_manage_access');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param  array{denied: bool, would_block: bool, reason: string, mode: string, country: ?string, city: ?string, vpn: ?bool}  $decision
     * @param  array<string, mixed>  $hubSettings
     * @param  array<string, mixed>|null  $userSettings
     * @return array<string, mixed>
     */
    private function formatSimulationResult(
        array $decision,
        string $ip,
        array $hubSettings,
        ?array $userSettings,
        ?User $asUser,
        bool $privateIpBypass
    ): array {
        $denied = (bool) ($decision['denied'] ?? false);
        $wouldBlock = (bool) ($decision['would_block'] ?? false);
        $reason = (string) ($decision['reason'] ?? 'allowed');

        $result = 'pass';
        if ($denied) {
            $result = 'block';
        } elseif ($wouldBlock) {
            $result = 'would_block';
        }

        return [
            'result' => $result,
            'denied' => $denied,
            'would_block' => $wouldBlock,
            'reason' => $reason,
            'reason_label' => self::reasonLabel($reason),
            'mode' => (string) ($decision['mode'] ?? 'enforce'),
            'ip' => $ip,
            'country' => $decision['country'] ?? null,
            'city' => $decision['city'] ?? null,
            'vpn' => $decision['vpn'] ?? null,
            'private_ip_bypass' => $privateIpBypass,
            'hub_rules_active' => $this->anyRuleEnabled($hubSettings),
            'user_rules_active' => $userSettings !== null && $this->anyRuleEnabled($userSettings),
            'user' => $asUser ? [
                'id' => (int) $asUser->id,
                'name' => $asUser->name,
                'email' => $asUser->email,
            ] : null,
        ];
    }

    public static function reasonLabel(string $reason): string
    {
        return match ($reason) {
            'allowed', 'allow_ips_override', 'allow_vpn_override' => 'Allowed',
            'restrict_ips' => 'Restrict IPs',
            'restrict_vpn' => 'Block VPN',
            'restrict_countries' => 'Restrict countries',
            'allow_countries' => 'Allow countries',
            'uk_only' => 'UK only',
            'uk_cities', 'uk_cities_country' => 'Specific UK cities',
            'allow_ips', 'allow_ips_or_vpn' => 'Allow IPs',
            'allow_vpn' => 'Allow VPN',
            'private_ip_bypass' => 'Private / local IP (live traffic skips geo rules)',
            'firewall_disabled' => 'Firewall disabled',
            'module_disabled' => 'Security Monitoring not enabled',
            default => $reason,
        };
    }

    /**
     * Normalize API / history snapshot fields into an evaluate()-ready settings array.
     *
     * @param  array<string, mixed>  $api
     * @return array<string, mixed>
     */
    public function settingsFromApiArray(array $api): array
    {
        $base = $this->emptySettings();
        foreach (array_keys($base) as $key) {
            if (array_key_exists($key, $api)) {
                $base[$key] = $api[$key];
            }
        }
        $base['enforcement_mode'] = SecurityMonitoringSetting::normalizedEnforcementMode(
            $base['enforcement_mode'] ?? 'enforce'
        );

        return $base;
    }

    /**
     * Merge hub + user Option B settings (user can tighten / add exceptions).
     *
     * @param  array<string, mixed>  $hub
     * @param  array<string, mixed>|null  $user
     * @return array<string, mixed>
     */
    public function mergeSettings(array $hub, ?array $user): array
    {
        if ($user === null || ! $this->anyRuleEnabled($user)) {
            return $hub;
        }

        $hubRestrictIps = ! empty($hub['restrict_ips_enabled']);
        $userRestrictIps = ! empty($user['restrict_ips_enabled']);
        $hubAllowIps = ! empty($hub['allow_ips_enabled']);
        $userAllowIps = ! empty($user['allow_ips_enabled']);
        $hubRestrictCountries = ! empty($hub['restrict_countries_enabled']);
        $userRestrictCountries = ! empty($user['restrict_countries_enabled']);
        $hubAllowCountries = ! empty($hub['allow_countries_enabled']);
        $userAllowCountries = ! empty($user['allow_countries_enabled']);
        $hubUkCities = ! empty($hub['uk_cities_enabled']);
        $userUkCities = ! empty($user['uk_cities_enabled']);

        $ukCities = array_values((array) ($hub['uk_cities'] ?? []));
        if ($hubUkCities && $userUkCities) {
            $userCitiesLower = array_map(
                fn ($c) => mb_strtolower(trim((string) $c)),
                (array) ($user['uk_cities'] ?? [])
            );
            $ukCities = array_values(array_filter(
                $ukCities,
                fn ($city) => in_array(mb_strtolower(trim((string) $city)), $userCitiesLower, true)
            ));
        } elseif ($userUkCities) {
            $ukCities = array_values((array) ($user['uk_cities'] ?? []));
        }

        return [
            'uk_only' => ! empty($hub['uk_only']) || ! empty($user['uk_only']),
            'uk_cities_enabled' => $hubUkCities || $userUkCities,
            'uk_cities' => $ukCities,
            'restrict_countries_enabled' => $hubRestrictCountries || $userRestrictCountries,
            'restricted_countries' => $this->mergeLists(
                $hubRestrictCountries ? (array) ($hub['restricted_countries'] ?? []) : [],
                $userRestrictCountries ? (array) ($user['restricted_countries'] ?? []) : []
            ),
            'allow_countries_enabled' => $hubAllowCountries || $userAllowCountries,
            'allowed_countries' => $this->mergeLists(
                $hubAllowCountries ? (array) ($hub['allowed_countries'] ?? []) : [],
                $userAllowCountries ? (array) ($user['allowed_countries'] ?? []) : []
            ),
            'restrict_ips_enabled' => $hubRestrictIps || $userRestrictIps,
            'restricted_ips' => $this->mergeLists(
                $hubRestrictIps ? (array) ($hub['restricted_ips'] ?? []) : [],
                $userRestrictIps ? (array) ($user['restricted_ips'] ?? []) : []
            ),
            'allow_ips_enabled' => $hubAllowIps || $userAllowIps,
            'allowed_ips' => $this->mergeLists(
                $hubAllowIps ? (array) ($hub['allowed_ips'] ?? []) : [],
                $userAllowIps ? (array) ($user['allowed_ips'] ?? []) : []
            ),
            'restrict_vpn_enabled' => ! empty($hub['restrict_vpn_enabled']) || ! empty($user['restrict_vpn_enabled']),
            'allow_vpn_enabled' => ! empty($hub['allow_vpn_enabled']) || ! empty($user['allow_vpn_enabled']),
            'enforcement_mode' => $this->mergeEnforcementMode($hub, $user),
        ];
    }

    /**
     * @param  array<string, mixed>  $hub
     * @param  array<string, mixed>  $user
     */
    private function mergeEnforcementMode(array $hub, array $user): string
    {
        $hubActive = $this->anyRuleEnabled($hub);
        $userActive = $this->anyRuleEnabled($user);
        $hubMode = SecurityMonitoringSetting::normalizedEnforcementMode($hub['enforcement_mode'] ?? null);
        $userMode = SecurityMonitoringSetting::normalizedEnforcementMode($user['enforcement_mode'] ?? null);

        if ($hubActive && ! $userActive) {
            return $hubMode;
        }
        if ($userActive && ! $hubActive) {
            return $userMode;
        }

        // Both active: enforce wins over monitor.
        return ($hubMode === 'enforce' || $userMode === 'enforce') ? 'enforce' : 'monitor';
    }

    /**
     * @param  list<mixed>  $a
     * @param  list<mixed>  $b
     * @return list<string>
     */
    private function mergeLists(array $a, array $b): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn ($v) => trim((string) $v),
            array_merge($a, $b)
        ), fn ($v) => $v !== '')));
    }

    public function resolveRequestUser(Request $request): ?User
    {
        $user = $request->user();
        if ($user instanceof User) {
            return $user;
        }

        $bearer = $request->bearerToken();
        if ($bearer) {
            try {
                $token = PersonalAccessToken::findToken($bearer);
                $tokenable = $token?->tokenable;
                if ($tokenable instanceof User) {
                    return $tokenable;
                }
            } catch (\Throwable) {
                //
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function cachedUserSettings(Hub $hub, User $user): ?array
    {
        $hubId = (int) $hub->id;
        $userId = (int) $user->id;

        try {
            return Cache::remember(
                self::USER_SETTINGS_CACHE_PREFIX.$hubId.':'.$userId,
                60,
                function () use ($hub, $user) {
                    $row = UserAccessRestrictionSetting::query()
                        ->where('hub_id', $hub->id)
                        ->where('user_id', $user->id)
                        ->first();

                    return $row ? $this->normalizeFromUserModel($row) : $this->emptySettings();
                }
            );
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function normalizeFromUserModel(UserAccessRestrictionSetting $row): array
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
            'enforcement_mode' => SecurityMonitoringSetting::normalizedEnforcementMode(
                $row->access_enforcement_mode ?? null
            ),
        ];
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
        $this->recordAccessDecision($request, 'fw.access_restricted', 'Request blocked by access restriction', $reason, false);
    }

    public function recordWouldBlockRequest(Request $request, string $reason = 'access_restriction'): void
    {
        $this->recordAccessDecision(
            $request,
            'fw.access_would_block',
            'Request would be blocked by access restriction (monitor mode)',
            $reason,
            true
        );
    }

    private function recordAccessDecision(
        Request $request,
        string $eventType,
        string $title,
        string $reason,
        bool $monitorOnly
    ): void {
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
                'event_type' => $eventType,
                'severity' => $monitorOnly ? SecurityEvent::SEVERITY_LOW : SecurityEvent::SEVERITY_MEDIUM,
                'title' => $title,
                'description' => sprintf(
                    '%s API request from %s (%s/%s) to %s %s — rule: %s.',
                    $monitorOnly ? 'Would block' : 'Blocked',
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
                    'monitor_only' => $monitorOnly,
                ],
            ]);
        } catch (\Throwable) {
            // Monitoring must not break the request.
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
            'enforcement_mode' => SecurityMonitoringSetting::normalizedEnforcementMode(
                $row->access_enforcement_mode ?? null
            ),
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
            'enforcement_mode' => 'enforce',
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
        // Order matches evaluation precedence (Option B + VPN).
        return [
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
                'label' => 'Block VPN',
                'description' => 'When enabled, block detected VPN/proxy/hosting exits. When disabled, VPN traffic is allowed and follows other rules. Allow IPs can still override. Detection is best-effort.',
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
                'key' => 'uk_cities',
                'label' => 'Specific UK cities',
                'description' => 'Allow requests only from selected UK cities (must also resolve as GB). Allow IPs / Allow countries can override.',
            ],
            [
                'key' => 'uk_only',
                'label' => 'UK only',
                'description' => 'Allow requests only from United Kingdom IP addresses. Allow IPs / Allow countries can override.',
            ],
        ];
    }
}
