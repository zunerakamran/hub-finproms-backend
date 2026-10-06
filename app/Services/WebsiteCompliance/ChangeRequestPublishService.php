<?php

namespace App\Services\WebsiteCompliance;

use App\Jobs\WebsiteCompliance\SyncAdvisorCpanelJob;
use App\Jobs\WebsiteCompliance\SyncTemplateRequestCpanelJob;
use App\Models\ComplianceAuditEvent;
use App\Models\WebsiteCompliance\ChangeRequest;
use App\Models\WebsiteCompliance\Section;
use App\Models\WebsiteCompliance\TemplateRequest;
use App\Services\ActivityLogService;
use App\Services\ComplianceAuditTrailService;
use App\Support\WebsiteCompliance\WcDatabaseContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ChangeRequestPublishService
{
    /**
     * Atomically claim a due scheduled request and apply its content.
     * Holds a row lock through the DB write so job + catch-up cron cannot double-publish.
     * Status stays `scheduled` until content is written — if this fails, retries can still pick it up.
     * cPanel sync is queued after commit (outside the lock) with the section payload.
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

        // Scheduled publish runs from cron/queue — keep cPanel off the lock, but pass
        // the section payload (same as Power Admin publishContent) so rebuild-by-TR is not required.
        self::queueLiveSiteSync(
            $applied['template_request_id'],
            $applied['publish_advisor_id'],
            $applied['sections']
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
     * Apply proposed content to hub sections and push to the live advisor site.
     * Immediate approve/publish matches Power Admin `publishContent`: sync cPanel inline
     * with the edited section payload (queue-only was silently failing when rebuild was empty).
     *
     * @return array{success: bool, cpanel_synced: bool, cpanel_sync_queued: bool, sections: array, cpanel_message?: string|null}
     */
    public static function publish(ChangeRequest $changeRequest, ?int $actorUserId = null): array
    {
        $changeRequest->loadMissing(['section', 'currentVersionRow']);

        $applied = self::applyPublishedContent($changeRequest, $actorUserId);

        $sync = self::syncLiveSiteNow(
            $applied['template_request_id'],
            $applied['publish_advisor_id'],
            $applied['sections']
        );

        // If inline push failed, queue a retry with the same payload.
        $queued = false;
        if (! ($sync['ok'] ?? false)) {
            self::queueLiveSiteSync(
                $applied['template_request_id'],
                $applied['publish_advisor_id'],
                $applied['sections']
            );
            $queued = true;

            Log::warning('wc publish: inline cPanel sync failed; queued retry', [
                'change_request_id' => $changeRequest->id,
                'template_request_id' => $applied['template_request_id'],
                'advisor_id' => $applied['publish_advisor_id'],
                'sections' => count($applied['sections']),
                'message' => $sync['message'] ?? null,
                'endpoint' => $sync['endpoint'] ?? null,
                'http_status' => $sync['http_status'] ?? null,
            ]);
        }

        self::logPublishActivity(
            $applied['change_request'],
            $actorUserId,
            cpanelSynced: (bool) ($sync['ok'] ?? false),
            scheduled: false,
            cpanelQueued: $queued
        );

        return [
            'success' => true,
            'cpanel_synced' => (bool) ($sync['ok'] ?? false),
            'cpanel_sync_queued' => $queued,
            'sections' => $applied['sections'],
            'cpanel_message' => $sync['message'] ?? null,
        ];
    }

    /**
     * Push live now — same path Power Admin uses for publish-without-approval.
     *
     * @param  list<array<string, mixed>>  $sections
     * @return array{ok: bool, endpoint: ?string, message: ?string, http_status: ?int, body: mixed}
     */
    private static function syncLiveSiteNow(mixed $templateRequestId, mixed $publishAdvisorId, array $sections): array
    {
        // Prefer full deployment payload from hub DB (content already written) so
        // every section name matches the advisor site; fall back to edited rows only.
        $sectionsToPush = $sections;
        if ($templateRequestId) {
            $full = CpanelSyncService::advisorSectionPayloadForTemplateRequest((int) $templateRequestId);
            if (! empty($full)) {
                $sectionsToPush = $full;
            }
        }

        if ($templateRequestId) {
            $templateRequest = TemplateRequest::query()->find((int) $templateRequestId);
            if ($templateRequest) {
                return CpanelSyncService::pushToTemplateRequestCpanelWithDetails(
                    $templateRequest,
                    $sectionsToPush
                );
            }

            Log::warning('wc publish: template request missing for live sync', [
                'template_request_id' => $templateRequestId,
            ]);
        }

        if ($publishAdvisorId) {
            if (empty($sectionsToPush)) {
                $sectionsToPush = CpanelSyncService::advisorSectionPayload($publishAdvisorId);
            }

            $ok = CpanelSyncService::pushToAdvisorCpanel($publishAdvisorId, $sectionsToPush);

            return [
                'ok' => $ok,
                'endpoint' => null,
                'message' => $ok
                    ? 'Synced via advisor deployment lookup.'
                    : 'Advisor cPanel push failed (no domain, empty sections, or site rejected sync / updated 0 rows).',
                'http_status' => null,
                'body' => null,
            ];
        }

        return [
            'ok' => false,
            'endpoint' => null,
            'message' => 'No template request or advisor deployment found to sync.',
            'http_status' => null,
            'body' => null,
        ];
    }

    /**
     * Queue live-site push with the edited section payload (do not rebuild empty).
     *
     * @param  list<array<string, mixed>>  $sections
     */
    private static function queueLiveSiteSync(mixed $templateRequestId, mixed $publishAdvisorId, array $sections = []): void
    {
        $hubId = WcDatabaseContext::hubId();

        if ($templateRequestId) {
            SyncTemplateRequestCpanelJob::dispatch(
                (int) $templateRequestId,
                $sections,
                $hubId
            );

            return;
        }

        SyncAdvisorCpanelJob::dispatch(
            $publishAdvisorId,
            $sections,
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

            if ($cpanelQueued && ! $cpanelSynced) {
                $eventDescription = $scheduled
                    ? 'Scheduled content published in hub DB for change request #'.$changeRequest->id.'; live site sync queued'
                    : 'Content approved and published in hub DB for change request #'.$changeRequest->id.'; live site sync queued after inline push failed';
            } elseif ($cpanelQueued && $cpanelSynced) {
                $eventDescription = $scheduled
                    ? 'Scheduled content published for change request #'.$changeRequest->id
                    : 'Content approved and published for change request #'.$changeRequest->id;
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
