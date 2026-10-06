<?php

namespace App\Services\WebsiteCompliance;

use App\Jobs\WebsiteCompliance\SyncAdvisorCpanelJob;
use App\Jobs\WebsiteCompliance\SyncTemplateRequestCpanelJob;
use App\Models\ComplianceAuditEvent;
use App\Models\WebsiteCompliance\ChangeRequest;
use App\Models\WebsiteCompliance\Section;
use App\Services\ActivityLogService;
use App\Services\ComplianceAuditTrailService;
use App\Support\WebsiteCompliance\WcDatabaseContext;
use Illuminate\Support\Facades\DB;

class ChangeRequestPublishService
{
    /**
     * Atomically claim a due scheduled request and apply its content.
     * Holds a row lock through the DB write so job + catch-up cron cannot double-publish.
     * Status stays `scheduled` until content is written — if this fails, retries can still pick it up.
     * cPanel sync runs after commit (outside the lock).
     *
     * @return array{success: bool, cpanel_synced: bool, cpanel_sync_queued: bool, sections: array}|null
     */
    public static function claimAndPublishDueScheduled(ChangeRequest $changeRequest, ?int $actorUserId = null): ?array
    {
        $connection = $changeRequest->getConnectionName() ?: (string) config('database.default');

        $applied = DB::connection($connection)->transaction(function () use ($changeRequest, $actorUserId) {
            $row = ChangeRequest::query()
                ->whereKey($changeRequest->id)
                ->where('status', ChangeRequest::STATUS_SCHEDULED)
                ->whereNotNull('scheduled_at')
                ->where('scheduled_at', '<=', now())
                ->lockForUpdate()
                ->first();

            if (! $row) {
                return null;
            }

            $row->loadMissing(['section', 'currentVersionRow']);

            // Apply section content + flip to published while still holding the lock.
            return self::applyPublishedContent($row, $actorUserId);
        });

        if ($applied === null) {
            return null;
        }

        // cPanel HTTP can take 25s+ — queue so PHP workers stay free for other users.
        // Pass [] so the job rebuilds a full keyed payload from hub DB (content already written).
        self::queueLiveSiteSync(
            $applied['template_request_id'],
            $applied['publish_advisor_id']
        );

        self::logPublishActivity(
            $applied['change_request'],
            $actorUserId,
            cpanelSynced: false,
            scheduled: true,
            cpanelQueued: true
        );

        return [
            'success' => true,
            'cpanel_synced' => false,
            'cpanel_sync_queued' => true,
            'sections' => $applied['sections'],
        ];
    }

    /**
     * Apply proposed content to hub sections and queue a live advisor site push.
     *
     * @return array{success: bool, cpanel_synced: bool, cpanel_sync_queued: bool, sections: array}
     */
    public static function publish(ChangeRequest $changeRequest, ?int $actorUserId = null): array
    {
        $changeRequest->loadMissing(['section', 'currentVersionRow']);

        $applied = self::applyPublishedContent($changeRequest, $actorUserId);

        // Pass [] so the job rebuilds a full keyed payload from hub DB (content already written).
        self::queueLiveSiteSync(
            $applied['template_request_id'],
            $applied['publish_advisor_id']
        );

        self::logPublishActivity(
            $applied['change_request'],
            $actorUserId,
            cpanelSynced: false,
            scheduled: false,
            cpanelQueued: true
        );

        return [
            'success' => true,
            'cpanel_synced' => false,
            'cpanel_sync_queued' => true,
            'sections' => $applied['sections'],
        ];
    }

    /**
     * Queue live-site push. Prefer the deployment tied to the edited sections
     * (same as SectionController visibility sync) over advisor-scoped lookup.
     */
    private static function queueLiveSiteSync(mixed $templateRequestId, mixed $publishAdvisorId): void
    {
        $hubId = WcDatabaseContext::hubId();

        if ($templateRequestId) {
            SyncTemplateRequestCpanelJob::dispatch(
                (int) $templateRequestId,
                [],
                $hubId
            );

            return;
        }

        SyncAdvisorCpanelJob::dispatch(
            $publishAdvisorId,
            [],
            $hubId
        );
    }

    /**
     * Write proposed content to sections and mark the change request published.
     * Does not push to cPanel (caller does that after any surrounding transaction commits).
     *
     * @return array{change_request: ChangeRequest, sections: array, publish_advisor_id: int|string|null, template_request_id: int|null}
     */
    private static function applyPublishedContent(ChangeRequest $changeRequest, ?int $actorUserId = null): array
    {
        $proposedContent = $changeRequest->resolvedProposedContent();
        if ($proposedContent !== null && $proposedContent !== $changeRequest->proposed_content) {
            $changeRequest->proposed_content = $proposedContent;
        }

        $decoded = json_decode((string) $proposedContent, true);
        $updatedSections = [];
        $publishAdvisorId = $changeRequest->editor_id;
        $templateRequestId = null;

        if (is_array($decoded)) {
            foreach ($decoded as $editItem) {
                if (! isset($editItem['section_id'])) {
                    continue;
                }

                $sec = Section::find($editItem['section_id']);
                if (! $sec) {
                    continue;
                }

                $sec->update([
                    'content' => $editItem['proposed_content'],
                    'is_locked' => false,
                    'locked_by' => null,
                ]);
                $sec->refresh();

                $publishAdvisorId = $sec->advisor_id ?: $publishAdvisorId;
                $templateRequestId = $sec->template_request_id ?: $templateRequestId;
                $updatedSections[] = CpanelSyncService::formatSectionForCpanel(
                    $sec,
                    $editItem['proposed_content'] ?? null
                );
            }
        } elseif ($changeRequest->section) {
            $changeRequest->section->update([
                'content' => $proposedContent,
                'is_locked' => false,
                'locked_by' => null,
            ]);
            $changeRequest->section->refresh();
            $publishAdvisorId = $changeRequest->section->advisor_id ?: $publishAdvisorId;
            $templateRequestId = $changeRequest->section->template_request_id ?: $templateRequestId;
            $updatedSections[] = CpanelSyncService::formatSectionForCpanel(
                $changeRequest->section,
                $proposedContent
            );
        }

        // Fallback: resolve deployment from advisor if sections lacked template_request_id.
        if (! $templateRequestId && $publishAdvisorId) {
            $deployed = CpanelSyncService::findDeployedRequest($publishAdvisorId);
            $templateRequestId = $deployed?->id;
        }

        $changeRequest->update([
            'status' => ChangeRequest::STATUS_PUBLISHED,
            'scheduled_at' => null,
            'proposed_content' => $proposedContent,
            'feedback' => null,
            'rejection_reason' => null,
        ]);

        $version = $changeRequest->currentVersionRow;
        if ($version) {
            $reviewerName = null;
            if ($actorUserId) {
                // Prefer the authenticated actor (shared control-plane user) over tenant User::find.
                $authUser = auth()->user();
                if ($authUser && (int) $authUser->id === (int) $actorUserId) {
                    $reviewerName = $authUser->name ?: (string) $actorUserId;
                } else {
                    $reviewer = \App\Models\User::find($actorUserId);
                    $reviewerName = $reviewer?->name ?: (string) $actorUserId;
                }
            }

            $version->update([
                'status' => ChangeRequest::STATUS_PUBLISHED,
                'proposed_content' => $proposedContent,
                'reviewed_by' => $version->reviewed_by ?: $reviewerName,
                'reviewed_at' => $version->reviewed_at ?: now(),
            ]);
        }

        return [
            'change_request' => $changeRequest,
            'sections' => $updatedSections,
            'publish_advisor_id' => $publishAdvisorId,
            'template_request_id' => $templateRequestId ? (int) $templateRequestId : null,
        ];
    }

    private static function logPublishActivity(
        ChangeRequest $changeRequest,
        ?int $actorUserId,
        bool $cpanelSynced,
        bool $scheduled,
        bool $cpanelQueued = false
    ): void {
        try {
            $actor = auth()->user();
            if (! $actor && $actorUserId) {
                $actor = \App\Models\User::find($actorUserId);
            }

            if ($cpanelQueued) {
                $eventDescription = $scheduled
                    ? 'Scheduled content published in hub DB for change request #'.$changeRequest->id.'; live site sync queued'
                    : 'Content approved and published in hub DB for change request #'.$changeRequest->id.'; live site sync queued';
            } else {
                $eventDescription = $scheduled
                    ? ($cpanelSynced
                        ? 'Scheduled content published for change request #'.$changeRequest->id
                        : 'Scheduled content published in hub DB for change request #'.$changeRequest->id.' but live site was not updated')
                    : ($cpanelSynced
                        ? 'Content approved and published for change request #'.$changeRequest->id
                        : 'Content approved in hub DB for change request #'.$changeRequest->id.' but live site was not updated');
            }

            app(ActivityLogService::class)->log([
                'action' => 'wc.change_request.approve',
                'description' => $eventDescription,
                'subject' => $changeRequest,
                'user' => $actor,
                'properties' => array_filter([
                    'cpanel_synced' => $cpanelSynced,
                    'cpanel_sync_queued' => $cpanelQueued ?: null,
                    'approver_id' => $actorUserId ?: $changeRequest->approver_id,
                    'via' => $scheduled ? 'scheduled' : null,
                ], fn ($v) => $v !== null),
            ]);

            app(ComplianceAuditTrailService::class)->record([
                'module' => ComplianceAuditEvent::MODULE_WC,
                'subject' => $changeRequest,
                'event_type' => ComplianceAuditEvent::EVENT_PUBLISHED,
                'actor' => $actor,
                'description' => $eventDescription,
                'to_status' => ChangeRequest::STATUS_PUBLISHED,
                'version_number' => $changeRequest->current_version,
                'metadata' => array_filter([
                    'cpanel_synced' => $cpanelSynced,
                    'cpanel_sync_queued' => $cpanelQueued ?: null,
                    'approver_id' => $actorUserId ?: $changeRequest->approver_id,
                    'via' => $scheduled ? 'scheduled' : null,
                ], fn ($v) => $v !== null),
            ]);
        } catch (\Throwable) {
            // Activity / audit logging must not block publish.
        }
    }
}
