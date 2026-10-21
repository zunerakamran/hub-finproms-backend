<?php

namespace App\Services;

use App\Models\Hub;
use App\Models\SecurityEvent;
use App\Models\SecurityIncident;
use App\Models\SecurityIncidentNote;
use App\Models\SecurityMonitoringSetting;
use App\Models\User;
use App\Support\IpAddressMatcher;
use App\Support\WebsiteCompliance\WcDatabaseContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

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
        $this->ensureSchema();

        $existing = SecurityMonitoringSetting::query()
            ->where('hub_id', $hub->id)
            ->first();

        if ($existing) {
            return $existing;
        }

        // Fallback: single-row settings on a content hub DB (no matching hub_id yet).
        $fallback = SecurityMonitoringSetting::query()->orderBy('id')->first();
        if ($fallback && $fallback->hub_id === null) {
            $fallback->hub_id = $hub->id;
            $fallback->save();

            return $fallback;
        }

        try {
            return SecurityMonitoringSetting::query()->create([
                'hub_id' => $hub->id,
                'alert_min_severity' => SecurityEvent::SEVERITY_HIGH,
                'alert_email_enabled' => false,
                'alert_emails' => [],
                'failed_login_threshold' => 5,
                'failed_login_window_minutes' => 15,
            ]);
        } catch (\Throwable) {
            // Race on unique hub_id, or older schema — return whatever exists.
            $again = SecurityMonitoringSetting::query()
                ->where('hub_id', $hub->id)
                ->first()
                ?: SecurityMonitoringSetting::query()->orderBy('id')->first();

            if ($again) {
                return $again;
            }

            throw new \RuntimeException('Unable to load or create security monitoring settings.');
        }
    }

    /**
     * Ensure security monitoring tables exist on the active (possibly remote) connection.
     * Power Admin acting on a content hub hits that hub's DB; Central migrations alone are not enough.
     */
    public function ensureSchema(): void
    {
        $connection = WcDatabaseContext::active()
            ? WcDatabaseContext::connection()
            : (string) config('database.default');

        $schema = Schema::connection($connection);

        if (! $schema->hasTable('security_events')) {
            $schema->create('security_events', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('hub_id')->nullable()->index();
                $table->dateTime('occurred_at');
                $table->dateTime('detected_at');
                $table->string('category', 40);
                $table->string('event_type', 120);
                $table->string('severity', 20)->default('info');
                $table->string('title', 255);
                $table->text('description');
                $table->string('status', 40)->default('open');
                $table->unsignedBigInteger('actor_user_id')->nullable();
                $table->string('actor_name')->nullable();
                $table->string('actor_email')->nullable();
                $table->string('actor_role', 60)->nullable();
                $table->string('source_ip', 45)->nullable();
                $table->string('user_agent', 500)->nullable();
                $table->string('route', 255)->nullable();
                $table->string('http_action', 40)->nullable();
                $table->unsignedBigInteger('security_incident_id')->nullable();
                $table->json('related_refs')->nullable();
                $table->json('properties')->nullable();
                $table->timestamps();

                $table->index(['occurred_at', 'severity'], 'secmon_events_occurred_sev_idx');
                $table->index(['category', 'status'], 'secmon_events_cat_status_idx');
                $table->index(['event_type', 'occurred_at'], 'secmon_events_type_occurred_idx');
                $table->index(['source_ip', 'occurred_at'], 'secmon_events_ip_occurred_idx');
                $table->index(['security_incident_id'], 'secmon_events_incident_idx');
            });
        }

        if (! $schema->hasTable('security_incidents')) {
            $schema->create('security_incidents', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('hub_id')->nullable()->index();
                $table->string('title', 255);
                $table->text('description')->nullable();
                $table->string('category', 40)->default('manual');
                $table->string('affected_module', 80)->nullable();
                $table->boolean('disaster_recognized')->default(false);
                $table->dateTime('disaster_recognized_at')->nullable();
                $table->string('recovery_status', 40)->default('none');
                $table->unsignedBigInteger('last_snapshot_id')->nullable();
                $table->unsignedBigInteger('last_rollback_snapshot_id')->nullable();
                $table->dateTime('last_rollback_at')->nullable();
                $table->string('severity', 20)->default('medium');
                $table->string('status', 40)->default('open');
                $table->unsignedBigInteger('declared_by_user_id')->nullable();
                $table->string('declared_by_name')->nullable();
                $table->unsignedBigInteger('resolved_by_user_id')->nullable();
                $table->string('resolved_by_name')->nullable();
                $table->text('resolution_summary')->nullable();
                $table->dateTime('resolved_at')->nullable();
                $table->timestamps();

                $table->index(['status', 'severity', 'created_at'], 'secmon_inc_status_sev_created_idx');
            });
        } else {
            if (! $schema->hasColumn('security_incidents', 'affected_module')) {
                $schema->table('security_incidents', function (Blueprint $table) {
                    $table->string('affected_module', 80)->nullable();
                    $table->boolean('disaster_recognized')->default(false);
                    $table->dateTime('disaster_recognized_at')->nullable();
                    $table->string('recovery_status', 40)->default('none');
                    $table->unsignedBigInteger('last_snapshot_id')->nullable();
                    $table->unsignedBigInteger('last_rollback_snapshot_id')->nullable();
                    $table->dateTime('last_rollback_at')->nullable();
                });
            }
        }

        if (! $schema->hasTable('module_disaster_snapshots')) {
            $schema->create('module_disaster_snapshots', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('hub_id')->nullable()->index();
                $table->string('module_key', 80);
                $table->string('label', 255)->nullable();
                $table->string('trigger', 40)->default('manual');
                $table->string('disk_path', 500);
                $table->unsignedBigInteger('size_bytes')->nullable();
                $table->json('row_counts')->nullable();
                $table->boolean('module_enabled')->nullable();
                $table->unsignedBigInteger('security_incident_id')->nullable()->index();
                $table->unsignedBigInteger('created_by_user_id')->nullable();
                $table->string('created_by_name')->nullable();
                $table->timestamps();
                $table->index(['hub_id', 'module_key', 'created_at'], 'mdr_snap_hub_module_created_idx');
            });
        }

        if (! $schema->hasTable('security_incident_notes')) {
            $schema->create('security_incident_notes', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('security_incident_id');
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('author_name')->nullable();
                $table->text('body');
                $table->timestamps();

                $table->index(['security_incident_id', 'created_at'], 'secmon_notes_incident_created_idx');
            });
        }

        if (! $schema->hasTable('security_monitoring_settings')) {
            $schema->create('security_monitoring_settings', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('hub_id')->nullable();
                $table->string('alert_min_severity', 20)->default('high');
                $table->boolean('alert_email_enabled')->default(false);
                $table->json('alert_emails')->nullable();
                $table->unsignedSmallInteger('failed_login_threshold')->default(5);
                $table->unsignedSmallInteger('failed_login_window_minutes')->default(15);
                $table->boolean('access_uk_only')->default(false);
                $table->boolean('access_uk_cities_enabled')->default(false);
                $table->json('access_restriction_cities')->nullable();
                $table->boolean('access_restrict_countries_enabled')->default(false);
                $table->boolean('access_allow_countries_enabled')->default(false);
                $table->json('access_restricted_countries')->nullable();
                $table->json('access_restricted_cities')->nullable();
                $table->json('access_allowed_countries')->nullable();
                $table->json('access_allowed_cities')->nullable();
                $table->boolean('access_restrict_ips_enabled')->default(false);
                $table->boolean('access_allow_ips_enabled')->default(false);
                $table->json('access_restricted_ips')->nullable();
                $table->json('access_allowed_ips')->nullable();
                $table->boolean('access_restrict_vpn_enabled')->default(false);
                $table->boolean('access_allow_vpn_enabled')->default(false);
                $table->string('access_enforcement_mode', 20)->default('enforce');
                $table->dateTime('last_dependency_scan_at')->nullable();
                $table->string('last_dependency_scan_status', 40)->nullable();
                $table->text('last_dependency_scan_summary')->nullable();
                $table->timestamps();

                $table->unique('hub_id', 'secmon_settings_hub_unique');
            });
        } else {
            // Heal columns on remote content-hub DBs that predate access restrictions.
            $healColumns = [
                'access_restriction_cities' => fn (Blueprint $t) => $t->json('access_restriction_cities')->nullable(),
                'access_uk_only' => fn (Blueprint $t) => $t->boolean('access_uk_only')->default(false),
                'access_uk_cities_enabled' => fn (Blueprint $t) => $t->boolean('access_uk_cities_enabled')->default(false),
                'access_restrict_countries_enabled' => fn (Blueprint $t) => $t->boolean('access_restrict_countries_enabled')->default(false),
                'access_allow_countries_enabled' => fn (Blueprint $t) => $t->boolean('access_allow_countries_enabled')->default(false),
                'access_restricted_countries' => fn (Blueprint $t) => $t->json('access_restricted_countries')->nullable(),
                'access_restricted_cities' => fn (Blueprint $t) => $t->json('access_restricted_cities')->nullable(),
                'access_allowed_countries' => fn (Blueprint $t) => $t->json('access_allowed_countries')->nullable(),
                'access_allowed_cities' => fn (Blueprint $t) => $t->json('access_allowed_cities')->nullable(),
                'access_restrict_ips_enabled' => fn (Blueprint $t) => $t->boolean('access_restrict_ips_enabled')->default(false),
                'access_allow_ips_enabled' => fn (Blueprint $t) => $t->boolean('access_allow_ips_enabled')->default(false),
                'access_restricted_ips' => fn (Blueprint $t) => $t->json('access_restricted_ips')->nullable(),
                'access_allowed_ips' => fn (Blueprint $t) => $t->json('access_allowed_ips')->nullable(),
                'access_restrict_vpn_enabled' => fn (Blueprint $t) => $t->boolean('access_restrict_vpn_enabled')->default(false),
                'access_allow_vpn_enabled' => fn (Blueprint $t) => $t->boolean('access_allow_vpn_enabled')->default(false),
                'access_enforcement_mode' => fn (Blueprint $t) => $t->string('access_enforcement_mode', 20)->default('enforce'),
            ];

            $missing = [];
            foreach ($healColumns as $column => $_) {
                if (! $schema->hasColumn('security_monitoring_settings', $column)) {
                    $missing[] = $column;
                }
            }

            if ($missing !== []) {
                $schema->table('security_monitoring_settings', function (Blueprint $table) use ($healColumns, $missing) {
                    foreach ($missing as $column) {
                        ($healColumns[$column])($table);
                    }
                });
            }
        }

        // Drop hub_id FKs if an earlier migration created them (breaks remote acting DBs).
        foreach (['security_events', 'security_incidents', 'security_monitoring_settings'] as $table) {
            if (! $schema->hasTable($table) || ! $schema->hasColumn($table, 'hub_id')) {
                continue;
            }
            try {
                $schema->table($table, function (Blueprint $blueprint) {
                    $blueprint->dropForeign(['hub_id']);
                });
            } catch (\Throwable) {
                // No FK, or already dropped.
            }
        }
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

    public const ACCESS_POLICY_CHANGED_EVENT = 'fw.access_policy_changed';

    public const ACCESS_EMERGENCY_UNLOCK_EVENT = 'fw.access_emergency_unlock';

    /** @var list<string> */
    public const ACCESS_BLOCKED_ATTEMPT_EVENTS = [
        'fw.access_restricted',
        'fw.access_would_block',
        'fw.ip_denied',
        'fw.ip_auto_blocked',
    ];

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateAccessSettings(Hub $hub, User $actor, array $data, ?Request $request = null): SecurityMonitoringSetting
    {
        $this->assertModuleEnabled($hub);
        $this->assertCan($hub, $actor, 'secmon_manage_access');

        $payload = $this->validatedAccessPayload($data);

        $settings = $this->settingsFor($hub);
        $before = $this->accessPolicySnapshot($settings->toAccessRestrictionApiArray());

        // Clear legacy single-mode column when present so it cannot conflict.
        if (\Illuminate\Support\Facades\Schema::hasColumn($settings->getTable(), 'access_restriction_mode')) {
            $payload['access_restriction_mode'] = 'off';
        }
        $settings->fill($payload);
        $settings->save();

        app(AccessRestrictionService::class)->forgetSettingsCache((int) $hub->id);

        $after = $this->accessPolicySnapshot($settings->fresh()->toAccessRestrictionApiArray());
        $this->recordAccessPolicyChange(
            $hub,
            $actor,
            $request,
            scope: 'hub',
            before: $before,
            after: $after,
            target: null
        );

        try {
            $this->activityLogs->log([
                'action' => 'secmon.access_settings_update',
                'description' => 'Updated hub access restrictions',
                'user' => $actor,
                'request' => $request,
                'status_code' => 200,
                'properties' => $settings->toAccessRestrictionApiArray(),
            ]);
        } catch (\Throwable) {
            //
        }

        return $settings;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateUserAccessSettings(
        Hub $hub,
        User $actor,
        User $target,
        array $data,
        ?Request $request = null
    ): \App\Models\UserAccessRestrictionSetting {
        $this->assertModuleEnabled($hub);
        $this->assertCan($hub, $actor, 'secmon_manage_access');

        $payload = $this->validatedAccessPayload($data);
        $payload['hub_id'] = (int) $hub->id;
        $payload['user_id'] = (int) $target->id;

        $existing = \App\Models\UserAccessRestrictionSetting::query()
            ->where('hub_id', $hub->id)
            ->where('user_id', $target->id)
            ->first();

        $before = $this->accessPolicySnapshot(
            $existing
                ? $existing->toAccessRestrictionApiArray()
                : app(AccessRestrictionService::class)->emptySettings()
        );

        $settings = \App\Models\UserAccessRestrictionSetting::query()->updateOrCreate(
            ['hub_id' => $hub->id, 'user_id' => $target->id],
            $payload
        );

        app(AccessRestrictionService::class)->forgetUserSettingsCache((int) $hub->id, (int) $target->id);

        $after = $this->accessPolicySnapshot($settings->fresh()->toAccessRestrictionApiArray());
        $this->recordAccessPolicyChange(
            $hub,
            $actor,
            $request,
            scope: 'user',
            before: $before,
            after: $after,
            target: $target
        );

        try {
            $this->activityLogs->log([
                'action' => 'secmon.user_access_settings_update',
                'description' => 'Updated user access restrictions for '.$target->email,
                'user' => $actor,
                'request' => $request,
                'status_code' => 200,
                'properties' => $settings->toAccessRestrictionApiArray(),
            ]);
        } catch (\Throwable) {
            //
        }

        return $settings;
    }

    /**
     * Recent access-policy change events for the Access Control history tab.
     *
     * @param  array{per_page?: int|null, scope?: string|null, user_id?: int|null}  $filters
     */
    public function listAccessPolicyChanges(Hub $hub, User $actor, array $filters = []): LengthAwarePaginator
    {
        $this->ensureSchema();
        $this->assertModuleEnabled($hub);
        $this->assertCan($hub, $actor, 'secmon_manage_access');

        $query = SecurityEvent::query()
            ->where(function ($q) use ($hub) {
                $q->where('hub_id', $hub->id)->orWhereNull('hub_id');
            })
            ->where('event_type', self::ACCESS_POLICY_CHANGED_EVENT)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id');

        if (($filters['scope'] ?? null) === 'hub' || ($filters['scope'] ?? null) === 'user') {
            $query->where('properties->scope', $filters['scope']);
        }
        if (! empty($filters['user_id'])) {
            $query->where('properties->target_user_id', (int) $filters['user_id']);
        }

        return $query->paginate(max(1, min(100, (int) ($filters['per_page'] ?? 25))));
    }

    /**
     * Blocked / would-block access attempts for the Access Control "Blocked attempts" tab.
     * These live in security_events (not activity_logs / not policy-change History).
     *
     * @param  array{per_page?: int|null, event_type?: string|null, q?: string|null}  $filters
     */
    public function listAccessBlockedAttempts(Hub $hub, User $actor, array $filters = []): LengthAwarePaginator
    {
        $this->ensureSchema();
        $this->assertModuleEnabled($hub);
        $this->assertCan($hub, $actor, 'secmon_manage_access');

        $types = self::ACCESS_BLOCKED_ATTEMPT_EVENTS;
        if (! empty($filters['event_type']) && in_array($filters['event_type'], $types, true)) {
            $types = [$filters['event_type']];
        }

        $query = SecurityEvent::query()
            ->where(function ($q) use ($hub) {
                $q->where('hub_id', $hub->id)->orWhereNull('hub_id');
            })
            ->whereIn('event_type', $types)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id');

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $like = '%'.$q.'%';
            $query->where(function ($inner) use ($like) {
                $inner->where('description', 'like', $like)
                    ->orWhere('title', 'like', $like)
                    ->orWhere('source_ip', 'like', $like)
                    ->orWhere('actor_email', 'like', $like)
                    ->orWhere('actor_name', 'like', $like)
                    ->orWhere('properties->country', 'like', $like)
                    ->orWhere('properties->city', 'like', $like)
                    ->orWhere('properties->user_email', 'like', $like);
            });
        }

        return $query->paginate(max(1, min(100, (int) ($filters['per_page'] ?? 25))));
    }

    /**
     * Preview whether proposed settings would block the actor's current IP.
     *
     * @param  array<string, mixed>  $proposedApi
     * @return array<string, mixed>
     */
    public function previewSelfLockout(
        Hub $hub,
        User $actor,
        array $proposedApi,
        string $scope,
        ?User $target,
        Request $request
    ): array {
        $this->assertModuleEnabled($hub);
        $this->assertCan($hub, $actor, 'secmon_manage_access');

        $access = app(AccessRestrictionService::class);
        $proposed = $access->settingsFromApiArray($this->accessPolicySnapshot($proposedApi));

        if ($scope === 'user') {
            $hubSettings = $access->normalizeFromModel($this->settingsFor($hub));
            $merged = $access->mergeSettings($hubSettings, $proposed);
        } else {
            $userSettings = null;
            if ($actor) {
                $row = $this->userAccessSettingsFor($hub, $actor);
                if ($row->exists) {
                    $userSettings = $access->normalizeFromUserModel($row);
                }
            }
            $merged = $access->mergeSettings($proposed, $userSettings);
        }

        $ip = (string) $request->ip();
        $decision = $access->decideWithSettings($merged, $ip, $request);
        $result = 'pass';
        if (! empty($decision['denied'])) {
            $result = 'block';
        } elseif (! empty($decision['would_block'])) {
            $result = 'would_block';
        }

        return [
            'result' => $result,
            'denied' => (bool) ($decision['denied'] ?? false),
            'would_block' => (bool) ($decision['would_block'] ?? false),
            'reason' => (string) ($decision['reason'] ?? 'allowed'),
            'reason_label' => AccessRestrictionService::reasonLabel((string) ($decision['reason'] ?? 'allowed')),
            'mode' => (string) ($decision['mode'] ?? 'enforce'),
            'ip' => $ip,
            'country' => $decision['country'] ?? null,
            'city' => $decision['city'] ?? null,
            'vpn' => $decision['vpn'] ?? null,
            'scope' => $scope,
            'target_user_id' => $target?->id,
        ];
    }

    /**
     * Restore hub/user access rules from a history event snapshot (before|after).
     *
     * @return SecurityMonitoringSetting|\App\Models\UserAccessRestrictionSetting
     */
    public function restoreAccessPolicyChange(
        Hub $hub,
        User $actor,
        SecurityEvent $event,
        string $snapshot = 'before',
        ?Request $request = null
    ): SecurityMonitoringSetting|\App\Models\UserAccessRestrictionSetting {
        $this->ensureSchema();
        $this->assertModuleEnabled($hub);
        $this->assertCan($hub, $actor, 'secmon_manage_access');
        $this->assertEventOnHub($hub, $event);

        if ($event->event_type !== self::ACCESS_POLICY_CHANGED_EVENT) {
            abort(422, 'Only access policy change events can be restored.');
        }

        $props = (array) ($event->properties ?? []);
        $which = strtolower(trim($snapshot)) === 'after' ? 'after' : 'before';
        $data = (array) ($props[$which] ?? []);
        if ($data === []) {
            abort(422, 'This history entry has no '.$which.' snapshot to restore.');
        }

        $scope = (string) ($props['scope'] ?? 'hub');
        if ($scope === 'user') {
            $targetId = (int) ($props['target_user_id'] ?? 0);
            $target = User::query()->findOrFail($targetId);

            return $this->updateUserAccessSettings($hub, $actor, $target, $data, $request);
        }

        return $this->updateAccessSettings($hub, $actor, $data, $request);
    }

    /**
     * Emergency unlock: disable blocking gates (or switch to monitor) for a hub.
     *
     * @return array{hub_updated: bool, user_rows: int, keep_rules: bool}
     */
    public function emergencyUnlockAccess(
        Hub $hub,
        User $actor,
        ?Request $request = null,
        bool $keepRules = false
    ): array {
        $this->ensureSchema();
        $this->assertModuleEnabled($hub);
        $this->assertCan($hub, $actor, 'secmon_manage_access');

        $settings = $this->settingsFor($hub);
        $before = $this->accessPolicySnapshot($settings->toAccessRestrictionApiArray());

        $payload = [];
        if (\Illuminate\Support\Facades\Schema::hasColumn($settings->getTable(), 'access_enforcement_mode')) {
            $payload['access_enforcement_mode'] = 'monitor';
        }
        if (! $keepRules) {
            $payload = array_merge($payload, [
                'access_uk_only' => false,
                'access_uk_cities_enabled' => false,
                'access_restrict_countries_enabled' => false,
                'access_allow_countries_enabled' => false,
                'access_restrict_ips_enabled' => false,
                'access_allow_ips_enabled' => false,
                'access_restrict_vpn_enabled' => false,
                'access_allow_vpn_enabled' => false,
            ]);
        }
        if (\Illuminate\Support\Facades\Schema::hasColumn($settings->getTable(), 'access_restriction_mode')) {
            $payload['access_restriction_mode'] = 'off';
        }

        $settings->fill($payload);
        $settings->save();
        app(AccessRestrictionService::class)->forgetSettingsCache((int) $hub->id);

        $userRows = 0;
        if (! $keepRules && \Illuminate\Support\Facades\Schema::hasTable('user_access_restriction_settings')) {
            $userQuery = \App\Models\UserAccessRestrictionSetting::query()->where('hub_id', $hub->id);
            $ids = $userQuery->pluck('user_id');
            $userPayload = [
                'access_uk_only' => false,
                'access_uk_cities_enabled' => false,
                'access_restrict_countries_enabled' => false,
                'access_allow_countries_enabled' => false,
                'access_restrict_ips_enabled' => false,
                'access_allow_ips_enabled' => false,
                'access_restrict_vpn_enabled' => false,
                'access_allow_vpn_enabled' => false,
            ];
            if (\Illuminate\Support\Facades\Schema::hasColumn('user_access_restriction_settings', 'access_enforcement_mode')) {
                $userPayload['access_enforcement_mode'] = 'monitor';
            }
            $userRows = \App\Models\UserAccessRestrictionSetting::query()
                ->where('hub_id', $hub->id)
                ->update($userPayload);
            foreach ($ids as $userId) {
                app(AccessRestrictionService::class)->forgetUserSettingsCache((int) $hub->id, (int) $userId);
            }
        } elseif ($keepRules && \Illuminate\Support\Facades\Schema::hasTable('user_access_restriction_settings')
            && \Illuminate\Support\Facades\Schema::hasColumn('user_access_restriction_settings', 'access_enforcement_mode')) {
            $userRows = \App\Models\UserAccessRestrictionSetting::query()
                ->where('hub_id', $hub->id)
                ->update(['access_enforcement_mode' => 'monitor']);
        }

        $after = $this->accessPolicySnapshot($settings->fresh()->toAccessRestrictionApiArray());
        $this->recordAccessPolicyChange(
            $hub,
            $actor,
            $request,
            scope: 'hub',
            before: $before,
            after: $after,
            target: null
        );

        try {
            $this->recordEvent($hub, [
                'category' => SecurityEvent::CATEGORY_AUTHORIZATION,
                'event_type' => self::ACCESS_EMERGENCY_UNLOCK_EVENT,
                'severity' => SecurityEvent::SEVERITY_MEDIUM,
                'title' => 'Emergency access unlock',
                'description' => sprintf(
                    '%s used emergency unlock (%s) for hub #%s.',
                    $actor->email ?? 'admin',
                    $keepRules ? 'monitor only' : 'clear blocking gates',
                    $hub->id
                ),
                'actor' => $actor,
                'request' => $request,
                'status' => SecurityEvent::STATUS_CLOSED,
                'properties' => [
                    'keep_rules' => $keepRules,
                    'user_rows_updated' => $userRows,
                ],
            ]);
        } catch (\Throwable) {
            //
        }

        return [
            'hub_updated' => true,
            'user_rows' => $userRows,
            'keep_rules' => $keepRules,
            'settings' => $settings->fresh()->toAccessRestrictionApiArray(),
        ];
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private function recordAccessPolicyChange(
        Hub $hub,
        User $actor,
        ?Request $request,
        string $scope,
        array $before,
        array $after,
        ?User $target
    ): void {
        $changes = $this->accessPolicyDiff($before, $after);
        if ($changes === []) {
            return;
        }

        $changedKeys = array_keys($changes);
        $summary = implode(', ', array_map(
            function (string $key) use ($changes): string {
                $from = $this->formatAccessPolicyValue($changes[$key]['from'] ?? null);
                $to = $this->formatAccessPolicyValue($changes[$key]['to'] ?? null);

                return $key.': '.$from.' → '.$to;
            },
            array_slice($changedKeys, 0, 6)
        ));
        if (count($changedKeys) > 6) {
            $summary .= ' (+'.(count($changedKeys) - 6).' more)';
        }

        $title = $scope === 'user'
            ? 'User access rules updated'
            : 'Hub access rules updated';

        $description = $scope === 'user'
            ? sprintf(
                '%s updated access rules for %s (%s). Changes: %s.',
                $actor->email ?? 'admin',
                $target?->email ?? 'user',
                $target?->id ?? '?',
                $summary
            )
            : sprintf(
                '%s updated hub access rules. Changes: %s.',
                $actor->email ?? 'admin',
                $summary
            );

        try {
            $this->recordEvent($hub, [
                'category' => SecurityEvent::CATEGORY_AUTHORIZATION,
                'event_type' => self::ACCESS_POLICY_CHANGED_EVENT,
                'severity' => SecurityEvent::SEVERITY_INFO,
                'title' => $title,
                'description' => $description,
                'actor' => $actor,
                'request' => $request,
                'status' => SecurityEvent::STATUS_CLOSED,
                'related_refs' => array_filter([
                    'scope' => $scope,
                    'target_user_id' => $target?->id,
                ]),
                'properties' => [
                    'scope' => $scope,
                    'target_user_id' => $target?->id,
                    'target_user_email' => $target?->email,
                    'target_user_name' => $target?->name,
                    'changed_keys' => $changedKeys,
                    'changes' => $changes,
                    'before' => $before,
                    'after' => $after,
                    'change_summary' => $summary,
                ],
            ]);
        } catch (\Throwable) {
            // Audit must not block saving settings.
        }
    }

    /**
     * @param  array<string, mixed>  $api
     * @return array<string, mixed>
     */
    private function accessPolicySnapshot(array $api): array
    {
        $keys = [
            'uk_only',
            'uk_cities_enabled',
            'uk_cities',
            'restrict_countries_enabled',
            'restricted_countries',
            'restricted_cities',
            'allow_countries_enabled',
            'allowed_countries',
            'allowed_cities',
            'restrict_ips_enabled',
            'restricted_ips',
            'allow_ips_enabled',
            'allowed_ips',
            'restrict_vpn_enabled',
            'allow_vpn_enabled',
            'enforcement_mode',
        ];

        $out = [];
        foreach ($keys as $key) {
            $value = $api[$key] ?? null;
            if (is_array($value)) {
                $value = array_values(array_map(
                    fn ($v) => is_string($v) ? trim($v) : $v,
                    $value
                ));
                sort($value);
            } elseif (is_bool($value) || $value === 0 || $value === 1 || $value === '0' || $value === '1') {
                $value = (bool) $value;
            } elseif (is_string($value)) {
                $value = trim($value);
            }
            $out[$key] = $value;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array<string, array{from: mixed, to: mixed}>
     */
    private function accessPolicyDiff(array $before, array $after): array
    {
        $changes = [];
        foreach ($before as $key => $from) {
            $to = $after[$key] ?? null;
            if ($this->accessPolicyValuesEqual($from, $to)) {
                continue;
            }
            $changes[$key] = ['from' => $from, 'to' => $to];
        }

        return $changes;
    }

    private function accessPolicyValuesEqual(mixed $a, mixed $b): bool
    {
        return json_encode($a) === json_encode($b);
    }

    private function formatAccessPolicyValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_array($value)) {
            return $value === [] ? '[]' : implode(', ', array_map('strval', $value));
        }
        if ($value === null || $value === '') {
            return '—';
        }

        return (string) $value;
    }

    public function userAccessSettingsFor(Hub $hub, User $target): \App\Models\UserAccessRestrictionSetting
    {
        return \App\Models\UserAccessRestrictionSetting::query()->firstOrNew(
            ['hub_id' => $hub->id, 'user_id' => $target->id]
        );
    }

    /**
     * Validate access-control API input (throws ValidationException on bad rules).
     *
     * @param  array<string, mixed>  $data
     */
    public function validateAccessSettingsInput(array $data): void
    {
        $this->validatedAccessPayload($data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validatedAccessPayload(array $data): array
    {
        $geoIp = app(GeoIpLookupService::class);

        $ukOnly = (bool) ($data['uk_only'] ?? false);
        $ukCitiesEnabled = (bool) ($data['uk_cities_enabled'] ?? false);
        $restrictCountriesEnabled = (bool) ($data['restrict_countries_enabled'] ?? false);
        $allowCountriesEnabled = (bool) ($data['allow_countries_enabled'] ?? false);
        $restrictIpsEnabled = (bool) ($data['restrict_ips_enabled'] ?? false);
        $allowIpsEnabled = (bool) ($data['allow_ips_enabled'] ?? false);
        $restrictVpnEnabled = (bool) ($data['restrict_vpn_enabled'] ?? false);
        $allowVpnEnabled = (bool) ($data['allow_vpn_enabled'] ?? false);
        $enforcementMode = \App\Models\SecurityMonitoringSetting::normalizedEnforcementMode(
            $data['enforcement_mode'] ?? 'enforce'
        );

        $cities = array_values(array_unique(array_filter(array_map(
            fn ($c) => $geoIp->normalizeCity($c),
            (array) ($data['uk_cities'] ?? $data['cities'] ?? [])
        ))));
        $restrictedCountries = array_values(array_unique(array_filter(array_map(
            fn ($c) => $geoIp->normalizeCountry($c),
            (array) ($data['restricted_countries'] ?? [])
        ))));
        $allowedCountries = array_values(array_unique(array_filter(array_map(
            fn ($c) => $geoIp->normalizeCountry($c),
            (array) ($data['allowed_countries'] ?? [])
        ))));
        $restrictedCities = array_values(array_unique(array_filter(array_map(
            fn ($c) => $geoIp->normalizeCity($c),
            (array) ($data['restricted_cities'] ?? [])
        ))));
        $allowedCities = array_values(array_unique(array_filter(array_map(
            fn ($c) => $geoIp->normalizeCity($c),
            (array) ($data['allowed_cities'] ?? [])
        ))));
        $restrictedIps = array_values(array_unique(array_filter(array_map(
            fn ($ip) => trim((string) $ip),
            (array) ($data['restricted_ips'] ?? [])
        ), fn ($ip) => $ip !== '')));
        $allowedIps = array_values(array_unique(array_filter(array_map(
            fn ($ip) => trim((string) $ip),
            (array) ($data['allowed_ips'] ?? [])
        ), fn ($ip) => $ip !== '')));

        foreach (array_merge($restrictedIps, $allowedIps) as $ip) {
            if (! IpAddressMatcher::isValidEntry($ip)) {
                throw ValidationException::withMessages([
                    'ips' => "Invalid IP, CIDR, or range: {$ip}",
                ]);
            }
        }

        foreach ($restrictedIps as $restricted) {
            foreach ($allowedIps as $allowed) {
                if (IpAddressMatcher::entriesOverlap($restricted, $allowed)) {
                    throw ValidationException::withMessages([
                        'ips' => "IP conflict: {$restricted} cannot appear in both Restricted IPs and Allowed IPs (overlaps {$allowed}).",
                    ]);
                }
            }
        }

        $countryOverlap = array_values(array_intersect($restrictedCountries, $allowedCountries));
        if ($countryOverlap !== []) {
            throw ValidationException::withMessages([
                'countries' => 'Country conflict: '.implode(', ', $countryOverlap).' cannot be in both Restricted and Allowed countries.',
            ]);
        }

        $restrictedCityKeys = array_map(fn ($c) => mb_strtolower((string) $c), $restrictedCities);
        $allowedCityKeys = array_map(fn ($c) => mb_strtolower((string) $c), $allowedCities);
        $cityOverlapKeys = array_values(array_intersect($restrictedCityKeys, $allowedCityKeys));
        if ($cityOverlapKeys !== []) {
            throw ValidationException::withMessages([
                'cities' => 'City conflict: '.implode(', ', $cityOverlapKeys).' cannot be in both Restricted and Allowed cities.',
            ]);
        }

        if (($ukOnly || ($ukCitiesEnabled && $cities !== [])) && in_array('GB', $restrictedCountries, true)) {
            throw ValidationException::withMessages([
                'countries' => 'Country conflict: GB cannot be Restricted while UK only / Specific UK cities is enabled.',
            ]);
        }

        if ($ukCitiesEnabled && $cities === []) {
            throw ValidationException::withMessages([
                'uk_cities' => 'Select at least one UK city when Specific UK cities is enabled.',
            ]);
        }
        if ($restrictCountriesEnabled && $restrictedCountries === [] && $restrictedCities === []) {
            throw ValidationException::withMessages([
                'restricted_countries' => 'Add at least one country or city to restrict.',
            ]);
        }
        if ($allowCountriesEnabled && $allowedCountries === [] && $allowedCities === []) {
            throw ValidationException::withMessages([
                'allowed_countries' => 'Add at least one country or city to allow.',
            ]);
        }
        if ($restrictIpsEnabled && $restrictedIps === []) {
            throw ValidationException::withMessages([
                'restricted_ips' => 'Add at least one IP, CIDR, or range to restrict.',
            ]);
        }
        if ($allowIpsEnabled && $allowedIps === []) {
            throw ValidationException::withMessages([
                'allowed_ips' => 'Add at least one IP, CIDR, or range to allow.',
            ]);
        }

        return [
            'access_uk_only' => $ukOnly,
            'access_uk_cities_enabled' => $ukCitiesEnabled,
            'access_restriction_cities' => $cities,
            'access_restrict_countries_enabled' => $restrictCountriesEnabled,
            'access_allow_countries_enabled' => $allowCountriesEnabled,
            'access_restricted_countries' => $restrictedCountries,
            'access_restricted_cities' => $restrictedCities,
            'access_allowed_countries' => $allowedCountries,
            'access_allowed_cities' => $allowedCities,
            'access_restrict_ips_enabled' => $restrictIpsEnabled,
            'access_allow_ips_enabled' => $allowIpsEnabled,
            'access_restricted_ips' => $restrictedIps,
            'access_allowed_ips' => $allowedIps,
            'access_restrict_vpn_enabled' => $restrictVpnEnabled,
            'access_allow_vpn_enabled' => $allowVpnEnabled,
            'access_enforcement_mode' => $enforcementMode,
        ];
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
            $this->ensureSchema();
        } catch (\Throwable) {
            return null;
        }

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
            try {
                app(ModuleDisasterRecoveryService::class)->autoRecognizeFromIncident($hub, $incident, $event);
            } catch (\Throwable) {
                // Disaster recognition must not break event recording.
            }
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
        $this->ensureSchema();

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
        $this->ensureSchema();

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
     *   affected_module?: string|null,
     *   recognize_disaster?: bool,
     *   event_ids?: list<int>
     * }  $data
     */
    public function declareIncident(Hub $hub, User $actor, array $data, ?Request $request = null): SecurityIncident
    {
        $this->assertModuleEnabled($hub);
        $this->assertCan($hub, $actor, 'secmon_manage_incidents');

        $affectedModule = isset($data['affected_module']) && filled($data['affected_module'])
            ? (string) $data['affected_module']
            : null;
        if ($affectedModule && ! app(ModuleDisasterRecoveryService::class)->isRecoverableModule($affectedModule)) {
            throw new \InvalidArgumentException('affected_module is not a recoverable product module.');
        }

        $incident = SecurityIncident::query()->create([
            'hub_id' => $hub->id,
            'title' => mb_substr($data['title'], 0, 255),
            'description' => $data['description'] ?? null,
            'category' => $data['category'] ?? SecurityEvent::CATEGORY_MANUAL,
            'affected_module' => $affectedModule,
            'severity' => $data['severity'] ?? SecurityEvent::SEVERITY_MEDIUM,
            'status' => SecurityIncident::STATUS_OPEN,
            'recovery_status' => SecurityIncident::RECOVERY_NONE,
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
            'related_refs' => [
                'security_incident_id' => $incident->id,
                'module_key' => $affectedModule,
            ],
            'status' => SecurityEvent::STATUS_ACKNOWLEDGED,
        ]);

        if (! empty($data['recognize_disaster']) && $affectedModule) {
            $incident = app(ModuleDisasterRecoveryService::class)
                ->recognizeDisaster($hub, $incident, $affectedModule, $actor, $request);
        } elseif ($affectedModule) {
            try {
                app(ModuleDisasterRecoveryService::class)->autoRecognizeFromIncident($hub, $incident);
            } catch (\Throwable) {
                //
            }
            $incident = $incident->fresh();
        }

        try {
            $this->activityLogs->log([
                'action' => 'secmon.incident_declare',
                'description' => 'Declared security incident #'.$incident->id.': '.$incident->title,
                'user' => $actor,
                'request' => $request,
                'status_code' => 201,
                'properties' => [
                    'incident_id' => $incident->id,
                    'affected_module' => $affectedModule,
                ],
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
        $this->ensureSchema();

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

        // Use DATE(...) in GROUP BY for MySQL ONLY_FULL_GROUP_BY compatibility.
        $byDay = (clone $base)
            ->select(DB::raw('DATE(occurred_at) as day'), DB::raw('count(*) as total'))
            ->groupBy(DB::raw('DATE(occurred_at)'))
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
            ->select('actor_email', DB::raw('MAX(actor_name) as actor_name'), DB::raw('count(*) as total'))
            ->groupBy('actor_email')
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
     * @return array{
     *   categories: list<array{key: string, label: string}>,
     *   severities: list<string>,
     *   statuses: list<string>,
     *   event_statuses: list<string>,
     *   access_restriction_rules: list<array{key: string, label: string, description: string}>,
     *   suggested_uk_cities: list<string>
     * }
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
            'recovery_statuses' => SecurityIncident::RECOVERY_STATUSES,
            'recoverable_modules' => app(ModuleDisasterRecoveryService::class)->recoverableModules(),
            'access_restriction_rules' => AccessRestrictionService::ruleOptions(),
            'suggested_uk_cities' => AccessRestrictionService::SUGGESTED_UK_CITIES,
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

        // NFR-SEC-FW: optional temporary application-layer IP block after threshold.
        if ($ip && config('security_firewall.auto_block_on_failed_login_threshold', false)) {
            try {
                app(FirewallService::class)->blockIpTemporarily(
                    $ip,
                    null,
                    $request,
                    sprintf(
                        'Auto-blocked after %d failed logins within %d minutes.',
                        $count,
                        $window
                    )
                );
            } catch (\Throwable) {
                // Firewall must not break auth monitoring.
            }
        }
    }

    private function createIncidentFromEvent(Hub $hub, SecurityEvent $event): SecurityIncident
    {
        $module = app(ModuleDisasterRecoveryService::class)->inferModuleFromEvent($event);

        return SecurityIncident::query()->create([
            'hub_id' => $hub->id,
            'title' => $event->title,
            'description' => $event->description,
            'category' => $event->category,
            'affected_module' => $module,
            'severity' => $event->severity,
            'status' => SecurityIncident::STATUS_OPEN,
            'recovery_status' => SecurityIncident::RECOVERY_NONE,
            'declared_by_name' => 'System',
        ]);
    }

    /**
     * @return array{status: string, summary: string, advisories: int}
     */
    private function executeComposerAudit(): array
    {
        $php = $this->phpCliBinary();
        $composerPhar = base_path('composer.phar');

        if (! is_file($composerPhar)) {
            $which = $this->findComposerBinary();
            if (! $which) {
                return [
                    'status' => 'error',
                    'summary' => 'Composer is not available on this server (composer.phar / composer binary missing).',
                    'advisories' => 0,
                ];
            }
            $composerPhar = str_ends_with(strtolower($which), '.phar') ? $which : null;
            $composerBin = $composerPhar ? null : $which;
        } else {
            $composerBin = null;
        }

        // Try array argv first (Symfony Process escapes spaces). Then a Windows cmd line.
        $attempts = [];
        if ($composerPhar) {
            $attempts[] = [$php, $composerPhar, 'audit', '--format=json', '--no-dev', '--no-ansi', '-n'];
            $attempts[] = $this->windowsCmdAuditLine($php, $composerPhar);
        }
        if ($composerBin) {
            $attempts[] = [$composerBin, 'audit', '--format=json', '--no-dev', '--no-ansi', '-n'];
        }

        $raw = '';
        $exit = 1;
        $lastRaw = '';
        $lastExit = 1;
        foreach ($attempts as $cmd) {
            if ($cmd === null || $cmd === [] || $cmd === '') {
                continue;
            }
            [$raw, $exit] = $this->runComposerCommand($cmd);
            $lastRaw = $raw;
            $lastExit = $exit;
            // Keep trying until we get real audit JSON (skip help banners / bad quoting errors).
            if ($this->composerOutputLooksLikeAuditJson($raw)) {
                break;
            }
            $raw = '';
            $exit = 1;
        }
        if ($raw === '') {
            $raw = $lastRaw;
            $exit = $lastExit;
        }

        $jsonStart = strpos($raw, '{');
        $decoded = null;
        if ($jsonStart !== false) {
            $decoded = json_decode(substr($raw, $jsonStart), true);
        }

        if (! is_array($decoded)) {
            if ($this->composerOutputLooksLikeHelpBanner($raw)) {
                return [
                    'status' => 'error',
                    'summary' => 'Composer audit did not run (help banner only — CLI argv not passed). Set PHP_CLI_BINARY in .env to your php.exe path (not php-cgi), e.g. C:\\laragon\\bin\\php\\php-8.3.0\\php.exe',
                    'advisories' => 0,
                ];
            }

            if (trim($raw) === '') {
                return [
                    'status' => 'unavailable',
                    'summary' => 'Composer audit could not run in this environment (no output). Set PHP_CLI_BINARY in .env to a working CLI php.exe.',
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

    /**
     * @param  list<string>|string  $cmd
     * @return array{0: string, 1: int}
     */
    private function runComposerCommand(array|string $cmd): array
    {
        $home = $this->composerHomeDirectory();
        $env = $this->composerProcessEnvironment($home);

        try {
            $process = is_array($cmd)
                ? new Process($cmd, base_path(), $env, null, 180)
                : Process::fromShellCommandline($cmd, base_path(), $env, null, 180);
            $process->run();

            $raw = trim($process->getOutput()."\n".$process->getErrorOutput());

            return [$raw, $process->getExitCode() ?? 1];
        } catch (\Throwable $e) {
            return [mb_substr($e->getMessage(), 0, 500), 1];
        }
    }

    /**
     * Windows cmd.exe line so argv reaches composer.phar reliably from web SAPIs.
     */
    private function windowsCmdAuditLine(string $php, string $composerPhar): ?string
    {
        if (DIRECTORY_SEPARATOR !== '\\') {
            return null;
        }

        // cmd.exe requires the special ""quoted exe"" form when paths contain spaces.
        return sprintf(
            'cmd.exe /C ""%s" "%s" audit --format=json --no-dev --no-ansi -n"',
            str_replace('"', '', $php),
            str_replace('"', '', $composerPhar)
        );
    }

    private function composerOutputLooksLikeHelpBanner(string $raw): bool
    {
        return str_contains($raw, 'Composer version')
            && (str_contains($raw, 'Usage: command') || str_contains($raw, 'Available commands'));
    }

    private function composerOutputLooksLikeAuditJson(string $raw): bool
    {
        $jsonStart = strpos($raw, '{');
        if ($jsonStart === false) {
            return false;
        }
        $decoded = json_decode(substr($raw, $jsonStart), true);

        return is_array($decoded) && (array_key_exists('advisories', $decoded) || array_key_exists('abandoned', $decoded));
    }

    /**
     * @return array<string, string>
     */
    private function composerProcessEnvironment(string $home): array
    {
        $env = [];

        // Keep a minimal inherited PATH — do not dump $_SERVER (CGI pollution).
        foreach (['PATH', 'Path', 'SystemRoot', 'SYSTEMROOT', 'PATHEXT', 'TMP', 'TEMP', 'APPDATA', 'LOCALAPPDATA', 'ComSpec', 'COMSPEC'] as $key) {
            $val = getenv($key);
            if (is_string($val) && $val !== '') {
                $env[$key] = $val;
            }
        }

        $env['HOME'] = $home;
        $env['COMPOSER_HOME'] = $home;
        $env['USERPROFILE'] = $home;
        $env['COMPOSER_DISABLE_XDEBUG_WARN'] = '1';

        return $env;
    }

    private function composerHomeDirectory(): string
    {
        $dir = storage_path('app/composer-home');
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        return $dir;
    }

    /**
     * Resolve a verified CLI php.exe (never php-cgi).
     */
    private function phpCliBinary(): string
    {
        $candidates = [];

        $configured = env('PHP_CLI_BINARY') ?: env('PHP_BINARY_CLI');
        if (is_string($configured) && $configured !== '') {
            $candidates[] = $configured;
        }

        $binary = is_string(PHP_BINARY) ? PHP_BINARY : '';
        if ($binary !== '') {
            $dir = dirname($binary);
            if (stripos($binary, 'cgi') !== false) {
                $candidates[] = preg_replace('/php[\w.-]*cgi/i', 'php', $binary) ?: '';
                $candidates[] = $dir.DIRECTORY_SEPARATOR.'php.exe';
                $candidates[] = $dir.DIRECTORY_SEPARATOR.'php';
            } elseif (in_array(PHP_SAPI, ['cli', 'phpdbg'], true)) {
                $candidates[] = $binary;
            } else {
                // apache2handler etc. — PHP_BINARY may be httpd; look beside common PHP dirs later.
                $candidates[] = $dir.DIRECTORY_SEPARATOR.'php.exe';
            }
        }

        foreach (['php.exe', 'php'] as $name) {
            $found = $this->whichExecutable($name);
            if ($found) {
                $candidates[] = $found;
            }
        }

        $candidates[] = 'php.exe';
        $candidates[] = 'php';

        $candidates = array_values(array_unique(array_filter($candidates)));

        foreach ($candidates as $candidate) {
            if ($candidate !== 'php' && $candidate !== 'php.exe' && ! is_file($candidate)) {
                continue;
            }
            if ($this->isCliPhpBinary($candidate)) {
                return $candidate;
            }
        }

        return $candidates[0] ?? 'php';
    }

    private function isCliPhpBinary(string $binary): bool
    {
        try {
            $process = new Process([$binary, '-r', 'echo PHP_SAPI;'], base_path(), $this->composerProcessEnvironment($this->composerHomeDirectory()), null, 10);
            $process->run();
            $sapi = trim($process->getOutput());

            return in_array($sapi, ['cli', 'phpdbg'], true);
        } catch (\Throwable) {
            return false;
        }
    }

    private function findComposerBinary(): ?string
    {
        foreach (['composer.bat', 'composer.cmd', 'composer', 'composer.phar'] as $name) {
            $path = $this->whichExecutable($name);
            if ($path) {
                return $path;
            }
        }

        return null;
    }

    private function whichExecutable(string $name): ?string
    {
        try {
            $cmd = DIRECTORY_SEPARATOR === '\\'
                ? ['cmd', '/C', 'where', $name]
                : ['sh', '-lc', 'command -v '.escapeshellarg($name)];
            $process = new Process($cmd, null, $this->composerProcessEnvironment($this->composerHomeDirectory()), null, 10);
            $process->run();
            if (! $process->isSuccessful()) {
                return null;
            }
            $path = trim(explode("\n", str_replace("\r", '', $process->getOutput()))[0] ?? '');

            return $path !== '' ? $path : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function scanIsOverdue(SecurityMonitoringSetting $settings): bool
    {
        if (! $settings->last_dependency_scan_at) {
            return true;
        }

        return $settings->last_dependency_scan_at->lt(now()->subDays(7));
    }

    public function assertCan(Hub $hub, User $actor, string $capability): void
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

    public function assertIncidentOnHub(Hub $hub, SecurityIncident $incident): void
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
