<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\ComplianceAuditEvent;
use App\Models\GeneralComplianceRequest;
use App\Models\SocialMediaComplianceRequest;
use App\Models\User;
use App\Models\WebsiteCompliance\ChangeRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ComplianceAuditBackfillService
{
    /**
     * @return array{created: int, skipped_subjects: int, from_activity_logs: int, from_versions: int}
     */
    public function backfill(bool $force = false): array
    {
        $stats = [
            'created' => 0,
            'skipped_subjects' => 0,
            'from_activity_logs' => 0,
            'from_versions' => 0,
        ];

        if ($force) {
            ComplianceAuditEvent::query()
                ->where('metadata->source', 'backfill')
                ->delete();
        }

        $stats = $this->mergeStats($stats, $this->backfillModule(
            ComplianceAuditEvent::MODULE_SMC,
            SocialMediaComplianceRequest::class,
            fn () => SocialMediaComplianceRequest::query()->with([
                'versions' => fn ($q) => $q->orderBy('version_number'),
                'assignee',
                'assigner',
                'user',
            ])->orderBy('id')->get(),
            $force
        ));

        $stats = $this->mergeStats($stats, $this->backfillModule(
            ComplianceAuditEvent::MODULE_GC,
            GeneralComplianceRequest::class,
            fn () => GeneralComplianceRequest::query()->with([
                'versions' => fn ($q) => $q->orderBy('version_number'),
                'assignee',
                'assigner',
                'user',
            ])->orderBy('id')->get(),
            $force
        ));

        $stats = $this->mergeStats($stats, $this->backfillModule(
            ComplianceAuditEvent::MODULE_WC,
            ChangeRequest::class,
            fn () => ChangeRequest::query()->with([
                'versions' => fn ($q) => $q->orderBy('version_number'),
                'editor',
                'approver',
            ])->orderBy('id')->get(),
            $force
        ));

        // Catch activity-log subjects that were not found as local request rows
        // (e.g. remote white-label DB ids, or deleted requests).
        $stats = $this->mergeStats($stats, $this->backfillOrphanActivityLogs(
            ComplianceAuditEvent::MODULE_SMC,
            SocialMediaComplianceRequest::class,
            [
                'smc.submit', 'smc.assign', 'smc.unassign', 'smc.review',
                'smc.resubmit', 'smc.confirm_feedback', 'smc.change_status',
            ],
            $force
        ));
        $stats = $this->mergeStats($stats, $this->backfillOrphanActivityLogs(
            ComplianceAuditEvent::MODULE_GC,
            GeneralComplianceRequest::class,
            [
                'gc.submit', 'gc.assign', 'gc.unassign', 'gc.review',
                'gc.resubmit', 'gc.confirm_feedback', 'gc.change_status',
            ],
            $force
        ));
        $stats = $this->mergeStats($stats, $this->backfillOrphanActivityLogs(
            ComplianceAuditEvent::MODULE_WC,
            ChangeRequest::class,
            [
                'wc.change_request.submit',
                'wc.change_request.assign',
                'wc.change_request.reject',
                'wc.change_request.schedule',
                'wc.change_request.approve',
                'wc.change_request.approve_with_feedback',
                'wc.change_request.resubmit',
                'wc.change_request.confirm_feedback',
                'wc.change_request.change_status',
            ],
            $force
        ));

        return $stats;
    }

    /**
     * @param  list<string>  $actions
     * @return array{created: int, skipped_subjects: int, from_activity_logs: int, from_versions: int}
     */
    private function backfillOrphanActivityLogs(
        string $module,
        string $subjectClass,
        array $actions,
        bool $force
    ): array {
        $subjectIds = ActivityLog::query()
            ->where('subject_type', $subjectClass)
            ->whereIn('action', $actions)
            ->whereNotNull('subject_id')
            ->distinct()
            ->pluck('subject_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $created = 0;
        $skipped = 0;
        $fromLogs = 0;

        foreach ($subjectIds as $subjectId) {
            $existing = ComplianceAuditEvent::query()
                ->where('module', $module)
                ->where('subject_id', $subjectId)
                ->count();

            if ($existing > 0 && ! $force) {
                $skipped++;
                continue;
            }

            if ($force) {
                ComplianceAuditEvent::query()
                    ->where('module', $module)
                    ->where('subject_id', $subjectId)
                    ->where('metadata->source', 'backfill')
                    ->delete();

                if (ComplianceAuditEvent::query()
                    ->where('module', $module)
                    ->where('subject_id', $subjectId)
                    ->exists()
                ) {
                    $skipped++;
                    continue;
                }
            }

            $events = $this->eventsFromActivityLogs($module, $subjectClass, $subjectId);
            if ($events === []) {
                continue;
            }

            $created += $this->insertEvents($events);
            $fromLogs += count($events);
        }

        return [
            'created' => $created,
            'skipped_subjects' => $skipped,
            'from_activity_logs' => $fromLogs,
            'from_versions' => 0,
        ];
    }

    /**
     * @param  callable(): Collection<int, object>  $loadRequests
     * @return array{created: int, skipped_subjects: int, from_activity_logs: int, from_versions: int}
     */
    private function backfillModule(string $module, string $subjectClass, callable $loadRequests, bool $force): array
    {
        $created = 0;
        $skipped = 0;
        $fromLogs = 0;
        $fromVersions = 0;

        $requests = $loadRequests();
        foreach ($requests as $request) {
            $subjectId = (int) $request->id;
            $existingCount = ComplianceAuditEvent::query()
                ->where('module', $module)
                ->where('subject_id', $subjectId)
                ->count();

            if ($existingCount > 0 && ! $force) {
                $skipped++;
                continue;
            }

            if ($force) {
                ComplianceAuditEvent::query()
                    ->where('module', $module)
                    ->where('subject_id', $subjectId)
                    ->where('metadata->source', 'backfill')
                    ->delete();

                $stillHasLive = ComplianceAuditEvent::query()
                    ->where('module', $module)
                    ->where('subject_id', $subjectId)
                    ->exists();
                if ($stillHasLive) {
                    $skipped++;
                    continue;
                }
            }

            $fromActivity = $this->eventsFromActivityLogs($module, $subjectClass, $subjectId);
            if ($fromActivity !== []) {
                $created += $this->insertEvents($fromActivity);
                $fromLogs += count($fromActivity);
                continue;
            }

            $fromVersion = $this->eventsFromVersions($module, $request);
            if ($fromVersion !== []) {
                $created += $this->insertEvents($fromVersion);
                $fromVersions += count($fromVersion);
            }
        }

        return [
            'created' => $created,
            'skipped_subjects' => $skipped,
            'from_activity_logs' => $fromLogs,
            'from_versions' => $fromVersions,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function eventsFromActivityLogs(string $module, string $subjectClass, int $subjectId): array
    {
        $actions = match ($module) {
            ComplianceAuditEvent::MODULE_SMC => [
                'smc.submit', 'smc.assign', 'smc.unassign', 'smc.review',
                'smc.resubmit', 'smc.confirm_feedback', 'smc.change_status',
            ],
            ComplianceAuditEvent::MODULE_GC => [
                'gc.submit', 'gc.assign', 'gc.unassign', 'gc.review',
                'gc.resubmit', 'gc.confirm_feedback', 'gc.change_status',
            ],
            default => [
                'wc.change_request.submit',
                'wc.change_request.assign',
                'wc.change_request.reject',
                'wc.change_request.schedule',
                'wc.change_request.approve',
                'wc.change_request.approve_with_feedback',
                'wc.change_request.resubmit',
                'wc.change_request.confirm_feedback',
                'wc.change_request.change_status',
            ],
        };

        $logs = ActivityLog::query()
            ->where('subject_type', $subjectClass)
            ->where('subject_id', $subjectId)
            ->whereIn('action', $actions)
            ->orderBy('id')
            ->get();

        if ($logs->isEmpty()) {
            return [];
        }

        $events = [];
        foreach ($logs as $log) {
            $mapped = $this->mapActivityLog($module, $subjectClass, $log);
            if ($mapped) {
                $events[] = $mapped;
            }
        }

        return $events;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function mapActivityLog(string $module, string $subjectClass, ActivityLog $log): ?array
    {
        $props = is_array($log->properties) ? $log->properties : [];
        $eventType = match ($log->action) {
            'smc.submit', 'gc.submit', 'wc.change_request.submit' => ComplianceAuditEvent::EVENT_SUBMITTED,
            'smc.assign', 'gc.assign', 'wc.change_request.assign' => ComplianceAuditEvent::EVENT_ASSIGNED,
            'smc.unassign', 'gc.unassign' => ComplianceAuditEvent::EVENT_UNASSIGNED,
            'smc.review', 'gc.review', 'wc.change_request.approve_with_feedback' => ComplianceAuditEvent::EVENT_REVIEWED,
            'smc.resubmit', 'gc.resubmit', 'wc.change_request.resubmit' => ComplianceAuditEvent::EVENT_RESUBMITTED,
            'smc.confirm_feedback', 'gc.confirm_feedback', 'wc.change_request.confirm_feedback' => ComplianceAuditEvent::EVENT_FEEDBACK_CONFIRMED,
            'smc.change_status', 'gc.change_status', 'wc.change_request.change_status' => ComplianceAuditEvent::EVENT_STATUS_CHANGED,
            'wc.change_request.schedule' => ComplianceAuditEvent::EVENT_SCHEDULED,
            'wc.change_request.approve' => ComplianceAuditEvent::EVENT_PUBLISHED,
            'wc.change_request.reject' => ComplianceAuditEvent::EVENT_REJECTED,
            default => null,
        };

        if (! $eventType) {
            return null;
        }

        $related = null;
        $relatedId = $props['assigned_to'] ?? $props['approver_id'] ?? null;
        if ($relatedId) {
            $related = User::query()->find((int) $relatedId);
        }

        $toStatus = $props['status'] ?? $props['to_status'] ?? null;
        $fromStatus = $props['previous_status'] ?? $props['from_status'] ?? null;

        return [
            'hub_id' => $log->hub_id,
            'module' => $module,
            'subject_type' => $subjectClass,
            'subject_id' => (int) $log->subject_id,
            'event_type' => $eventType,
            'description' => $log->description,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'version_number' => isset($props['version']) ? (int) $props['version'] : null,
            'actor_user_id' => $log->user_id,
            'actor_name' => $log->user_name,
            'actor_email' => $log->user_email,
            'actor_role' => $log->user_role,
            'related_user_id' => $related?->id,
            'related_user_name' => $related?->name ?? ($props['assigned_to_name'] ?? null),
            'related_user_email' => $related?->email,
            'related_user_role' => $related?->role,
            'metadata' => [
                'source' => 'backfill',
                'from' => 'activity_logs',
                'activity_log_id' => $log->id,
                'action' => $log->action,
            ],
            'created_at' => $log->created_at ?? now(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function eventsFromVersions(string $module, object $request): array
    {
        $subjectClass = $request::class;
        $subjectId = (int) $request->id;
        $versions = $request->versions instanceof Collection
            ? $request->versions->sortBy('version_number')->values()
            : collect();

        if ($versions->isEmpty()) {
            return $this->assignmentFallbackEvents($module, $request, $subjectClass, $subjectId);
        }

        $events = [];
        $prevStatus = null;

        foreach ($versions as $version) {
            $versionNumber = (int) $version->version_number;
            $status = (string) ($version->status ?? '');
            $submitter = $this->resolveUser($version->submitted_by ?? null);
            $submittedAt = $version->submitted_at ?? $request->created_at ?? now();

            $creationEventType = null;
            if ($versionNumber <= 1) {
                $creationEventType = ComplianceAuditEvent::EVENT_SUBMITTED;
                $events[] = $this->versionEvent(
                    $module,
                    $subjectClass,
                    $subjectId,
                    $creationEventType,
                    'Submitted compliance request #'.$subjectId.' (backfilled from version history)',
                    null,
                    $status !== '' ? ($status === 'Pending' || $status === 'pending' ? $status : ($module === ComplianceAuditEvent::MODULE_WC ? 'pending' : 'Pending')) : null,
                    $versionNumber,
                    $submitter,
                    $submittedAt
                );
            } else {
                $creationEventType = $this->inferVersionEventType($module, $prevStatus, $status, $version, $versions);
                $actorForCreation = $submitter;
                if (in_array($creationEventType, [
                    ComplianceAuditEvent::EVENT_STATUS_CHANGED,
                    ComplianceAuditEvent::EVENT_FEEDBACK_CONFIRMED,
                ], true) && filled($version->reviewed_by)) {
                    $actorForCreation = $this->resolveUserByName((string) $version->reviewed_by) ?? $submitter;
                }
                $events[] = $this->versionEvent(
                    $module,
                    $subjectClass,
                    $subjectId,
                    $creationEventType,
                    $this->versionEventDescription($creationEventType, $subjectId, $versionNumber, $status),
                    $prevStatus,
                    $status !== '' ? $status : null,
                    $versionNumber,
                    $actorForCreation,
                    $version->reviewed_at ?? $submittedAt
                );
            }

            // Approver review usually mutates the current version in place — capture that
            // when this version was created as a submission/resubmit and later reviewed.
            $alreadyCapturedDecision = in_array($creationEventType, [
                ComplianceAuditEvent::EVENT_STATUS_CHANGED,
                ComplianceAuditEvent::EVENT_FEEDBACK_CONFIRMED,
                ComplianceAuditEvent::EVENT_REVIEWED,
            ], true);

            if (
                ! $alreadyCapturedDecision
                && filled($version->reviewed_at)
                && filled($version->reviewed_by)
                && $status !== ''
                && ! in_array($status, ['Pending', 'pending'], true)
            ) {
                $reviewer = $this->resolveUserByName((string) $version->reviewed_by)
                    ?? $submitter;
                $events[] = $this->versionEvent(
                    $module,
                    $subjectClass,
                    $subjectId,
                    $this->reviewEventTypeForStatus($module, $status),
                    'Status set to '.$status.' on request #'.$subjectId.' (backfilled from version history)',
                    $versionNumber <= 1
                        ? ($module === ComplianceAuditEvent::MODULE_WC ? 'pending' : 'Pending')
                        : $prevStatus,
                    $status,
                    $versionNumber,
                    $reviewer,
                    $version->reviewed_at,
                    [
                        'reviewed_by_name' => (string) $version->reviewed_by,
                        'feedback' => $version->feedback ?? null,
                    ]
                );
            }

            $prevStatus = $status !== '' ? $status : $prevStatus;
        }

        foreach ($this->assignmentFallbackEvents($module, $request, $subjectClass, $subjectId) as $assignEvent) {
            $events[] = $assignEvent;
        }

        return $this->sortEvents($events);
    }

    private function inferVersionEventType(
        string $module,
        ?string $prevStatus,
        string $status,
        object $version,
        Collection $allVersions
    ): string {
        $pending = $module === ComplianceAuditEvent::MODULE_WC ? 'pending' : 'Pending';
        $rejected = $module === ComplianceAuditEvent::MODULE_WC ? 'rejected' : 'Rejected';
        $awf = $module === ComplianceAuditEvent::MODULE_WC
            ? 'approved_with_feedback'
            : 'Approved with Feedback';
        $approved = $module === ComplianceAuditEvent::MODULE_WC ? 'approved' : 'Approved';

        if ($prevStatus === $rejected && $status === $pending) {
            return ComplianceAuditEvent::EVENT_RESUBMITTED;
        }
        if ($prevStatus === $awf && in_array($status, [$approved, $pending], true)) {
            return ComplianceAuditEvent::EVENT_FEEDBACK_CONFIRMED;
        }
        if (filled($version->reviewed_by) && $status !== $pending && $status !== $prevStatus) {
            return ComplianceAuditEvent::EVENT_STATUS_CHANGED;
        }

        return ComplianceAuditEvent::EVENT_RESUBMITTED;
    }

    private function reviewEventTypeForStatus(string $module, string $status): string
    {
        if ($module === ComplianceAuditEvent::MODULE_WC) {
            return match ($status) {
                ChangeRequest::STATUS_REJECTED => ComplianceAuditEvent::EVENT_REJECTED,
                ChangeRequest::STATUS_SCHEDULED => ComplianceAuditEvent::EVENT_SCHEDULED,
                ChangeRequest::STATUS_PUBLISHED => ComplianceAuditEvent::EVENT_PUBLISHED,
                ChangeRequest::STATUS_APPROVED => ComplianceAuditEvent::EVENT_REVIEWED,
                ChangeRequest::STATUS_APPROVED_WITH_FEEDBACK => ComplianceAuditEvent::EVENT_REVIEWED,
                default => ComplianceAuditEvent::EVENT_STATUS_CHANGED,
            };
        }

        return ComplianceAuditEvent::EVENT_REVIEWED;
    }

    private function versionEventDescription(string $eventType, int $subjectId, int $version, string $status): string
    {
        return match ($eventType) {
            ComplianceAuditEvent::EVENT_RESUBMITTED => 'Resubmitted request #'.$subjectId.' as v'.$version.' (backfilled)',
            ComplianceAuditEvent::EVENT_FEEDBACK_CONFIRMED => 'Confirmed feedback on request #'.$subjectId.' as v'.$version.' (backfilled)',
            ComplianceAuditEvent::EVENT_STATUS_CHANGED => 'Changed status of request #'.$subjectId.' → '.$status.' (v'.$version.', backfilled)',
            default => 'Updated request #'.$subjectId.' as v'.$version.' (backfilled)',
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function assignmentFallbackEvents(
        string $module,
        object $request,
        string $subjectClass,
        int $subjectId
    ): array {
        if ($module === ComplianceAuditEvent::MODULE_WC) {
            $approver = $request->approver ?? null;
            if (! $approver) {
                return [];
            }

            return [[
                ...$this->baseEvent($module, $subjectClass, $subjectId),
                'event_type' => ComplianceAuditEvent::EVENT_ASSIGNED,
                'description' => 'Assigned change request #'.$subjectId.' to '.$approver->name.' (backfilled from current assignee)',
                'from_status' => null,
                'to_status' => $request->status ?? null,
                'version_number' => $request->current_version ?? null,
                'actor_user_id' => null,
                'actor_name' => 'System backfill',
                'actor_email' => null,
                'actor_role' => null,
                'related_user_id' => $approver->id,
                'related_user_name' => $approver->name,
                'related_user_email' => $approver->email,
                'related_user_role' => $approver->role,
                'metadata' => [
                    'source' => 'backfill',
                    'from' => 'current_assignment',
                    'approximate' => true,
                ],
                'created_at' => $request->updated_at ?? $request->created_at ?? now(),
            ]];
        }

        $assignee = $request->assignee ?? null;
        $assigner = $request->assigner ?? null;
        if (! $assignee) {
            return [];
        }

        return [[
            ...$this->baseEvent($module, $subjectClass, $subjectId),
            'event_type' => ComplianceAuditEvent::EVENT_ASSIGNED,
            'description' => 'Assigned request #'.$subjectId.' to '.$assignee->name.' (backfilled from current assignment)',
            'from_status' => null,
            'to_status' => method_exists($request, 'currentStatus') ? $request->currentStatus() : null,
            'version_number' => $request->current_version ?? null,
            'actor_user_id' => $assigner?->id,
            'actor_name' => $assigner?->name ?? 'System backfill',
            'actor_email' => $assigner?->email,
            'actor_role' => $assigner?->role,
            'related_user_id' => $assignee->id,
            'related_user_name' => $assignee->name,
            'related_user_email' => $assignee->email,
            'related_user_role' => $assignee->role,
            'metadata' => [
                'source' => 'backfill',
                'from' => 'current_assignment',
                'approximate' => true,
            ],
            'created_at' => $request->assigned_date ?? $request->updated_at ?? now(),
        ]];
    }

    /**
     * @param  array<string, mixed>  $extraMeta
     * @return array<string, mixed>
     */
    private function versionEvent(
        string $module,
        string $subjectClass,
        int $subjectId,
        string $eventType,
        string $description,
        ?string $fromStatus,
        ?string $toStatus,
        ?int $versionNumber,
        ?User $actor,
        mixed $at,
        array $extraMeta = []
    ): array {
        return [
            ...$this->baseEvent($module, $subjectClass, $subjectId),
            'event_type' => $eventType,
            'description' => $description,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'version_number' => $versionNumber,
            'actor_user_id' => $actor?->id,
            'actor_name' => $actor?->name,
            'actor_email' => $actor?->email,
            'actor_role' => $actor?->role,
            'related_user_id' => null,
            'related_user_name' => null,
            'related_user_email' => null,
            'related_user_role' => null,
            'metadata' => array_merge([
                'source' => 'backfill',
                'from' => 'versions',
            ], $extraMeta),
            'created_at' => $at instanceof Carbon ? $at : Carbon::parse((string) $at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function baseEvent(string $module, string $subjectClass, int $subjectId): array
    {
        return [
            'hub_id' => null,
            'module' => $module,
            'subject_type' => $subjectClass,
            'subject_id' => $subjectId,
        ];
    }

    private function resolveUser(mixed $id): ?User
    {
        if (! $id) {
            return null;
        }

        return User::query()->find((int) $id);
    }

    private function resolveUserByName(string $name): ?User
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        return User::query()->where('name', $name)->first();
    }

    /**
     * @param  list<array<string, mixed>>  $events
     * @return list<array<string, mixed>>
     */
    private function sortEvents(array $events): array
    {
        usort($events, function (array $a, array $b) {
            $aTime = Carbon::parse((string) $a['created_at'])->getTimestamp();
            $bTime = Carbon::parse((string) $b['created_at'])->getTimestamp();
            if ($aTime === $bTime) {
                return ($a['version_number'] ?? 0) <=> ($b['version_number'] ?? 0);
            }

            return $aTime <=> $bTime;
        });

        return $events;
    }

    /**
     * @param  list<array<string, mixed>>  $events
     */
    private function insertEvents(array $events): int
    {
        $count = 0;
        foreach ($events as $event) {
            ComplianceAuditEvent::query()->create($event);
            $count++;
        }

        return $count;
    }

    /**
     * @param  array{created: int, skipped_subjects: int, from_activity_logs: int, from_versions: int}  $a
     * @param  array{created: int, skipped_subjects: int, from_activity_logs: int, from_versions: int}  $b
     * @return array{created: int, skipped_subjects: int, from_activity_logs: int, from_versions: int}
     */
    private function mergeStats(array $a, array $b): array
    {
        return [
            'created' => $a['created'] + $b['created'],
            'skipped_subjects' => $a['skipped_subjects'] + $b['skipped_subjects'],
            'from_activity_logs' => $a['from_activity_logs'] + $b['from_activity_logs'],
            'from_versions' => $a['from_versions'] + $b['from_versions'],
        ];
    }
}
