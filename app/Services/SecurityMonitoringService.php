<?php

namespace App\Services;

use App\Models\Hub;
use App\Models\SecurityEvent;
use App\Models\SecurityIncident;
use App\Models\SecurityIncidentNote;
use App\Models\SecurityMonitoringSetting;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SecurityMonitoringService
{
    public function __construct(
        private readonly CapabilitiesMatrixService $matrix,
        private readonly ActivityLogService $activityLogs,
        private readonly HubService $hubs
    ) {}

    public function assertModuleEnabled(Hub $hub): void
    {
        if (! $hub->hasSecurityMonitoringFunctionality()) {
            throw ValidationException::withMessages([
                'security_monitoring' => 'Security Monitoring is not enabled for this hub.',
            ]);
        }
    }

    public function settingsFor(Hub $hub): SecurityMonitoringSetting
    {
        $existing = SecurityMonitoringSetting::query()
            ->where('hub_id', $hub->id)
            ->first();

        if ($existing) {
            return $existing;
        }

        return SecurityMonitoringSetting::query()->create([
            'hub_id' => $hub->id,
            'alert_min_severity' => SecurityEvent::SEVERITY_HIGH,
            'alert_email_enabled' => false,
            'alert_emails' => [],
            'failed_login_threshold' => 5,
            'failed_login_window_minutes' => 15,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateSettings(Hub $hub, User $actor, array $data, ?Request $request = null): SecurityMonitoringSetting
    {
        $this->assertModuleEnabled($hub);
        $this->assertCan($hub, $actor, 'secmon_manage_alerts');

        $settings = $this->settingsFor($hub);
        $settings->fill([
            'alert_min_severity' => $data['alert_min_severity'] ?? $settings->alert_min_severity,
            'alert_email_enabled' => array_key_exists('alert_email_enabled', $data)
                ? (bool) $data['alert_email_enabled']
                : $settings->alert_email_enabled,
            'alert_emails' => array_key_exists('alert_emails', $data)
                ? array_values(array_filter((array) $data['alert_emails']))
                : $settings->alert_emails,
            'failed_login_threshold' => (int) ($data['failed_login_threshold'] ?? $settings->failed_login_threshold),
            'failed_login_window_minutes' => (int) ($data['failed_login_window_minutes'] ?? $settings->failed_login_window_minutes),
        ]);
        $settings->save();

        try {
            $this->activityLogs->log([
                'action' => 'secmon.alert_settings_update',
                'description' => 'Updated security monitoring alert settings',
                'user' => $actor,
                'request' => $request,
                'status_code' => 200,
                'properties' => $settings->toApiArray(),
            ]);
        } catch (\Throwable) {
            //
        }

        return $settings;
    }

    /**
     * Record a security event. Safe to call even when the functionality is off
     * so history is available when monitoring is later enabled.
     *
     * @param  array{
     *   category: string,
     *   event_type: string,
     *   severity?: string,
     *   title: string,
     *   description: string,
     *   status?: string,
     *   occurred_at?: \DateTimeInterface|string|null,
     *   actor?: ?User,
     *   actor_name?: ?string,
     *   actor_email?: ?string,
     *   actor_role?: ?string,
     *   source_ip?: ?string,
     *   user_agent?: ?string,
     *   route?: ?string,
     *   http_action?: ?string,
     *   related_refs?: array<string, mixed>,
     *   properties?: array<string, mixed>,
     *   create_incident?: bool,
     *   request?: ?Request
     * }  $payload
     */
    public function recordEvent(?Hub $hub, array $payload): ?SecurityEvent
    {
        try {
            if (! $hub) {
                $hub = $this->hubs->current();
            }
        } catch (\Throwable) {
            $hub = null;
        }

        $request = $payload['request'] ?? null;
        $actor = $payload['actor'] ?? null;
        $now = now();

        $event = SecurityEvent::query()->create([
            'hub_id' => $hub?->id,
            'occurred_at' => $payload['occurred_at'] ?? $now,
            'detected_at' => $now,
            'category' => $payload['category'],
            'event_type' => $payload['event_type'],
            'severity' => $payload['severity'] ?? SecurityEvent::SEVERITY_INFO,
            'title' => mb_substr((string) $payload['title'], 0, 255),
            'description' => (string) $payload['description'],
            'status' => $payload['status'] ?? SecurityEvent::STATUS_OPEN,
            'actor_user_id' => $actor?->id,
            'actor_name' => $payload['actor_name'] ?? $actor?->name,
            'actor_email' => $payload['actor_email'] ?? $actor?->email,
            'actor_role' => $payload['actor_role'] ?? $actor?->role,
            'source_ip' => $payload['source_ip'] ?? ($request instanceof Request ? $request->ip() : null),
            'user_agent' => $this->truncateUa($payload['user_agent'] ?? ($request instanceof Request ? $request->userAgent() : null)),
            'route' => $payload['route'] ?? ($request instanceof Request ? '/'.ltrim($request->path(), '/') : null),
            'http_action' => $payload['http_action'] ?? ($request instanceof Request ? $request->method() : null),
            'related_refs' => $payload['related_refs'] ?? [],
            'properties' => $payload['properties'] ?? [],
        ]);

        if (! empty($payload['create_incident']) && $hub) {
            $incident = $this->createIncidentFromEvent($hub, $event);
            $event->security_incident_id = $incident->id;
            $event->save();
        }

        return $event;
    }

    public function recordFailedLogin(Hub $hub, string $email, ?Request $request = null): void
    {
        try {
            $this->recordEvent($hub, [
                'category' => SecurityEvent::CATEGORY_AUTH,
                'event_type' => 'auth.login_failed',
                'severity' => SecurityEvent::SEVERITY_LOW,
                'title' => 'Failed login attempt',
                'description' => 'Failed login attempt for '.$email
                    .' at '.now()->toIso8601String().'.',
                'actor_email' => $email,
                'request' => $request,
                'properties' => ['email' => $email],
            ]);

            $this->maybeEscalateFailedLogins($hub, $email, $request);
        } catch (\Throwable) {
            // Never block auth on security monitoring.
        }
    }

    public function recordBlockedLogin(Hub $hub, User $user, string $reason, ?Request $request = null): void
    {
        try {
            $this->recordEvent($hub, [
                'category' => SecurityEvent::CATEGORY_AUTH,
                'event_type' => 'auth.login_blocked',
                'severity' => SecurityEvent::SEVERITY_MEDIUM,
                'title' => 'Login blocked ('.$reason.')',
                'description' => 'Login blocked for '.$user->email.' — reason: '.$reason
                    .' at '.now()->toIso8601String().'.',
                'actor' => $user,
                'request' => $request,
                'properties' => ['reason' => $reason],
            ]);
        } catch (\Throwable) {
            //
        }
    }

    public function recordForceLogout(Hub $hub, User $actor, User $target, array $meta = [], ?Request $request = null): void
    {
        try {
            $this->recordEvent($hub, [
                'category' => SecurityEvent::CATEGORY_AUTH,
                'event_type' => 'auth.force_logout',
                'severity' => SecurityEvent::SEVERITY_MEDIUM,
                'title' => 'Force logout',
                'description' => $actor->email.' force-logged out '.$target->email
                    .' at '.now()->toIso8601String().'.',
                'actor' => $actor,
                'request' => $request,
                'related_refs' => ['target_user_id' => $target->id],
                'properties' => $meta,
            ]);
        } catch (\Throwable) {
            //
        }
    }

    public function recordRateLimitHit(?Hub $hub, string $limiter, ?Request $request = null): void
    {
        try {
            $this->recordEvent($hub, [
                'category' => SecurityEvent::CATEGORY_AUTHORIZATION,
                'event_type' => 'api.rate_limited',
                'severity' => SecurityEvent::SEVERITY_MEDIUM,
                'title' => 'Rate limit exceeded ('.$limiter.')',
                'description' => 'Request rate limit "'.$limiter.'" was exceeded'
                    .' at '.now()->toIso8601String().'.',
                'request' => $request,
                'properties' => ['limiter' => $limiter],
            ]);
        } catch (\Throwable) {
            //
        }
    }

    /**
     * @param  array{
     *   severity?: string|null,
     *   category?: string|null,
     *   status?: string|null,
     *   event_type?: string|null,
     *   q?: string|null,
     *   from?: string|null,
     *   to?: string|null,
     *   source_ip?: string|null,
     *   actor_user_id?: int|null,
     *   per_page?: int|null
     * }  $filters
     */
    public function listEvents(Hub $hub, User $actor, array $filters): LengthAwarePaginator
    {
        $this->assertModuleEnabled($hub);
        $this->assertCan($hub, $actor, 'secmon_view_events');

        $query = SecurityEvent::query()
            ->where(function ($q) use ($hub) {
                $q->where('hub_id', $hub->id)->orWhereNull('hub_id');
            })
            ->orderByDesc('occurred_at')
            ->orderByDesc('id');

        $this->applyEventFilters($query, $filters);

        return $query->paginate(max(1, min(100, (int) ($filters['per_page'] ?? 25))));
    }

    public function showEvent(Hub $hub, User $actor, SecurityEvent $event): SecurityEvent
    {
        $this->assertModuleEnabled($hub);
        $this->assertCan($hub, $actor, 'secmon_view_events');
        $this->assertEventOnHub($hub, $event);

        return $event;
    }

    /**
     * @param  array{
     *   severity?: string|null,
     *   category?: string|null,
     *   status?: string|null,
     *   q?: string|null,
     *   from?: string|null,
     *   to?: string|null,
     *   per_page?: int|null
     * }  $filters
     */
    public function listIncidents(Hub $hub, User $actor, array $filters): LengthAwarePaginator
    {
        $this->assertModuleEnabled($hub);
        $this->assertCan($hub, $actor, 'secmon_view_events');

        $query = SecurityIncident::query()
            ->withCount('events')
            ->where(function ($q) use ($hub) {
                $q->where('hub_id', $hub->id)->orWhereNull('hub_id');
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if (! empty($filters['severity'])) {
            $query->where('severity', $filters['severity']);
        }
        if (! empty($filters['category'])) {
            $query->where('category', $filters['category']);
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['q'])) {
            $q = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $filters['q']).'%';
            $query->where(function ($inner) use ($q) {
                $inner->where('title', 'like', $q)
                    ->orWhere('description', 'like', $q)
                    ->orWhere('resolution_summary', 'like', $q);
            });
        }
        if (! empty($filters['from'])) {
            $query->where('created_at', '>=', Carbon::parse($filters['from'])->startOfDay());
        }
        if (! empty($filters['to'])) {
            $query->where('created_at', '<=', Carbon::parse($filters['to'])->endOfDay());
        }

        return $query->paginate(max(1, min(100, (int) ($filters['per_page'] ?? 25))));
    }

    public function showIncident(Hub $hub, User $actor, SecurityIncident $incident): SecurityIncident
    {
        $this->assertModuleEnabled($hub);
        $this->assertCan($hub, $actor, 'secmon_view_events');
        $this->assertIncidentOnHub($hub, $incident);

        return $incident->load(['events' => fn ($q) => $q->orderByDesc('occurred_at'), 'notes']);
    }

    /**
     * @param  array{
     *   title: string,
     *   description?: string|null,
     *   category?: string,
     *   severity?: string,
     *   event_ids?: list<int>
     * }  $data
     */
    public function declareIncident(Hub $hub, User $actor, array $data, ?Request $request = null): SecurityIncident
    {
        $this->assertModuleEnabled($hub);
        $this->assertCan($hub, $actor, 'secmon_manage_incidents');

        $incident = SecurityIncident::query()->create([
            'hub_id' => $hub->id,
            'title' => mb_substr($data['title'], 0, 255),
            'description' => $data['description'] ?? null,
            'category' => $data['category'] ?? SecurityEvent::CATEGORY_MANUAL,
            'severity' => $data['severity'] ?? SecurityEvent::SEVERITY_MEDIUM,
            'status' => SecurityIncident::STATUS_OPEN,
            'declared_by_user_id' => $actor->id,
            'declared_by_name' => $actor->name,
        ]);

        $eventIds = array_values(array_filter(array_map('intval', $data['event_ids'] ?? [])));
        if ($eventIds !== []) {
            SecurityEvent::query()
                ->whereIn('id', $eventIds)
                ->where(function ($q) use ($hub) {
                    $q->where('hub_id', $hub->id)->orWhereNull('hub_id');
                })
                ->update(['security_incident_id' => $incident->id]);
        }

        $this->recordEvent($hub, [
            'category' => SecurityEvent::CATEGORY_MANUAL,
            'event_type' => 'secmon.incident_declared',
            'severity' => $incident->severity,
            'title' => 'Security incident declared',
            'description' => 'Manual security incident declared: '.$incident->title,
            'actor' => $actor,
            'request' => $request,
            'related_refs' => ['security_incident_id' => $incident->id],
            'status' => SecurityEvent::STATUS_ACKNOWLEDGED,
        ]);

        try {
            $this->activityLogs->log([
                'action' => 'secmon.incident_declare',
                'description' => 'Declared security incident #'.$incident->id.': '.$incident->title,
                'user' => $actor,
                'request' => $request,
                'status_code' => 201,
                'properties' => ['incident_id' => $incident->id],
            ]);
        } catch (\Throwable) {
            //
        }

        return $incident->load(['events', 'notes'])->loadCount('events');
    }

    /**
     * @param  array{
     *   status?: string,
     *   severity?: string,
     *   resolution_summary?: string|null,
     *   note?: string|null,
     *   event_ids?: list<int>
     * }  $data
     */
    public function updateIncident(Hub $hub, User $actor, SecurityIncident $incident, array $data, ?Request $request = null): SecurityIncident
    {
        $this->assertModuleEnabled($hub);
        $this->assertCan($hub, $actor, 'secmon_manage_incidents');
        $this->assertIncidentOnHub($hub, $incident);

        if (! empty($data['status'])) {
            $incident->status = $data['status'];
            if (in_array($data['status'], [
                SecurityIncident::STATUS_RESOLVED,
                SecurityIncident::STATUS_CLOSED,
                SecurityIncident::STATUS_FALSE_POSITIVE,
            ], true)) {
                $incident->resolved_at = now();
                $incident->resolved_by_user_id = $actor->id;
                $incident->resolved_by_name = $actor->name;
            }
        }

        if (! empty($data['severity'])) {
            $incident->severity = $data['severity'];
        }

        if (array_key_exists('resolution_summary', $data)) {
            $incident->resolution_summary = $data['resolution_summary'];
        }

        $incident->save();

        if (! empty($data['note'])) {
            SecurityIncidentNote::query()->create([
                'security_incident_id' => $incident->id,
                'user_id' => $actor->id,
                'author_name' => $actor->name,
                'body' => $data['note'],
            ]);
        }

        $eventIds = array_values(array_filter(array_map('intval', $data['event_ids'] ?? [])));
        if ($eventIds !== []) {
            SecurityEvent::query()
                ->whereIn('id', $eventIds)
                ->where(function ($q) use ($hub) {
                    $q->where('hub_id', $hub->id)->orWhereNull('hub_id');
                })
                ->update(['security_incident_id' => $incident->id]);
        }

        try {
            $this->activityLogs->log([
                'action' => 'secmon.incident_update',
                'description' => 'Updated security incident #'.$incident->id.' (status='.$incident->status.')',
                'user' => $actor,
                'request' => $request,
                'status_code' => 200,
                'properties' => [
                    'incident_id' => $incident->id,
                    'status' => $incident->status,
                ],
            ]);
        } catch (\Throwable) {
            //
        }

        return $incident->fresh()->load(['events' => fn ($q) => $q->orderByDesc('occurred_at'), 'notes'])->loadCount('events');
    }

    /**
     * @param  array{
     *   from?: string|null,
     *   to?: string|null
     * }  $filters
     * @return array<string, mixed>
     */
    public function report(Hub $hub, User $actor, array $filters = []): array
    {
        $this->assertModuleEnabled($hub);
        $this->assertCan($hub, $actor, 'secmon_view_report');

        $base = SecurityEvent::query()->where(function ($q) use ($hub) {
            $q->where('hub_id', $hub->id)->orWhereNull('hub_id');
        });

        if (! empty($filters['from'])) {
            $base->where('occurred_at', '>=', Carbon::parse($filters['from'])->startOfDay());
        }
        if (! empty($filters['to'])) {
            $base->where('occurred_at', '<=', Carbon::parse($filters['to'])->endOfDay());
        }

        $bySeverity = (clone $base)
            ->select('severity', DB::raw('count(*) as total'))
            ->groupBy('severity')
            ->pluck('total', 'severity')
            ->all();

        $byCategory = (clone $base)
            ->select('category', DB::raw('count(*) as total'))
            ->groupBy('category')
            ->pluck('total', 'category')
            ->all();

        $byStatus = (clone $base)
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        $byDay = (clone $base)
            ->select(DB::raw('DATE(occurred_at) as day'), DB::raw('count(*) as total'))
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->map(fn ($row) => ['day' => $row->day, 'total' => (int) $row->total])
            ->all();

        $topIps = (clone $base)
            ->whereNotNull('source_ip')
            ->select('source_ip', DB::raw('count(*) as total'))
            ->groupBy('source_ip')
            ->orderByDesc('total')
            ->limit(10)
            ->get()
            ->map(fn ($row) => ['source_ip' => $row->source_ip, 'total' => (int) $row->total])
            ->all();

        $topActors = (clone $base)
            ->whereNotNull('actor_email')
            ->select('actor_email', 'actor_name', DB::raw('count(*) as total'))
            ->groupBy('actor_email', 'actor_name')
            ->orderByDesc('total')
            ->limit(10)
            ->get()
            ->map(fn ($row) => [
                'email' => $row->actor_email,
                'name' => $row->actor_name,
                'total' => (int) $row->total,
            ])
            ->all();

        $openHighCritical = SecurityIncident::query()
            ->where(function ($q) use ($hub) {
                $q->where('hub_id', $hub->id)->orWhereNull('hub_id');
            })
            ->whereIn('severity', [SecurityEvent::SEVERITY_HIGH, SecurityEvent::SEVERITY_CRITICAL])
            ->whereIn('status', [
                SecurityIncident::STATUS_OPEN,
                SecurityIncident::STATUS_ACKNOWLEDGED,
                SecurityIncident::STATUS_INVESTIGATING,
            ])
            ->count();

        $settings = $this->settingsFor($hub);

        return [
            'totals' => [
                'events' => (clone $base)->count(),
                'open_high_critical_incidents' => $openHighCritical,
            ],
            'by_severity' => $bySeverity,
            'by_category' => $byCategory,
            'by_status' => $byStatus,
            'by_day' => $byDay,
            'top_source_ips' => $topIps,
            'top_actors' => $topActors,
            'dependency_scan' => [
                'last_at' => optional($settings->last_dependency_scan_at)?->toIso8601String(),
                'status' => $settings->last_dependency_scan_status ?? 'unknown',
                'summary' => $settings->last_dependency_scan_summary,
                'overdue' => $this->scanIsOverdue($settings),
            ],
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * On-demand monitor/scan for capable users (no daily schedule).
     *
     * @return array{status: string, summary: string, advisories: int, scanned_at: string}
     */
    public function scanNow(Hub $hub, User $actor, ?Request $request = null): array
    {
        $this->assertModuleEnabled($hub);

        if (! $this->matrix->userCan($hub, $actor, 'secmon_view_report')
            && ! $this->matrix->userCan($hub, $actor, 'secmon_manage_alerts')
        ) {
            abort(403, 'You do not have permission to run Security Monitoring now.');
        }

        $result = $this->runDependencyScan($hub);

        try {
            $this->activityLogs->log([
                'action' => 'secmon.scan_now',
                'description' => 'Ran on-demand security monitoring scan (status='.$result['status'].')',
                'user' => $actor,
                'request' => $request,
                'status_code' => 200,
                'properties' => $result,
            ]);
        } catch (\Throwable) {
            //
        }

        return array_merge($result, [
            'scanned_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * @return array{status: string, summary: string, advisories: int}
     */
    public function runDependencyScan(?Hub $hub = null): array
    {
        try {
            if (! $hub) {
                $hub = $this->hubs->current();
            }
        } catch (\Throwable) {
            $hub = Hub::query()->orderBy('id')->first();
        }

        $result = $this->executeComposerAudit();
        $settings = $hub ? $this->settingsFor($hub) : null;

        if ($settings) {
            $settings->last_dependency_scan_at = now();
            $settings->last_dependency_scan_status = $result['status'];
            $settings->last_dependency_scan_summary = $result['summary'];
            $settings->save();
        }

        if ($hub && in_array($result['status'], ['fail', 'error', 'overdue'], true)) {
            $this->recordEvent($hub, [
                'category' => SecurityEvent::CATEGORY_DEPENDENCY,
                'event_type' => 'dependency.scan_'.$result['status'],
                'severity' => $result['status'] === 'fail'
                    ? SecurityEvent::SEVERITY_HIGH
                    : SecurityEvent::SEVERITY_MEDIUM,
                'title' => 'Dependency security scan: '.$result['status'],
                'description' => $result['summary'],
                'properties' => ['advisories' => $result['advisories']],
                'create_incident' => $result['status'] === 'fail' && $result['advisories'] > 0,
            ]);
        }

        return $result;
    }

    /**
     * @return array{categories: list<array{key: string, label: string}>, severities: list<string>, statuses: list<string>, event_statuses: list<string>}
     */
    public function options(): array
    {
        return [
            'categories' => collect(SecurityEvent::CATEGORY_LABELS)
                ->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label])
                ->values()
                ->all(),
            'severities' => SecurityEvent::SEVERITIES,
            'statuses' => SecurityIncident::STATUSES,
            'event_statuses' => SecurityEvent::STATUSES,
        ];
    }

    private function maybeEscalateFailedLogins(Hub $hub, string $email, ?Request $request): void
    {
        $settings = $this->settingsFor($hub);
        $window = max(1, (int) $settings->failed_login_window_minutes);
        $threshold = max(2, (int) $settings->failed_login_threshold);
        $ip = $request?->ip();

        $since = now()->subMinutes($window);
        $countQuery = SecurityEvent::query()
            ->where('hub_id', $hub->id)
            ->where('event_type', 'auth.login_failed')
            ->where('occurred_at', '>=', $since);

        if ($ip) {
            $countQuery->where('source_ip', $ip);
        } else {
            $countQuery->where('actor_email', $email);
        }

        $count = $countQuery->count();
        if ($count < $threshold) {
            return;
        }

        // Avoid spam: only escalate once per window per IP/email.
        $already = SecurityEvent::query()
            ->where('hub_id', $hub->id)
            ->where('event_type', 'auth.failed_login_threshold')
            ->where('occurred_at', '>=', $since)
            ->when($ip, fn ($q) => $q->where('source_ip', $ip), fn ($q) => $q->where('actor_email', $email))
            ->exists();

        if ($already) {
            return;
        }

        $this->recordEvent($hub, [
            'category' => SecurityEvent::CATEGORY_AUTH,
            'event_type' => 'auth.failed_login_threshold',
            'severity' => SecurityEvent::SEVERITY_HIGH,
            'title' => 'Repeated failed logins threshold crossed',
            'description' => sprintf(
                'At %s, %d failed login attempts were detected within %d minutes%s. Possible brute-force activity.',
                now()->toIso8601String(),
                $count,
                $window,
                $ip ? ' from IP '.$ip : ' for '.$email
            ),
            'actor_email' => $email,
            'request' => $request,
            'properties' => [
                'count' => $count,
                'threshold' => $threshold,
                'window_minutes' => $window,
            ],
            'create_incident' => true,
        ]);
    }

    private function createIncidentFromEvent(Hub $hub, SecurityEvent $event): SecurityIncident
    {
        return SecurityIncident::query()->create([
            'hub_id' => $hub->id,
            'title' => $event->title,
            'description' => $event->description,
            'category' => $event->category,
            'severity' => $event->severity,
            'status' => SecurityIncident::STATUS_OPEN,
            'declared_by_name' => 'System',
        ]);
    }

    /**
     * @return array{status: string, summary: string, advisories: int}
     */
    private function executeComposerAudit(): array
    {
        $composer = base_path('composer.phar');
        $cmd = is_file($composer)
            ? 'php '.escapeshellarg($composer).' audit --format=json --no-dev 2>&1'
            : 'composer audit --format=json --no-dev 2>&1';

        $output = [];
        $exit = 0;
        @exec($cmd, $output, $exit);
        $raw = implode("\n", $output);

        $jsonStart = strpos($raw, '{');
        $decoded = null;
        if ($jsonStart !== false) {
            $decoded = json_decode(substr($raw, $jsonStart), true);
        }

        if (! is_array($decoded)) {
            // Treat missing composer / empty as unknown rather than hard fail in local/dev.
            if ($exit === 0 || $raw === '') {
                return [
                    'status' => 'pass',
                    'summary' => 'Composer audit completed with no reported advisories (or audit unavailable in this environment).',
                    'advisories' => 0,
                ];
            }

            return [
                'status' => 'error',
                'summary' => 'Composer audit could not be parsed: '.mb_substr($raw, 0, 500),
                'advisories' => 0,
            ];
        }

        $advisories = 0;
        if (isset($decoded['advisories']) && is_array($decoded['advisories'])) {
            foreach ($decoded['advisories'] as $pkgAdvisories) {
                $advisories += is_array($pkgAdvisories) ? count($pkgAdvisories) : 1;
            }
        } elseif (isset($decoded['abandoned'])) {
            // Some composer versions nest differently; count top-level advisory keys.
            $advisories = isset($decoded['advisories']) ? count((array) $decoded['advisories']) : 0;
        }

        if ($exit !== 0 || $advisories > 0) {
            return [
                'status' => 'fail',
                'summary' => $advisories > 0
                    ? "Composer audit reported {$advisories} advisory(ies). Review and patch accordingly."
                    : 'Composer audit exited with failures. Review output and patch accordingly.',
                'advisories' => $advisories,
            ];
        }

        return [
            'status' => 'pass',
            'summary' => 'Composer audit passed with no known advisories.',
            'advisories' => 0,
        ];
    }

    private function scanIsOverdue(SecurityMonitoringSetting $settings): bool
    {
        if (! $settings->last_dependency_scan_at) {
            return true;
        }

        return $settings->last_dependency_scan_at->lt(now()->subDays(7));
    }

    private function assertCan(Hub $hub, User $actor, string $capability): void
    {
        if (! $this->matrix->userCan($hub, $actor, $capability)) {
            abort(403, 'You do not have permission for this Security Monitoring action.');
        }
    }

    private function assertEventOnHub(Hub $hub, SecurityEvent $event): void
    {
        if ($event->hub_id !== null && (int) $event->hub_id !== (int) $hub->id) {
            abort(404);
        }
    }

    private function assertIncidentOnHub(Hub $hub, SecurityIncident $incident): void
    {
        if ($incident->hub_id !== null && (int) $incident->hub_id !== (int) $hub->id) {
            abort(404);
        }
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\SecurityEvent>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyEventFilters($query, array $filters): void
    {
        if (! empty($filters['severity'])) {
            $query->where('severity', $filters['severity']);
        }
        if (! empty($filters['category'])) {
            $query->where('category', $filters['category']);
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['event_type'])) {
            $query->where('event_type', $filters['event_type']);
        }
        if (! empty($filters['source_ip'])) {
            $query->where('source_ip', $filters['source_ip']);
        }
        if (! empty($filters['actor_user_id'])) {
            $query->where('actor_user_id', (int) $filters['actor_user_id']);
        }
        if (! empty($filters['q'])) {
            $q = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $filters['q']).'%';
            $query->where(function ($inner) use ($q) {
                $inner->where('title', 'like', $q)
                    ->orWhere('description', 'like', $q)
                    ->orWhere('event_type', 'like', $q)
                    ->orWhere('actor_email', 'like', $q)
                    ->orWhere('source_ip', 'like', $q);
            });
        }
        if (! empty($filters['from'])) {
            $query->where('occurred_at', '>=', Carbon::parse($filters['from'])->startOfDay());
        }
        if (! empty($filters['to'])) {
            $query->where('occurred_at', '<=', Carbon::parse($filters['to'])->endOfDay());
        }
    }

    private function truncateUa(?string $ua): ?string
    {
        if ($ua === null || $ua === '') {
            return null;
        }

        return mb_substr($ua, 0, 500);
    }
}
