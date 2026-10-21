<?php

namespace App\Services;

use App\Models\SecurityEvent;
use App\Support\IpAddressMatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * NFR-SEC-FW — application-layer IP deny list (config + temporary auto-blocks).
 */
class FirewallService
{
    private const CACHE_PREFIX = 'security_firewall:blocked_ip:';

    public function __construct(
        private HubService $hubs,
        private SecurityMonitoringService $securityMonitoring,
        private AccessRestrictionService $accessRestrictions,
    ) {}

    public function isEnabled(): bool
    {
        return (bool) config('security_firewall.enabled', true);
    }

    /**
     * Env deny-list + temporary auto-blocks (IP only, no hub geo rules).
     */
    public function isIpDenied(?string $ip): bool
    {
        if (! $this->isEnabled() || $ip === null || $ip === '') {
            return false;
        }

        foreach ((array) config('security_firewall.denied_ips', []) as $entry) {
            $entry = trim((string) $entry);
            if ($entry === '') {
                continue;
            }
            if ($this->ipMatches($ip, $entry)) {
                return true;
            }
        }

        return Cache::has(self::CACHE_PREFIX.$ip);
    }

    /**
     * Full request gate: env deny-list, temporary blocks, then hub access rules.
     */
    public function isRequestDenied(Request $request): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        if ($this->isIpDenied($request->ip())) {
            return true;
        }

        return $this->accessRestrictions->isRequestDenied($request);
    }

    public function accessRestrictions(): AccessRestrictionService
    {
        return $this->accessRestrictions;
    }

    /**
     * Temporarily block an IP (used after failed-login threshold when enabled).
     */
    public function blockIpTemporarily(string $ip, ?int $minutes = null, ?Request $request = null, string $reason = ''): void
    {
        if (! $this->isEnabled() || $ip === '') {
            return;
        }

        $minutes = max(1, $minutes ?? (int) config('security_firewall.auto_block_minutes', 60));
        Cache::put(self::CACHE_PREFIX.$ip, [
            'blocked_at' => now()->toIso8601String(),
            'reason' => $reason !== '' ? $reason : 'auto_block',
            'minutes' => $minutes,
        ], now()->addMinutes($minutes));

        $this->recordBlockedEvent($ip, $request, $reason !== '' ? $reason : 'Temporary IP block applied ('.$minutes.' minutes).');
    }

    public function recordDeniedRequest(Request $request): void
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
            $this->securityMonitoring->recordEvent($hub, [
                'category' => SecurityEvent::CATEGORY_AUTHORIZATION,
                'event_type' => 'fw.ip_denied',
                'severity' => SecurityEvent::SEVERITY_MEDIUM,
                'title' => 'Request blocked by application firewall',
                'description' => sprintf(
                    'Blocked API request from denied IP %s to %s %s.',
                    $ip,
                    $request->method(),
                    '/'.$request->path()
                ),
                'actor' => $request->user(),
                'request' => $request,
                'properties' => [
                    'source_ip' => $ip,
                    'path' => '/'.$request->path(),
                ],
            ]);
        } catch (\Throwable) {
            // Monitoring must not break the 403 response.
        }
    }

    private function recordBlockedEvent(string $ip, ?Request $request, string $description): void
    {
        if (! config('security_firewall.record_security_events', true)) {
            return;
        }

        try {
            $hub = $this->hubs->current();
            if (! $hub || ! $hub->hasSecurityMonitoringFunctionality()) {
                return;
            }

            $this->securityMonitoring->recordEvent($hub, [
                'category' => SecurityEvent::CATEGORY_AUTH,
                'event_type' => 'fw.ip_auto_blocked',
                'severity' => SecurityEvent::SEVERITY_HIGH,
                'title' => 'IP temporarily blocked by application firewall',
                'description' => $description.' IP: '.$ip,
                'request' => $request,
                'properties' => ['source_ip' => $ip],
            ]);
        } catch (\Throwable) {
            // ignore
        }
    }

    private function ipMatches(string $ip, string $entry): bool
    {
        return IpAddressMatcher::matches($ip, $entry);
    }
}
