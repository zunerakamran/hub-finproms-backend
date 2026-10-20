<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Resolve country / city / VPN signals for a client IP.
 *
 * Prefer CDN / proxy headers (Cloudflare) only when the request is from a
 * trusted reverse proxy (or Simulate opts in), then optional HTTP lookup
 * (ip-api), then config test overrides. Results are cached to keep middleware
 * cheap.
 *
 * VPN detection uses ip-api `proxy` (and optionally `hosting`) fields, or the
 * trusted edge header X-App-Geo-Vpn. Detection is best-effort — not all VPNs
 * are labelled, and residential VPNs may look like normal ISPs.
 */
class GeoIpLookupService
{
    /** Request attribute: Simulate / admin tools may force-trust geo headers. */
    public const ATTR_FORCE_TRUST_HEADERS = 'geoip.force_trust_headers';

    /**
     * @return array{country: ?string, city: ?string, vpn: ?bool, source: string}
     */
    public function lookup(?string $ip, ?Request $request = null, bool $needVpn = false): array
    {
        $headers = $this->fromHeaders($request);
        $override = $this->fromConfigOverride($ip);

        $country = $headers['country'] ?? null;
        $city = $headers['city'] ?? null;
        $vpn = $headers['vpn'] ?? null;
        $sources = [];

        if ($country !== null || $city !== null || $vpn !== null) {
            $sources[] = 'headers';
        }

        if ($override !== null) {
            $country ??= $override['country'];
            $city ??= $override['city'];
            $vpn ??= $override['vpn'];
            $sources[] = 'override';
        }

        $haveGeo = $country !== null || $city !== null;
        $haveVpn = $vpn !== null;
        // Prefer headers/overrides when they already satisfy what we need.
        if ($haveGeo && (! $needVpn || $haveVpn)) {
            return [
                'country' => $country,
                'city' => $city,
                'vpn' => $vpn,
                'source' => $sources !== [] ? implode('+', array_unique($sources)) : 'headers',
            ];
        }

        if ($ip === null || $ip === '' || $this->isPrivateOrLocal((string) $ip)) {
            return [
                'country' => $country,
                'city' => $city,
                'vpn' => $vpn ?? false,
                'source' => $sources !== [] ? implode('+', array_unique($sources)) : 'local',
            ];
        }

        if (! (bool) config('security_firewall.geoip.http_enabled', true)) {
            return [
                'country' => $country,
                'city' => $city,
                'vpn' => $vpn,
                'source' => $sources !== [] ? implode('+', array_unique($sources)) : 'disabled',
            ];
        }

        $http = $this->fromHttp($ip);
        $country ??= $http['country'];
        $city ??= $http['city'];
        $vpn ??= $http['vpn'];
        if (($http['source'] ?? '') !== '') {
            $sources[] = $http['source'];
        }

        return [
            'country' => $country,
            'city' => $city,
            'vpn' => $vpn,
            'source' => $sources !== [] ? implode('+', array_unique($sources)) : ($http['source'] ?? 'http'),
        ];
    }

    /**
     * @return array{country: ?string, city: ?string, vpn: ?bool, source: string}
     */
    private function fromHeaders(?Request $request): array
    {
        if (! $request) {
            return ['country' => null, 'city' => null, 'vpn' => null, 'source' => 'none'];
        }

        if (! (bool) config('security_firewall.geoip.trust_headers', true)) {
            return ['country' => null, 'city' => null, 'vpn' => null, 'source' => 'none'];
        }

        if (! $this->shouldTrustGeoHeaders($request)) {
            return ['country' => null, 'city' => null, 'vpn' => null, 'source' => 'untrusted'];
        }

        $country = $request->headers->get('CF-IPCountry')
            ?: $request->headers->get('X-App-Geo-Country');

        $city = $request->headers->get('CF-IPCity')
            ?: $request->headers->get('X-App-Geo-City');

        if ((bool) config('security_firewall.geoip.trust_generic_geo_headers', false)) {
            $country = $country ?: $request->headers->get('X-Country-Code');
            $city = $city ?: $request->headers->get('X-City');
        }

        $vpnHeader = $request->headers->get('X-App-Geo-Vpn');

        $country = $this->normalizeCountry($country);
        $city = $this->normalizeCity($city);
        $vpn = $this->normalizeVpnFlag($vpnHeader);

        if ($country === null && $city === null && $vpn === null) {
            return ['country' => null, 'city' => null, 'vpn' => null, 'source' => 'none'];
        }

        // Cloudflare uses XX for unknown.
        if ($country === 'XX') {
            $country = null;
        }

        return [
            'country' => $country,
            'city' => $city,
            'vpn' => $vpn,
            'source' => 'headers',
        ];
    }

    /**
     * Geo headers are only safe when set by a trusted edge (or Simulate).
     */
    public function shouldTrustGeoHeaders(Request $request): bool
    {
        if ($request->attributes->get(self::ATTR_FORCE_TRUST_HEADERS)) {
            return true;
        }

        if (! (bool) config('security_firewall.geoip.require_trusted_proxy_for_headers', true)) {
            return true;
        }

        // Laravel marks the request when REMOTE_ADDR matched trustProxies().
        if ($request->isFromTrustedProxy()) {
            return true;
        }

        // No trusted proxies configured: never treat client-supplied geo as truth.
        return false;
    }

    /**
     * @return array{country: ?string, city: ?string, vpn: ?bool, source: string}|null
     */
    private function fromConfigOverride(?string $ip): ?array
    {
        if ($ip === null || $ip === '') {
            return null;
        }

        $map = (array) config('security_firewall.geoip.test_overrides', []);
        if (! isset($map[$ip]) || ! is_array($map[$ip])) {
            return null;
        }

        $vpn = array_key_exists('vpn', $map[$ip])
            ? $this->normalizeVpnFlag($map[$ip]['vpn'])
            : null;

        return [
            'country' => $this->normalizeCountry($map[$ip]['country'] ?? null),
            'city' => $this->normalizeCity($map[$ip]['city'] ?? null),
            'vpn' => $vpn,
            'source' => 'override',
        ];
    }

    /**
     * @return array{country: ?string, city: ?string, vpn: ?bool, source: string}
     */
    private function fromHttp(string $ip): array
    {
        $cacheMinutes = max(1, (int) config('security_firewall.geoip.cache_minutes', 1440));
        // v2: cache entries include vpn / proxy signals.
        $cacheKey = 'security_firewall:geoip:v2:'.$ip;

        try {
            return Cache::remember($cacheKey, now()->addMinutes($cacheMinutes), function () use ($ip) {
                $template = (string) config(
                    'security_firewall.geoip.http_url',
                    'http://ip-api.com/json/{ip}?fields=status,countryCode,city,proxy,hosting'
                );
                $url = str_replace('{ip}', urlencode($ip), $template);
                $timeout = max(1, (int) config('security_firewall.geoip.timeout_seconds', 2));

                $response = Http::timeout($timeout)
                    ->acceptJson()
                    ->get($url);

                if (! $response->successful()) {
                    return ['country' => null, 'city' => null, 'vpn' => null, 'source' => 'http_error'];
                }

                $json = $response->json();
                if (! is_array($json)) {
                    return ['country' => null, 'city' => null, 'vpn' => null, 'source' => 'http_error'];
                }

                $status = strtolower((string) ($json['status'] ?? 'success'));
                if ($status !== '' && $status !== 'success') {
                    return ['country' => null, 'city' => null, 'vpn' => null, 'source' => 'http_miss'];
                }

                $country = $this->normalizeCountry(
                    $json['countryCode'] ?? $json['country_code'] ?? $json['country'] ?? null
                );
                $city = $this->normalizeCity($json['city'] ?? null);
                $vpn = $this->vpnFromProviderPayload($json);

                return [
                    'country' => $country,
                    'city' => $city,
                    'vpn' => $vpn,
                    'source' => 'http',
                ];
            });
        } catch (\Throwable $e) {
            Log::debug('GeoIP HTTP lookup failed', ['ip' => $ip, 'error' => $e->getMessage()]);

            return ['country' => null, 'city' => null, 'vpn' => null, 'source' => 'http_error'];
        }
    }

    /**
     * @param  array<string, mixed>  $json
     */
    private function vpnFromProviderPayload(array $json): ?bool
    {
        $hasProxyKey = array_key_exists('proxy', $json)
            || array_key_exists('hosting', $json)
            || array_key_exists('vpn', $json);

        if (! $hasProxyKey) {
            return null;
        }

        $proxy = $this->truthy($json['proxy'] ?? false) || $this->truthy($json['vpn'] ?? false);
        $hosting = $this->truthy($json['hosting'] ?? false);

        if ($proxy) {
            return true;
        }

        if ((bool) config('security_firewall.geoip.treat_hosting_as_vpn', true) && $hosting) {
            return true;
        }

        return false;
    }

    public function normalizeVpnFlag(mixed $value): ?bool
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return ((int) $value) !== 0;
        }

        $raw = strtolower(trim((string) $value));
        if ($raw === '') {
            return null;
        }

        if (in_array($raw, ['1', 'true', 'yes', 'y', 'on', 'vpn', 'proxy'], true)) {
            return true;
        }

        if (in_array($raw, ['0', 'false', 'no', 'n', 'off'], true)) {
            return false;
        }

        return null;
    }

    private function truthy(mixed $value): bool
    {
        return $this->normalizeVpnFlag($value) === true;
    }

    public function normalizeCountry(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $code = strtoupper(trim((string) $value));
        if ($code === '') {
            return null;
        }

        // Non-ISO aliases occasionally returned by providers / CDNs.
        if ($code === 'UK') {
            return 'GB';
        }

        if (strlen($code) !== 2) {
            return null;
        }

        return $code;
    }

    public function normalizeCity(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $city = trim((string) $value);
        if ($city === '') {
            return null;
        }

        return $city;
    }

    public function isPrivateOrLocal(string $ip): bool
    {
        if (in_array($ip, ['127.0.0.1', '::1', '0.0.0.0'], true)) {
            return true;
        }

        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }
}
