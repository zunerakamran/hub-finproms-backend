<?php

namespace App\Services;

use App\Models\ActivityAnomaly;
use App\Models\ActivityLog;
use App\Models\Hub;
use App\Models\SecurityEvent;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class ActivityAnomalyService
{
    /** @var list<string> */
    private const FAILED_LOGIN_ACTIONS = [
        'auth.login_failed',
        'auth.login_blocked',
    ];

    /** @var list<string> */
    private const HIGH_IMPACT_ACTION_PREFIXES = [
        'auth.force_logout',
        'auth.session_timeout_updated',
        'gdpr.',
        'capabilities.',
        'modules.',
        'firm.head.',
        'advisor.discontinue',
        'advisor.import',
        'secmon.',
        'hub.',
    ];

    /** @var list<string> */
    private const EXPORT_ACTIONS = [
        'gdpr.export',
        'activity_logs.report',
        'activity_logs.view',
        'gc.reports.export',
        'smc.reports.export',
        'hub.backup.download',
    ];

    public function __construct(
        private readonly HubService $hubs,
        private readonly ActivityLogService $activityLogs,
        private readonly SecurityMonitoringService $security
    ) {}

    public function schemaReady(): bool
    {
        try {
            return Schema::hasTable('activity_anomalies');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array{
     *   anomaly_types: list<array{value: string, label: string}>,
     *   severities: list<string>,
     *   statuses: list<string>
     * }
     */
    public function options(): array
    {
        return [
            'anomaly_types' => collect(ActivityAnomaly::TYPE_LABELS)
                ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                ->values()
                ->all(),
            'severities' => ActivityAnomaly::SEVERITIES,
            'statuses' => ActivityAnomaly::STATUSES,
        ];
    }

    /**
     * @param  array{
     *   anomaly_type?: string|null,
     *   severity?: string|null,
     *   status?: string|null,
     *   q?: string|null,
     *   from?: string|null,
     *   to?: string|null,
     *   user_id?: int|null,
     *   per_page?: int|null
     * }  $filters
     */
    public function list(Hub $hub, array $filters = []): LengthAwarePaginator
    {
        $this->assertSchema();

        $query = $this->filteredQuery($hub, $filters)->orderByDesc('id');
        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 25)));

        return $query->paginate($perPage);
    }

    public function show(Hub $hub, ActivityAnomaly $anomaly): ActivityAnomaly
    {
        $this->assertSchema();
        $this->assertBelongsToHub($hub, $anomaly);

        return $anomaly;
    }

    /**
     * @param  array{status: string}  $data
     */
    public function updateStatus(Hub $hub, User $actor, ActivityAnomaly $anomaly, array $data, ?Request $request = null): ActivityAnomaly
    {
        $this->assertSchema();
        $this->assertBelongsToHub($hub, $anomaly);

        $status = (string) $data['status'];
        if (! in_array($status, [
            ActivityAnomaly::STATUS_OPEN,
            ActivityAnomaly::STATUS_REVIEWED,
            ActivityAnomaly::STATUS_DISMISSED,
        ], true)) {
            throw ValidationException::withMessages([
                'status' => 'Status must be open, reviewed, or dismissed.',
            ]);
        }

        if ($anomaly->status === ActivityAnomaly::STATUS_ESCALATED && $status !== ActivityAnomaly::STATUS_ESCALATED) {
            // Allow marking reviewed/dismissed after escalate for closure, but keep link.
        }

        $anomaly->status = $status;
        if (in_array($status, [ActivityAnomaly::STATUS_REVIEWED, ActivityAnomaly::STATUS_DISMISSED], true)) {
            $anomaly->reviewed_by_user_id = $actor->id;
            $anomaly->reviewed_by_name = $actor->name;
            $anomaly->reviewed_at = now();
        } elseif ($status === ActivityAnomaly::STATUS_OPEN) {
            $anomaly->reviewed_by_user_id = null;
            $anomaly->reviewed_by_name = null;
            $anomaly->reviewed_at = null;
        }
        $anomaly->save();

        $this->activityLogs->log([
            'action' => 'activity.anomaly_'.$status,
            'description' => 'Marked activity anomaly #'.$anomaly->id.' as '.$status,
            'user' => $actor,
            'hub' => $hub,
            'subject' => $anomaly,
            'request' => $request,
            'properties' => [
                'anomaly_id' => $anomaly->id,
                'anomaly_type' => $anomaly->anomaly_type,
                'status' => $status,
            ],
        ]);

        return $anomaly->fresh() ?? $anomaly;
    }

    public function escalate(Hub $hub, User $actor, ActivityAnomaly $anomaly, ?Request $request = null): ActivityAnomaly
    {
        $this->assertSchema();
        $this->assertBelongsToHub($hub, $anomaly);

        if (! $hub->hasSecurityMonitoringFunctionality()) {
            throw ValidationException::withMessages([
                'security_monitoring' => 'Security Monitoring is not enabled for this hub. Enable it under Functionalities to escalate.',
            ]);
        }

        if ($anomaly->security_event_id) {
            return $anomaly;
        }

        $event = $this->security->recordEvent($hub, [
            'category' => $this->securityCategoryFor($anomaly->anomaly_type),
            'event_type' => 'activity.anomaly.'.$anomaly->anomaly_type,
            'severity' => $this->mapSeverityToSecurity($anomaly->severity),
            'title' => 'Escalated activity anomaly: '.$anomaly->title,
            'description' => $anomaly->description
                ."\n\nEscalated from Activity Logs anomaly #{$anomaly->id} by {$actor->email}.",
            'actor' => $anomaly->actor_user_id
                ? User::query()->find($anomaly->actor_user_id)
                : null,
            'actor_name' => $anomaly->actor_name,
            'actor_email' => $anomaly->actor_email,
            'actor_role' => $anomaly->actor_role,
            'source_ip' => $anomaly->source_ip,
            'related_refs' => [
                'activity_anomaly_id' => $anomaly->id,
                'activity_log_ids' => $anomaly->related_activity_log_ids ?? [],
            ],
            'properties' => array_merge($anomaly->properties ?? [], [
                'escalated_from' => 'activity_anomaly',
                'anomaly_type' => $anomaly->anomaly_type,
            ]),
            'request' => $request,
            'create_incident' => in_array($anomaly->severity, [
                ActivityAnomaly::SEVERITY_HIGH,
            ], true),
        ]);

        $anomaly->security_event_id = $event?->id;
        $anomaly->security_incident_id = $event?->security_incident_id;
        $anomaly->status = ActivityAnomaly::STATUS_ESCALATED;
        $anomaly->reviewed_by_user_id = $actor->id;
        $anomaly->reviewed_by_name = $actor->name;
        $anomaly->reviewed_at = now();
        $anomaly->save();

        $this->activityLogs->log([
            'action' => 'activity.anomaly_escalate',
            'description' => 'Escalated activity anomaly #'.$anomaly->id.' to Security Monitoring',
            'user' => $actor,
            'hub' => $hub,
            'subject' => $anomaly,
            'request' => $request,
            'properties' => [
                'anomaly_id' => $anomaly->id,
                'security_event_id' => $anomaly->security_event_id,
                'security_incident_id' => $anomaly->security_incident_id,
            ],
        ]);

        return $anomaly->fresh() ?? $anomaly;
    }

    /**
     * Run all detectors for a hub. Returns counts created.
     *
     * @return array{created: int, by_type: array<string, int>}
     */
    public function detectForHub(Hub $hub): array
    {
        if (! $this->schemaReady()) {
            return ['created' => 0, 'by_type' => []];
        }

        $created = [];
        $created = array_merge($created, $this->detectFailedLoginBursts($hub));
        $created = array_merge($created, $this->detectNewIpLogins($hub));
        $created = array_merge($created, $this->detectAdminActionBursts($hub));
        $created = array_merge($created, $this->detectExportVolume($hub));
        $created = array_merge($created, $this->detectActionRate($hub));

        $byType = [];
        foreach ($created as $anomaly) {
            $byType[$anomaly->anomaly_type] = ($byType[$anomaly->anomaly_type] ?? 0) + 1;
        }

        return [
            'created' => count($created),
            'by_type' => $byType,
        ];
    }

    /**
     * Lightweight near-real-time hook after an activity log is written.
     */
    public function maybeDetectFromLog(ActivityLog $log): void
    {
        if (! $this->schemaReady() || ! $log->hub_id) {
            return;
        }

        try {
            $hub = Hub::query()->find($log->hub_id);
            if (! $hub) {
                return;
            }

            $action = (string) $log->action;

            if (in_array($action, self::FAILED_LOGIN_ACTIONS, true)) {
                $this->detectFailedLoginBursts($hub, $log);
            }

            if ($action === 'auth.login') {
                $this->detectNewIpLogins($hub, $log);
            }

            if ($this->isHighImpactAction($action)) {
                $this->detectAdminActionBursts($hub, $log);
            }

            if (in_array($action, self::EXPORT_ACTIONS, true) || str_ends_with($action, '.export')) {
                $this->detectExportVolume($hub, $log);
            }
        } catch (\Throwable) {
            // Never break the request because of anomaly detection.
        }
    }

    /**
     * @return list<ActivityAnomaly>
     */
    private function detectFailedLoginBursts(Hub $hub, ?ActivityLog $trigger = null): array
    {
        $windowMinutes = 15;
        $threshold = 5;
        $since = now()->subMinutes($windowMinutes);

        $query = ActivityLog::query()
            ->where('hub_id', $hub->id)
            ->whereIn('action', self::FAILED_LOGIN_ACTIONS)
            ->where('created_at', '>=', $since);

        if ($trigger?->ip_address) {
            $query->where('ip_address', $trigger->ip_address);
        }

        $rows = $query->orderByDesc('id')->limit(200)->get();
        if ($rows->isEmpty()) {
            return [];
        }

        $groups = $rows->groupBy(function (ActivityLog $log) {
            $key = $log->ip_address ?: 'unknown';
            $email = strtolower((string) ($log->user_email ?: ($log->properties['email'] ?? '')));

            return $key.'|'.$email;
        });

        $created = [];
        foreach ($groups as $groupKey => $group) {
            if ($group->count() < $threshold) {
                continue;
            }

            [$ip, $email] = array_pad(explode('|', (string) $groupKey, 2), 2, '');
            $ip = $ip === 'unknown' ? null : $ip;
            $latest = $group->first();
            $ids = $group->take(20)->pluck('id')->map(fn ($id) => (int) $id)->values()->all();

            $anomaly = $this->createIfNew($hub, [
                'anomaly_type' => ActivityAnomaly::TYPE_FAILED_LOGIN_BURST,
                'severity' => ActivityAnomaly::SEVERITY_HIGH,
                'title' => 'Repeated failed / blocked sign-ins',
                'description' => sprintf(
                    'At %s, %d failed or blocked login attempts were recorded within %d minutes%s%s. This may indicate credential stuffing or a locked-out user.',
                    now()->toIso8601String(),
                    $group->count(),
                    $windowMinutes,
                    $ip ? ' from IP '.$ip : '',
                    $email !== '' ? ' targeting '.$email : ''
                ),
                'window_start' => $since,
                'window_end' => now(),
                'actor_user_id' => $latest->user_id,
                'actor_name' => $latest->user_name,
                'actor_email' => $email !== '' ? $email : $latest->user_email,
                'actor_role' => $latest->user_role,
                'source_ip' => $ip,
                'related_activity_log_ids' => $ids,
                'properties' => [
                    'count' => $group->count(),
                    'threshold' => $threshold,
                    'window_minutes' => $windowMinutes,
                    'dedupe_key' => 'failed_login|'.$ip.'|'.$email,
                ],
            ]);

            if ($anomaly) {
                $created[] = $anomaly;
            }
        }

        return $created;
    }

    /**
     * @return list<ActivityAnomaly>
     */
    private function detectNewIpLogins(Hub $hub, ?ActivityLog $trigger = null): array
    {
        $lookbackDays = 30;
        $created = [];

        $candidates = ActivityLog::query()
            ->where('hub_id', $hub->id)
            ->where('action', 'auth.login')
            ->whereNotNull('user_id')
            ->whereNotNull('ip_address')
            ->where('created_at', '>=', now()->subHours(2))
            ->when($trigger?->id, fn (Builder $q) => $q->where('id', $trigger->id))
            ->orderByDesc('id')
            ->limit($trigger ? 1 : 50)
            ->get();

        foreach ($candidates as $login) {
            $priorCount = ActivityLog::query()
                ->where('hub_id', $hub->id)
                ->where('action', 'auth.login')
                ->where('user_id', $login->user_id)
                ->where('created_at', '<', $login->created_at)
                ->where('created_at', '>=', now()->subDays($lookbackDays))
                ->count();

            // Need an established baseline before flagging a "new" IP.
            if ($priorCount < 3) {
                continue;
            }

            $seenIp = ActivityLog::query()
                ->where('hub_id', $hub->id)
                ->where('action', 'auth.login')
                ->where('user_id', $login->user_id)
                ->where('ip_address', $login->ip_address)
                ->where('id', '!=', $login->id)
                ->where('created_at', '>=', now()->subDays($lookbackDays))
                ->exists();

            if ($seenIp) {
                continue;
            }

            $anomaly = $this->createIfNew($hub, [
                'anomaly_type' => ActivityAnomaly::TYPE_NEW_IP_LOGIN,
                'severity' => ActivityAnomaly::SEVERITY_MEDIUM,
                'title' => 'Sign-in from an uncommon IP address',
                'description' => sprintf(
                    'User %s signed in from IP %s at %s. That IP was not seen for this user in the last %d days (prior successful logins in window: %d).',
                    $login->user_email ?: ($login->user_name ?: 'user#'.$login->user_id),
                    $login->ip_address,
                    optional($login->created_at)?->toIso8601String() ?? now()->toIso8601String(),
                    $lookbackDays,
                    $priorCount
                ),
                'window_start' => now()->subDays($lookbackDays),
                'window_end' => $login->created_at ?? now(),
                'actor_user_id' => $login->user_id,
                'actor_name' => $login->user_name,
                'actor_email' => $login->user_email,
                'actor_role' => $login->user_role,
                'source_ip' => $login->ip_address,
                'related_activity_log_ids' => [(int) $login->id],
                'properties' => [
                    'lookback_days' => $lookbackDays,
                    'prior_logins' => $priorCount,
                    'dedupe_key' => 'new_ip|'.$login->user_id.'|'.$login->ip_address,
                ],
            ]);

            if ($anomaly) {
                $created[] = $anomaly;
            }
        }

        return $created;
    }

    /**
     * @return list<ActivityAnomaly>
     */
    private function detectAdminActionBursts(Hub $hub, ?ActivityLog $trigger = null): array
    {
        $windowMinutes = 30;
        $threshold = 8;
        $since = now()->subMinutes($windowMinutes);

        $logs = ActivityLog::query()
            ->where('hub_id', $hub->id)
            ->whereNotNull('user_id')
            ->where('created_at', '>=', $since)
            ->when($trigger?->user_id, fn (Builder $q) => $q->where('user_id', $trigger->user_id))
            ->orderByDesc('id')
            ->limit(500)
            ->get()
            ->filter(fn (ActivityLog $log) => $this->isHighImpactAction((string) $log->action));

        $created = [];
        foreach ($logs->groupBy('user_id') as $userId => $group) {
            if ($group->count() < $threshold) {
                continue;
            }

            $latest = $group->first();
            $distinct = $group->pluck('action')->unique()->count();
            $ids = $group->take(25)->pluck('id')->map(fn ($id) => (int) $id)->values()->all();

            $anomaly = $this->createIfNew($hub, [
                'anomaly_type' => ActivityAnomaly::TYPE_ADMIN_ACTION_BURST,
                'severity' => $distinct >= 4
                    ? ActivityAnomaly::SEVERITY_HIGH
                    : ActivityAnomaly::SEVERITY_MEDIUM,
                'title' => 'Burst of high-impact admin actions',
                'description' => sprintf(
                    'User %s performed %d high-impact actions (%d distinct types) within %d minutes ending %s.',
                    $latest->user_email ?: ($latest->user_name ?: 'user#'.$userId),
                    $group->count(),
                    $distinct,
                    $windowMinutes,
                    now()->toIso8601String()
                ),
                'window_start' => $since,
                'window_end' => now(),
                'actor_user_id' => (int) $userId,
                'actor_name' => $latest->user_name,
                'actor_email' => $latest->user_email,
                'actor_role' => $latest->user_role,
                'source_ip' => $latest->ip_address,
                'related_activity_log_ids' => $ids,
                'properties' => [
                    'count' => $group->count(),
                    'distinct_actions' => $distinct,
                    'threshold' => $threshold,
                    'window_minutes' => $windowMinutes,
                    'actions' => $group->pluck('action')->unique()->values()->take(20)->all(),
                    'dedupe_key' => 'admin_burst|'.$userId,
                ],
            ]);

            if ($anomaly) {
                $created[] = $anomaly;
            }
        }

        return $created;
    }

    /**
     * @return list<ActivityAnomaly>
     */
    private function detectExportVolume(Hub $hub, ?ActivityLog $trigger = null): array
    {
        $windowHours = 6;
        $threshold = 10;
        $since = now()->subHours($windowHours);

        $logs = ActivityLog::query()
            ->where('hub_id', $hub->id)
            ->where('created_at', '>=', $since)
            ->when($trigger?->user_id, fn (Builder $q) => $q->where('user_id', $trigger->user_id))
            ->orderByDesc('id')
            ->limit(500)
            ->get()
            ->filter(function (ActivityLog $log) {
                $action = (string) $log->action;

                return in_array($action, self::EXPORT_ACTIONS, true) || str_ends_with($action, '.export');
            });

        $created = [];
        foreach ($logs->groupBy(fn (ActivityLog $log) => $log->user_id ?: 'guest:'.($log->ip_address ?: 'unknown')) as $key => $group) {
            if ($group->count() < $threshold) {
                continue;
            }

            $latest = $group->first();
            $ids = $group->take(20)->pluck('id')->map(fn ($id) => (int) $id)->values()->all();

            $anomaly = $this->createIfNew($hub, [
                'anomaly_type' => ActivityAnomaly::TYPE_EXPORT_VOLUME,
                'severity' => ActivityAnomaly::SEVERITY_HIGH,
                'title' => 'Unusual export / report volume',
                'description' => sprintf(
                    '%s triggered %d export or report-view actions within %d hours ending %s.',
                    $latest->user_email ?: ($latest->user_name ?: 'Guest / unknown'),
                    $group->count(),
                    $windowHours,
                    now()->toIso8601String()
                ),
                'window_start' => $since,
                'window_end' => now(),
                'actor_user_id' => $latest->user_id,
                'actor_name' => $latest->user_name,
                'actor_email' => $latest->user_email,
                'actor_role' => $latest->user_role,
                'source_ip' => $latest->ip_address,
                'related_activity_log_ids' => $ids,
                'properties' => [
                    'count' => $group->count(),
                    'threshold' => $threshold,
                    'window_hours' => $windowHours,
                    'dedupe_key' => 'export|'.$key,
                ],
            ]);

            if ($anomaly) {
                $created[] = $anomaly;
            }
        }

        return $created;
    }

    /**
     * @return list<ActivityAnomaly>
     */
    private function detectActionRate(Hub $hub, ?ActivityLog $trigger = null): array
    {
        $windowMinutes = 60;
        $absoluteThreshold = 120;
        $since = now()->subMinutes($windowMinutes);

        $query = ActivityLog::query()
            ->where('hub_id', $hub->id)
            ->whereNotNull('user_id')
            ->where('created_at', '>=', $since)
            ->when($trigger?->user_id, fn (Builder $q) => $q->where('user_id', $trigger->user_id));

        $counts = $query
            ->selectRaw('user_id, user_name, user_email, user_role, COUNT(*) as total, MAX(id) as latest_id')
            ->groupBy('user_id', 'user_name', 'user_email', 'user_role')
            ->having('total', '>=', $absoluteThreshold)
            ->get();

        $created = [];
        foreach ($counts as $row) {
            $baselineDays = 7;
            $baselineTotal = ActivityLog::query()
                ->where('hub_id', $hub->id)
                ->where('user_id', $row->user_id)
                ->where('created_at', '>=', now()->subDays($baselineDays))
                ->where('created_at', '<', $since)
                ->count();

            $hourlyBaseline = $baselineTotal > 0
                ? $baselineTotal / max(1, $baselineDays * 24)
                : 0;

            // Flag if absolute burst OR clearly above baseline (3x, min 40).
            $aboveBaseline = $hourlyBaseline >= 5 && (int) $row->total >= max(40, (int) ceil($hourlyBaseline * 3));
            if ((int) $row->total < $absoluteThreshold && ! $aboveBaseline) {
                continue;
            }

            $sampleIds = ActivityLog::query()
                ->where('hub_id', $hub->id)
                ->where('user_id', $row->user_id)
                ->where('created_at', '>=', $since)
                ->orderByDesc('id')
                ->limit(20)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all();

            $latest = ActivityLog::query()->find($row->latest_id);

            $anomaly = $this->createIfNew($hub, [
                'anomaly_type' => ActivityAnomaly::TYPE_ACTION_RATE,
                'severity' => (int) $row->total >= 200
                    ? ActivityAnomaly::SEVERITY_HIGH
                    : ActivityAnomaly::SEVERITY_MEDIUM,
                'title' => 'Unusual activity rate for a user',
                'description' => sprintf(
                    'User %s recorded %d activity-log entries in the last %d minutes (7-day hourly baseline ≈ %.1f).',
                    $row->user_email ?: ($row->user_name ?: 'user#'.$row->user_id),
                    (int) $row->total,
                    $windowMinutes,
                    $hourlyBaseline
                ),
                'window_start' => $since,
                'window_end' => now(),
                'actor_user_id' => (int) $row->user_id,
                'actor_name' => $row->user_name,
                'actor_email' => $row->user_email,
                'actor_role' => $row->user_role,
                'source_ip' => $latest?->ip_address,
                'related_activity_log_ids' => $sampleIds,
                'properties' => [
                    'count' => (int) $row->total,
                    'absolute_threshold' => $absoluteThreshold,
                    'hourly_baseline' => round($hourlyBaseline, 2),
                    'window_minutes' => $windowMinutes,
                    'dedupe_key' => 'action_rate|'.$row->user_id,
                ],
            ]);

            if ($anomaly) {
                $created[] = $anomaly;
            }
        }

        return $created;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function createIfNew(Hub $hub, array $payload): ?ActivityAnomaly
    {
        $dedupeKey = (string) ($payload['properties']['dedupe_key'] ?? '');
        $cooldownHours = 6;

        $existing = ActivityAnomaly::query()
            ->where('hub_id', $hub->id)
            ->where('anomaly_type', $payload['anomaly_type'])
            ->where('detected_at', '>=', now()->subHours($cooldownHours))
            ->whereIn('status', [
                ActivityAnomaly::STATUS_OPEN,
                ActivityAnomaly::STATUS_ESCALATED,
            ])
            ->when($dedupeKey !== '', function (Builder $q) use ($dedupeKey) {
                $q->where('properties->dedupe_key', $dedupeKey);
            })
            ->when(($payload['actor_user_id'] ?? null) && $dedupeKey === '', function (Builder $q) use ($payload) {
                $q->where('actor_user_id', $payload['actor_user_id']);
            })
            ->when(($payload['source_ip'] ?? null) && $dedupeKey === '', function (Builder $q) use ($payload) {
                $q->where('source_ip', $payload['source_ip']);
            })
            ->exists();

        if ($existing) {
            return null;
        }

        return ActivityAnomaly::query()->create([
            'hub_id' => $hub->id,
            'detected_at' => now(),
            'window_start' => $payload['window_start'] ?? null,
            'window_end' => $payload['window_end'] ?? null,
            'anomaly_type' => $payload['anomaly_type'],
            'severity' => $payload['severity'] ?? ActivityAnomaly::SEVERITY_MEDIUM,
            'title' => mb_substr((string) $payload['title'], 0, 255),
            'description' => (string) $payload['description'],
            'status' => ActivityAnomaly::STATUS_OPEN,
            'actor_user_id' => $payload['actor_user_id'] ?? null,
            'actor_name' => $payload['actor_name'] ?? null,
            'actor_email' => $payload['actor_email'] ?? null,
            'actor_role' => $payload['actor_role'] ?? null,
            'source_ip' => $payload['source_ip'] ?? null,
            'related_activity_log_ids' => $payload['related_activity_log_ids'] ?? [],
            'properties' => $payload['properties'] ?? [],
        ]);
    }

    private function isHighImpactAction(string $action): bool
    {
        foreach (self::HIGH_IMPACT_ACTION_PREFIXES as $prefix) {
            if (str_starts_with($action, $prefix) || $action === rtrim($prefix, '.')) {
                return true;
            }
        }

        return false;
    }

    private function securityCategoryFor(string $anomalyType): string
    {
        return match ($anomalyType) {
            ActivityAnomaly::TYPE_FAILED_LOGIN_BURST,
            ActivityAnomaly::TYPE_NEW_IP_LOGIN => SecurityEvent::CATEGORY_AUTH,
            ActivityAnomaly::TYPE_EXPORT_VOLUME => SecurityEvent::CATEGORY_DATA,
            ActivityAnomaly::TYPE_ADMIN_ACTION_BURST,
            ActivityAnomaly::TYPE_ACTION_RATE => SecurityEvent::CATEGORY_AUTHORIZATION,
            default => SecurityEvent::CATEGORY_OTHER,
        };
    }

    private function mapSeverityToSecurity(string $severity): string
    {
        return match ($severity) {
            ActivityAnomaly::SEVERITY_HIGH => SecurityEvent::SEVERITY_HIGH,
            ActivityAnomaly::SEVERITY_LOW => SecurityEvent::SEVERITY_LOW,
            ActivityAnomaly::SEVERITY_INFO => SecurityEvent::SEVERITY_INFO,
            default => SecurityEvent::SEVERITY_MEDIUM,
        };
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function filteredQuery(Hub $hub, array $filters): Builder
    {
        $query = ActivityAnomaly::query()->where('hub_id', $hub->id);

        if (! empty($filters['anomaly_type'])) {
            $query->where('anomaly_type', (string) $filters['anomaly_type']);
        }

        if (! empty($filters['severity'])) {
            $query->where('severity', (string) $filters['severity']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', (string) $filters['status']);
        }

        if (array_key_exists('user_id', $filters) && $filters['user_id'] !== null && $filters['user_id'] !== '') {
            if ((int) $filters['user_id'] === 0) {
                $query->whereNull('actor_user_id');
            } else {
                $query->where('actor_user_id', (int) $filters['user_id']);
            }
        }

        if (! empty($filters['q'])) {
            $q = '%'.trim((string) $filters['q']).'%';
            $query->where(function (Builder $inner) use ($q) {
                $inner->where('title', 'like', $q)
                    ->orWhere('description', 'like', $q)
                    ->orWhere('actor_name', 'like', $q)
                    ->orWhere('actor_email', 'like', $q)
                    ->orWhere('source_ip', 'like', $q)
                    ->orWhere('anomaly_type', 'like', $q);
            });
        }

        if (! empty($filters['from'])) {
            $query->where('detected_at', '>=', Carbon::parse((string) $filters['from'])->startOfDay());
        }

        if (! empty($filters['to'])) {
            $query->where('detected_at', '<=', Carbon::parse((string) $filters['to'])->endOfDay());
        }

        return $query;
    }

    private function assertSchema(): void
    {
        if (! $this->schemaReady()) {
            throw ValidationException::withMessages([
                'activity_anomalies' => 'Activity anomaly tables are not migrated yet. Run migrations.',
            ]);
        }
    }

    private function assertBelongsToHub(Hub $hub, ActivityAnomaly $anomaly): void
    {
        if ((int) $anomaly->hub_id !== (int) $hub->id) {
            abort(404);
        }
    }
}
