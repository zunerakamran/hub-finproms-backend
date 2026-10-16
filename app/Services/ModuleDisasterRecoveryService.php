<?php

namespace App\Services;

use App\Models\Hub;
use App\Models\ModuleDisasterSnapshot;
use App\Models\SecurityEvent;
use App\Models\SecurityIncident;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Module-scoped disaster recovery: recognize via Security Monitoring, then
 * snapshot / roll back only the affected product module (not whole-hub restore).
 */
class ModuleDisasterRecoveryService
{
    public const RECOVERY_NONE = 'none';

    public const RECOVERY_RECOGNIZED = 'recognized';

    public const RECOVERY_SNAPSHOT_TAKEN = 'snapshot_taken';

    public const RECOVERY_ROLLED_BACK = 'rolled_back';

    public const RECOVERY_FAILED = 'failed';

    /**
     * Product modules that support data snapshot + rollback.
     *
     * @var array<string, list<string>>
     */
    public const MODULE_TABLES = [
        'module_general_compliance' => [
            'general_compliance_content_types',
            'general_compliance_requests',
            'general_compliance_request_versions',
            'general_compliance_request_attachments',
        ],
        'module_social_media_compliance' => [
            'social_media_compliance_requests',
            'social_media_compliance_request_versions',
            'social_media_compliance_request_attachments',
        ],
        'module_website_compliance' => [
            'wc_change_requests',
            'wc_change_request_versions',
            'wc_change_request_attachments',
        ],
        'module_website_template_library' => [
            'wc_templates',
            'wc_template_requests',
            'wc_pages',
            'wc_sections',
        ],
        'module_social_media_template_library' => [
            'posts',
            'types',
            'categories',
            'tags',
            'bundles',
        ],
    ];

    public function __construct(
        private readonly HubService $hubs,
        private readonly ActivityLogService $activityLogs,
    ) {}

    private function securityMonitoring(): SecurityMonitoringService
    {
        return app(SecurityMonitoringService::class);
    }

    /**
     * @return list<array{key: string, label: string, description: string, tables: list<string>}>
     */
    public function recoverableModules(): array
    {
        $out = [];
        foreach (self::MODULE_TABLES as $key => $tables) {
            $def = Hub::CHECKLIST_DEFINITIONS[$key] ?? [];
            $out[] = [
                'key' => $key,
                'label' => $def['label'] ?? $key,
                'description' => $def['description'] ?? '',
                'tables' => $tables,
            ];
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    public function recoverableModuleKeys(): array
    {
        return array_keys(self::MODULE_TABLES);
    }

    public function isRecoverableModule(string $moduleKey): bool
    {
        return isset(self::MODULE_TABLES[$moduleKey]);
    }

    /**
     * Infer product module from a security event route / type / refs.
     */
    public function inferModuleFromEvent(?SecurityEvent $event): ?string
    {
        if (! $event) {
            return null;
        }

        $refs = is_array($event->related_refs) ? $event->related_refs : [];
        if (! empty($refs['module_key']) && $this->isRecoverableModule((string) $refs['module_key'])) {
            return (string) $refs['module_key'];
        }
        if (! empty($refs['affected_module']) && $this->isRecoverableModule((string) $refs['affected_module'])) {
            return (string) $refs['affected_module'];
        }

        $haystack = strtolower(implode(' ', array_filter([
            (string) $event->route,
            (string) $event->event_type,
            (string) $event->title,
            (string) $event->description,
        ])));

        $map = [
            'module_general_compliance' => [
                'general-compliance', 'general_compliance', '/gc/', 'gc_',
            ],
            'module_social_media_compliance' => [
                'social-media-compliance', 'social_media_compliance', '/smc/', 'smc_',
            ],
            'module_website_compliance' => [
                'website-compliance', 'website_compliance', '/wc/', 'wc_change', 'change-request',
            ],
            'module_website_template_library' => [
                'website-template', 'wc_template', 'template-library',
            ],
            'module_social_media_template_library' => [
                'social-media-template', 'posts/', '/posts', 'reels', 'bundles',
            ],
        ];

        foreach ($map as $module => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($haystack, $needle)) {
                    return $module;
                }
            }
        }

        return null;
    }

    /**
     * When monitoring opens a high/critical incident, mark disaster for the module.
     */
    public function autoRecognizeFromIncident(Hub $hub, SecurityIncident $incident, ?SecurityEvent $event = null): SecurityIncident
    {
        $module = $incident->affected_module
            ?: $this->inferModuleFromEvent($event);

        if (! $module || ! $this->isRecoverableModule($module)) {
            return $incident;
        }

        $rank = SecurityEvent::SEVERITY_RANK[$incident->severity] ?? 0;
        $highEnough = $rank >= (SecurityEvent::SEVERITY_RANK[SecurityEvent::SEVERITY_HIGH] ?? 3);

        if (! $highEnough && ! $incident->disaster_recognized) {
            // Still attach the inferred module for operator visibility.
            if (! $incident->affected_module) {
                $incident->affected_module = $module;
                $incident->save();
            }

            return $incident->fresh();
        }

        return $this->recognizeDisaster($hub, $incident, $module, null, null, auto: true);
    }

    /**
     * @return list<ModuleDisasterSnapshot>
     */
    public function listSnapshots(Hub $hub, ?string $moduleKey = null, int $limit = 50): array
    {
        $query = ModuleDisasterSnapshot::query()
            ->where('hub_id', $hub->id)
            ->orderByDesc('id')
            ->limit(max(1, min(100, $limit)));

        if ($moduleKey) {
            $query->where('module_key', $moduleKey);
        }

        return $query->get()->all();
    }

    public function createSnapshot(
        Hub $hub,
        string $moduleKey,
        ?User $actor = null,
        string $trigger = ModuleDisasterSnapshot::TRIGGER_MANUAL,
        ?string $label = null,
        ?SecurityIncident $incident = null,
    ): ModuleDisasterSnapshot {
        if (! $this->isRecoverableModule($moduleKey)) {
            throw new RuntimeException('Module is not supported for disaster recovery snapshots.');
        }

        $payload = $this->captureModulePayload($hub, $moduleKey);
        $dir = 'module-disaster-snapshots/'.$hub->id.'/'.$moduleKey;
        Storage::disk('local')->makeDirectory($dir);
        $filename = now()->format('Ymd_His').'_'.Str::lower(Str::random(8)).'.json';
        $relative = $dir.'/'.$filename;
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        Storage::disk('local')->put($relative, $json);

        $snapshot = ModuleDisasterSnapshot::query()->create([
            'hub_id' => $hub->id,
            'module_key' => $moduleKey,
            'label' => $label ?: $this->defaultLabel($moduleKey, $trigger),
            'trigger' => $trigger,
            'disk_path' => $relative,
            'size_bytes' => strlen($json),
            'row_counts' => $payload['row_counts'] ?? [],
            'module_enabled' => $payload['module_enabled'] ?? null,
            'security_incident_id' => $incident?->id,
            'created_by_user_id' => $actor?->id,
            'created_by_name' => $actor?->name ?? ($trigger === ModuleDisasterSnapshot::TRIGGER_AUTO_BASELINE ? 'System' : null),
        ]);

        if ($incident) {
            $incident->last_snapshot_id = $snapshot->id;
            if ($incident->recovery_status === self::RECOVERY_RECOGNIZED
                || $incident->recovery_status === self::RECOVERY_NONE
            ) {
                $incident->recovery_status = self::RECOVERY_SNAPSHOT_TAKEN;
            }
            $incident->save();
        }

        try {
            $this->activityLogs->log([
                'action' => 'secmon.disaster_snapshot',
                'description' => 'Created module disaster snapshot #'.$snapshot->id.' for '.$moduleKey,
                'user' => $actor,
                'status_code' => 201,
                'properties' => [
                    'snapshot_id' => $snapshot->id,
                    'module_key' => $moduleKey,
                    'trigger' => $trigger,
                    'incident_id' => $incident?->id,
                ],
            ]);
        } catch (Throwable) {
            //
        }

        return $snapshot;
    }

    public function recognizeDisaster(
        Hub $hub,
        SecurityIncident $incident,
        string $moduleKey,
        ?User $actor = null,
        ?Request $request = null,
        bool $auto = false,
    ): SecurityIncident {
        if ($incident->hub_id && (int) $incident->hub_id !== (int) $hub->id) {
            throw new RuntimeException('Incident does not belong to this hub.');
        }
        if (! $this->isRecoverableModule($moduleKey)) {
            throw new RuntimeException('Select a recoverable product module.');
        }

        $incident->affected_module = $moduleKey;
        $incident->disaster_recognized = true;
        $incident->disaster_recognized_at = $incident->disaster_recognized_at ?: now();
        if (in_array($incident->recovery_status, [self::RECOVERY_NONE, '', null], true)) {
            $incident->recovery_status = self::RECOVERY_RECOGNIZED;
        }
        if ($incident->status === SecurityIncident::STATUS_OPEN) {
            $incident->status = SecurityIncident::STATUS_INVESTIGATING;
        }
        $incident->save();

        // Ensure a baseline snapshot exists so rollback is possible.
        $hasSnapshot = ModuleDisasterSnapshot::query()
            ->where('hub_id', $hub->id)
            ->where('module_key', $moduleKey)
            ->where('trigger', '!=', ModuleDisasterSnapshot::TRIGGER_PRE_ROLLBACK)
            ->exists();

        if (! $hasSnapshot) {
            $this->createSnapshot(
                $hub,
                $moduleKey,
                $actor,
                $auto ? ModuleDisasterSnapshot::TRIGGER_AUTO_BASELINE : ModuleDisasterSnapshot::TRIGGER_RECOGNIZE,
                'Baseline at disaster recognition',
                $incident
            );
            $incident = $incident->fresh();
        }

        $this->securityMonitoring()->recordEvent($hub, [
            'category' => SecurityEvent::CATEGORY_DATA,
            'event_type' => 'secmon.disaster_recognized',
            'severity' => $incident->severity,
            'title' => 'Disaster recognized for module',
            'description' => 'Disaster recognized for '
                .($this->moduleLabel($moduleKey))
                .' via security incident #'.$incident->id
                .($auto ? ' (auto from monitoring).' : '.'),
            'actor' => $actor,
            'request' => $request,
            'related_refs' => [
                'security_incident_id' => $incident->id,
                'module_key' => $moduleKey,
            ],
            'status' => SecurityEvent::STATUS_ACKNOWLEDGED,
        ]);

        try {
            $this->activityLogs->log([
                'action' => 'secmon.disaster_recognize',
                'description' => 'Recognized disaster on incident #'.$incident->id.' for '.$moduleKey,
                'user' => $actor,
                'request' => $request,
                'status_code' => 200,
                'properties' => [
                    'incident_id' => $incident->id,
                    'module_key' => $moduleKey,
                    'auto' => $auto,
                ],
            ]);
        } catch (Throwable) {
            //
        }

        return $incident->fresh()->load(['events' => fn ($q) => $q->orderByDesc('occurred_at'), 'notes']);
    }

    public function rollbackIncident(
        Hub $hub,
        SecurityIncident $incident,
        int $snapshotId,
        ?User $actor = null,
        ?Request $request = null,
    ): array {
        if ($incident->hub_id && (int) $incident->hub_id !== (int) $hub->id) {
            throw new RuntimeException('Incident does not belong to this hub.');
        }
        if (! $incident->disaster_recognized) {
            throw new RuntimeException('Recognize the disaster for a module before rolling back.');
        }

        $moduleKey = (string) $incident->affected_module;
        if (! $this->isRecoverableModule($moduleKey)) {
            throw new RuntimeException('Incident has no recoverable affected module.');
        }

        $snapshot = ModuleDisasterSnapshot::query()->findOrFail($snapshotId);
        if ((int) $snapshot->hub_id !== (int) $hub->id || $snapshot->module_key !== $moduleKey) {
            throw new RuntimeException('Snapshot does not match this hub and affected module.');
        }

        // Preserve current (bad) state before overwrite.
        $pre = $this->createSnapshot(
            $hub,
            $moduleKey,
            $actor,
            ModuleDisasterSnapshot::TRIGGER_PRE_ROLLBACK,
            'Pre-rollback safety copy',
            $incident
        );

        try {
            $this->restoreModuleFromSnapshot($hub, $snapshot);
        } catch (Throwable $e) {
            $incident->recovery_status = self::RECOVERY_FAILED;
            $incident->save();

            $this->securityMonitoring()->recordEvent($hub, [
                'category' => SecurityEvent::CATEGORY_DATA,
                'event_type' => 'secmon.disaster_rollback_failed',
                'severity' => SecurityEvent::SEVERITY_HIGH,
                'title' => 'Module rollback failed',
                'description' => 'Rollback of '.$this->moduleLabel($moduleKey)
                    .' from snapshot #'.$snapshot->id.' failed: '.$e->getMessage(),
                'actor' => $actor,
                'request' => $request,
                'related_refs' => [
                    'security_incident_id' => $incident->id,
                    'module_key' => $moduleKey,
                    'snapshot_id' => $snapshot->id,
                ],
            ]);

            throw $e;
        }

        $incident->recovery_status = self::RECOVERY_ROLLED_BACK;
        $incident->last_rollback_snapshot_id = $snapshot->id;
        $incident->last_rollback_at = now();
        $incident->last_snapshot_id = $pre->id;
        if (in_array($incident->status, [
            SecurityIncident::STATUS_OPEN,
            SecurityIncident::STATUS_ACKNOWLEDGED,
            SecurityIncident::STATUS_INVESTIGATING,
        ], true)) {
            $incident->status = SecurityIncident::STATUS_RESOLVED;
            $incident->resolved_at = now();
            $incident->resolved_by_user_id = $actor?->id;
            $incident->resolved_by_name = $actor?->name;
            $incident->resolution_summary = 'Module rolled back to snapshot #'.$snapshot->id
                .' ('.$snapshot->label.'). Pre-rollback copy #'.$pre->id.'.';
        }
        $incident->save();

        $this->securityMonitoring()->recordEvent($hub, [
            'category' => SecurityEvent::CATEGORY_DATA,
            'event_type' => 'secmon.disaster_rolled_back',
            'severity' => SecurityEvent::SEVERITY_MEDIUM,
            'title' => 'Module rolled back after disaster',
            'description' => $this->moduleLabel($moduleKey)
                .' restored from snapshot #'.$snapshot->id
                .' for incident #'.$incident->id.'.',
            'actor' => $actor,
            'request' => $request,
            'related_refs' => [
                'security_incident_id' => $incident->id,
                'module_key' => $moduleKey,
                'snapshot_id' => $snapshot->id,
                'pre_rollback_snapshot_id' => $pre->id,
            ],
            'status' => SecurityEvent::STATUS_RESOLVED,
        ]);

        try {
            $this->activityLogs->log([
                'action' => 'secmon.disaster_rollback',
                'description' => 'Rolled back '.$moduleKey.' from snapshot #'.$snapshot->id
                    .' (incident #'.$incident->id.')',
                'user' => $actor,
                'request' => $request,
                'status_code' => 200,
                'properties' => [
                    'incident_id' => $incident->id,
                    'module_key' => $moduleKey,
                    'snapshot_id' => $snapshot->id,
                    'pre_rollback_snapshot_id' => $pre->id,
                ],
            ]);
        } catch (Throwable) {
            //
        }

        return [
            'incident' => $incident->fresh()->load(['events' => fn ($q) => $q->orderByDesc('occurred_at'), 'notes']),
            'restored_snapshot' => $snapshot->fresh(),
            'pre_rollback_snapshot' => $pre->fresh(),
        ];
    }

    /**
     * @return array{
     *   version: int,
     *   module_key: string,
     *   module_enabled: bool,
     *   captured_at: string,
     *   row_counts: array<string, int>,
     *   tables: array<string, list<array<string, mixed>>>
     * }
     */
    private function captureModulePayload(Hub $hub, string $moduleKey): array
    {
        $tables = self::MODULE_TABLES[$moduleKey];
        $data = [];
        $counts = [];

        foreach ($tables as $table) {
            if (! Schema::hasTable($table)) {
                $data[$table] = [];
                $counts[$table] = 0;
                continue;
            }
            $rows = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
            $data[$table] = $rows;
            $counts[$table] = count($rows);
        }

        $checklist = is_array($hub->checklist) ? $hub->checklist : [];

        return [
            'version' => 1,
            'module_key' => $moduleKey,
            'module_enabled' => (bool) ($checklist[$moduleKey] ?? false),
            'captured_at' => now()->toIso8601String(),
            'row_counts' => $counts,
            'tables' => $data,
        ];
    }

    private function restoreModuleFromSnapshot(Hub $hub, ModuleDisasterSnapshot $snapshot): void
    {
        $path = $snapshot->absolutePath();
        if (! $path || ! is_file($path)) {
            throw new RuntimeException('Snapshot file is missing on disk.');
        }

        $payload = json_decode((string) file_get_contents($path), true);
        if (! is_array($payload) || ($payload['module_key'] ?? null) !== $snapshot->module_key) {
            throw new RuntimeException('Snapshot payload is corrupt or mismatched.');
        }

        $tables = self::MODULE_TABLES[$snapshot->module_key] ?? [];
        $tableData = is_array($payload['tables'] ?? null) ? $payload['tables'] : [];

        DB::transaction(function () use ($tables, $tableData, $hub, $payload, $snapshot) {
            $this->disableForeignKeyChecks();

            try {
                // Child tables first when deleting (reverse order).
                foreach (array_reverse($tables) as $table) {
                    if (! Schema::hasTable($table)) {
                        continue;
                    }
                    DB::table($table)->delete();
                }

                foreach ($tables as $table) {
                    if (! Schema::hasTable($table)) {
                        continue;
                    }
                    $rows = $tableData[$table] ?? [];
                    if (! is_array($rows) || $rows === []) {
                        continue;
                    }
                    foreach (array_chunk($rows, 100) as $chunk) {
                        $normalized = array_map(function ($row) {
                            return is_array($row) ? $row : (array) $row;
                        }, $chunk);
                        DB::table($table)->insert($normalized);
                    }
                }
            } finally {
                $this->enableForeignKeyChecks();
            }

            // Restore module enablement flag on the hub checklist (Central registry).
            $enabled = array_key_exists('module_enabled', $payload)
                ? (bool) $payload['module_enabled']
                : $snapshot->module_enabled;
            if ($enabled !== null) {
                $registryHub = Hub::query()->find($hub->id) ?: $hub;
                $checklist = is_array($registryHub->checklist) ? $registryHub->checklist : [];
                $checklist[$snapshot->module_key] = (bool) $enabled;
                $registryHub->checklist = $checklist;
                $registryHub->save();
                $this->hubs->forgetCurrentCache();
            }
        });
    }

    private function disableForeignKeyChecks(): void
    {
        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
        } elseif ($driver === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = OFF');
        }
    }

    private function enableForeignKeyChecks(): void
    {
        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        } elseif ($driver === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = ON');
        }
    }

    private function defaultLabel(string $moduleKey, string $trigger): string
    {
        $label = $this->moduleLabel($moduleKey);

        return match ($trigger) {
            ModuleDisasterSnapshot::TRIGGER_PRE_ROLLBACK => 'Pre-rollback · '.$label,
            ModuleDisasterSnapshot::TRIGGER_AUTO_BASELINE => 'Auto baseline · '.$label,
            ModuleDisasterSnapshot::TRIGGER_RECOGNIZE => 'At recognition · '.$label,
            default => 'Manual · '.$label.' · '.now()->toDateTimeString(),
        };
    }

    private function moduleLabel(string $moduleKey): string
    {
        return Hub::CHECKLIST_DEFINITIONS[$moduleKey]['label'] ?? $moduleKey;
    }
}
