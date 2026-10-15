<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Resolve country / city for a client IP.
 *
 * Prefer CDN / proxy headers (Cloudflare), then optional HTTP lookup (ip-api),
 * then config test overrides. Results are cached to keep middleware cheap.
 */
class GeoIpLookupService
{
    /**
     * @return array{country: ?string, city: ?string, source: string}
     */
    public function lookup(?string $ip, ?Request $request = null): array
    {
        $fromHeaders = $this->fromHeaders($request);
        if ($fromHeaders['country'] !== null || $fromHeaders['city'] !== null) {
            return $fromHeaders;
        }

        $override = $this->fromConfigOverride($ip);
        if ($override !== null) {
            return $override;
        }

        if ($ip === null || $ip === '' || $this->isPrivateOrLocal($ip)) {
            return ['country' => null, 'city' => null, 'source' => 'local'];
        }

        if (! (bool) config('security_firewall.geoip.http_enabled', true)) {
            return ['country' => null, 'city' => null, 'source' => 'disabled'];
        }

        return $this->fromHttp($ip);
    }

    /**
     * @return array{country: ?string, city: ?string, source: string}
     */
    private function fromHeaders(?Request $request): array
    {
        if (! $request) {
            return ['country' => null, 'city' => null, 'source' => 'none'];
        }

        $country = $request->headers->get('CF-IPCountry')
            ?: $request->headers->get('X-App-Geo-Country')
            ?: $request->headers->get('X-Country-Code');

        $city = $request->headers->get('CF-IPCity')
            ?: $request->headers->get('X-App-Geo-City')
            ?: $request->headers->get('X-City');

        $country = $this->normalizeCountry($country);
        $city = $this->normalizeCity($city);

        if ($country === null && $city === null) {
            return ['country' => null, 'city' => null, 'source' => 'none'];
        }

        // Cloudflare uses XX for unknown.
        if ($country === 'XX') {
            $country = null;
        }

        return [
            'country' => $country,
            'city' => $city,
            'source' => 'headers',
        ];
    }

    /**
     * @return array{country: ?string, city: ?string, source: string}|null
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

        return [
            'country' => $this->normalizeCountry($map[$ip]['country'] ?? null),
            'city' => $this->normalizeCity($map[$ip]['city'] ?? null),
            'source' => 'override',
        ];
    }

    /**
     * @return array{country: ?string, city: ?string, source: string}
     */
    private function fromHttp(string $ip): array
    {
        $cacheMinutes = max(1, (int) config('security_firewall.geoip.cache_minutes', 1440));
        $cacheKey = 'security_firewall:geoip:'.$ip;

        try {
            return Cache::remember($cacheKey, now()->addMinutes($cacheMinutes), function () use ($ip) {
                $template = (string) config(
                    'security_firewall.geoip.http_url',
                    'http://ip-api.com/json/{ip}?fields=status,countryCode,city'
                );
                $url = str_replace('{ip}', urlencode($ip), $template);
                $timeout = max(1, (int) config('security_firewall.geoip.timeout_seconds', 2));

                $response = Http::timeout($timeout)
                    ->acceptJson()
                    ->get($url);

                if (! $response->successful()) {
                    return ['country' => null, 'city' => null, 'source' => 'http_error'];
                }

                $json = $response->json();
                if (! is_array($json)) {
                    return ['country' => null, 'city' => null, 'source' => 'http_error'];
                }

                $status = strtolower((string) ($json['status'] ?? 'success'));
                if ($status !== '' && $status !== 'success') {
                    return ['country' => null, 'city' => null, 'source' => 'http_miss'];
                }

                $country = $this->normalizeCountry(
                    $json['countryCode'] ?? $json['country_code'] ?? $json['country'] ?? null
                );
                $city = $this->normalizeCity($json['city'] ?? null);

                return [
                    'country' => $country,
                    'city' => $city,
                    'source' => 'http',
                ];
            });
        } catch (\Throwable $e) {
            Log::debug('GeoIP HTTP lookup failed', ['ip' => $ip, 'error' => $e->getMessage()]);

            return ['country' => null, 'city' => null, 'source' => 'http_error'];
        }
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
