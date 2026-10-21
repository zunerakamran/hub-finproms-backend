<?php

namespace App\Support;

/**
 * SSRF hardening helpers for server-side HTTP fetches.
 */
class SafeOutboundUrl
{
    /**
     * True when the host is loopback, link-local, private, or otherwise blocked.
     *
     * @param  bool  $resolveDns  When true, also block hostnames that resolve to private IPs.
     */
    public static function isBlockedHost(string $host, bool $resolveDns = true): bool
    {
        $host = strtolower(trim($host));
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
            return true;
        }

        // Cloud metadata / link-local hostnames.
        if ($host === 'metadata.google.internal' || $host === 'metadata') {
            return true;
        }

        // IPv6 literals in brackets or bare.
        $ip = $host;
        if (str_starts_with($ip, '[') && str_ends_with($ip, ']')) {
            $ip = substr($ip, 1, -1);
        }

        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return ! filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            );
        }

        if (! $resolveDns) {
            return false;
        }

        // Resolve hostname — block if any A/AAAA is private/reserved.
        $records = @dns_get_record($host, DNS_A + DNS_AAAA);
        if (! is_array($records) || $records === []) {
            // Unresolvable: fail closed for untrusted outbound fetches.
            return true;
        }

        foreach ($records as $record) {
            $addr = $record['ip'] ?? $record['ipv6'] ?? null;
            if (! is_string($addr) || $addr === '') {
                continue;
            }
            if (! filter_var(
                $addr,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            )) {
                return true;
            }
        }

        return false;
    }

    /**
     * Parse and validate an http(s) URL; return parts or null.
     *
     * @return array{scheme: string, host: string, port: ?int, path: string, url: string}|null
     */
    public static function parseHttpUrl(string $url): ?array
    {
        $url = trim($url);
        if ($url === '' || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }

        $scheme = strtolower((string) $parts['scheme']);
        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        return [
            'scheme' => $scheme,
            'host' => strtolower((string) $parts['host']),
            'port' => isset($parts['port']) ? (int) $parts['port'] : null,
            'path' => (string) ($parts['path'] ?? '/'),
            'url' => $url,
        ];
    }

    /**
     * True when $url is http(s) and its host is not private / loopback / metadata.
     */
    public static function isSafeOutboundUrl(string $url, bool $resolveDns = true): bool
    {
        $parsed = self::parseHttpUrl($url);
        if ($parsed === null) {
            return false;
        }

        return ! self::isBlockedHost($parsed['host'], $resolveDns);
    }

    /**
     * Normalize a bare host or URL to absolute http(s), then reject unsafe hosts.
     *
     * @return array{ok: true, url: string}|array{ok: false, message: string}
     */
    public static function validateAdvisorSiteUrl(string $domain, bool $resolveDns = true): array
    {
        $domain = trim($domain);
        if ($domain === '') {
            return [
                'ok' => false,
                'message' => 'Advisor site URL is required.',
            ];
        }

        $domain = rtrim($domain, '/');
        if (! preg_match('#^https?://#i', $domain)) {
            $domain = 'https://'.$domain;
        }

        $parsed = self::parseHttpUrl($domain);
        if ($parsed === null) {
            return [
                'ok' => false,
                'message' => 'Advisor site URL must be a valid http(s) URL.',
            ];
        }

        if (self::isBlockedHost($parsed['host'], $resolveDns)) {
            return [
                'ok' => false,
                'message' => 'Advisor site URL must not target private, loopback, link-local, or metadata hosts.',
            ];
        }

        return [
            'ok' => true,
            'url' => $domain,
        ];
    }

    /**
     * Whether $url's host matches any allowlisted base URL's host.
     *
     * @param  list<string>  $allowedBaseUrls
     */
    public static function hostMatchesAllowlist(string $url, array $allowedBaseUrls): bool
    {
        $parsed = self::parseHttpUrl($url);
        if ($parsed === null) {
            return false;
        }

        foreach ($allowedBaseUrls as $base) {
            $base = trim((string) $base);
            if ($base === '') {
                continue;
            }
            $baseParts = self::parseHttpUrl($base)
                ?? self::parseHttpUrl(rtrim($base, '/').'/');
            if ($baseParts === null) {
                // Bare hostname in allowlist.
                if (strcasecmp($base, $parsed['host']) === 0) {
                    return true;
                }

                continue;
            }
            if (strcasecmp($baseParts['host'], $parsed['host']) === 0) {
                return true;
            }
        }

        return false;
    }
}
