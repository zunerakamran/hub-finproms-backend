<?php

namespace App\Support;

use App\Models\Hub;
use App\Services\HubService;

/**
 * Open-redirect hardening: only allow return URLs on the hub frontend origin.
 */
class SafeFrontendRedirect
{
    public static function allowedOrigins(?Hub $hub = null): array
    {
        $origins = [];

        $appFrontend = rtrim((string) config('app.frontend_url', ''), '/');
        if ($appFrontend !== '') {
            $origins[] = $appFrontend;
        }

        $appUrl = rtrim((string) config('app.url', ''), '/');
        if ($appUrl !== '') {
            $origins[] = $appUrl;
        }

        try {
            $hub ??= app(HubService::class)->current();
        } catch (\Throwable) {
            $hub = null;
        }

        if ($hub && filled($hub->frontend_url)) {
            $origins[] = rtrim((string) $hub->frontend_url, '/');
        }

        return array_values(array_unique(array_filter($origins)));
    }

    /**
     * Return a safe same-origin path or absolute URL on an allowlisted origin.
     * Rejects protocol-relative and off-origin absolute URLs.
     */
    public static function sanitize(?string $target, ?Hub $hub = null, string $fallbackPath = '/'): string
    {
        $fallbackPath = self::normalizePath($fallbackPath) ?: '/';

        if ($target === null || trim($target) === '') {
            return $fallbackPath;
        }

        $target = trim($target);

        // Path-only (preferred for in-app redirects).
        if (str_starts_with($target, '/') && ! str_starts_with($target, '//')) {
            return self::normalizePath(parse_url($target, PHP_URL_PATH) ?: '/')
                .(isset(parse_url($target)['query']) ? '?'.parse_url($target, PHP_URL_QUERY) : '');
        }

        if (! filter_var($target, FILTER_VALIDATE_URL)) {
            return $fallbackPath;
        }

        $parts = parse_url($target);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (! in_array($scheme, ['http', 'https'], true)) {
            return $fallbackPath;
        }

        $origin = $scheme.'://'.strtolower((string) ($parts['host'] ?? ''));
        if (! empty($parts['port'])) {
            $origin .= ':'.$parts['port'];
        }

        foreach (self::allowedOrigins($hub) as $allowed) {
            $allowedParts = parse_url($allowed);
            if (! is_array($allowedParts) || empty($allowedParts['host'])) {
                continue;
            }
            $allowedOrigin = strtolower((string) ($allowedParts['scheme'] ?? 'https')).'://'
                .strtolower((string) $allowedParts['host']);
            if (! empty($allowedParts['port'])) {
                $allowedOrigin .= ':'.$allowedParts['port'];
            }
            if ($origin === $allowedOrigin) {
                $path = self::normalizePath($parts['path'] ?? '/') ?: '/';
                $query = isset($parts['query']) ? '?'.$parts['query'] : '';

                return $path.$query;
            }
        }

        return $fallbackPath;
    }

    public static function isAllowlistedFrontendPath(string $pathname): bool
    {
        $path = self::normalizePath($pathname) ?: '/';
        $exact = config('security_navigation.allowlist_exact', []);
        if (in_array($path, $exact, true)) {
            return true;
        }

        foreach (config('security_navigation.allowlist_prefixes', []) as $prefix) {
            $prefix = self::normalizePath((string) $prefix) ?: '/';
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }

    private static function normalizePath(string $path): string
    {
        $path = explode('?', $path, 2)[0];
        $path = explode('#', $path, 2)[0];
        if ($path === '') {
            return '/';
        }
        if (! str_starts_with($path, '/')) {
            $path = '/'.$path;
        }
        if (strlen($path) > 1 && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        return $path;
    }
}
