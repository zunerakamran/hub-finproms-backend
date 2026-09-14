<?php

namespace App\Services\WebsiteCompliance;

use App\Models\WebsiteCompliance\ChangeRequest;
use App\Models\WebsiteCompliance\Section;
use App\Services\ActivityLogService;

class ChangeRequestPublishService
{
    /**
     * Apply proposed content to hub sections and push to the live advisor site.
     *
     * @return array{success: bool, cpanel_synced: bool, sections: array}
     */
    public static function publish(ChangeRequest $changeRequest, ?int $actorUserId = null): array
    {
        $changeRequest->loadMissing(['section', 'currentVersionRow']);

        $proposedContent = $changeRequest->resolvedProposedContent();
        if ($proposedContent !== null && $proposedContent !== $changeRequest->proposed_content) {
            $changeRequest->proposed_content = $proposedContent;
        }

        $decoded = json_decode((string) $proposedContent, true);
        $updatedSections = [];
        $publishAdvisorId = $changeRequest->editor_id;

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

                $publishAdvisorId = $sec->advisor_id ?: $publishAdvisorId;
                $updatedSections[] = [
                    'name' => $sec->name,
                    'display_name' => $sec->display_name ?: $sec->name,
                    'is_visible' => $sec->is_visible !== false,
                    'content' => $editItem['proposed_content'],
                ];
            }
        } elseif ($changeRequest->section) {
            $changeRequest->section->update([
                'content' => $proposedContent,
                'is_locked' => false,
                'locked_by' => null,
            ]);
            $publishAdvisorId = $changeRequest->section->advisor_id ?: $publishAdvisorId;
            $updatedSections[] = [
                'name' => $changeRequest->section->name,
                'display_name' => $changeRequest->section->display_name ?: $changeRequest->section->name,
                'is_visible' => $changeRequest->section->is_visible !== false,
                'content' => $proposedContent,
            ];
        }

        $changeRequest->update([
            'status' => ChangeRequest::STATUS_APPROVED,
            'scheduled_at' => null,
            'proposed_content' => $proposedContent,
            'feedback' => null,
            'rejection_reason' => null,
        ]);

        $version = $changeRequest->currentVersionRow;
        if ($version) {
            $reviewerName = null;
            if ($actorUserId) {
                $reviewer = \App\Models\User::find($actorUserId);
                $reviewerName = $reviewer?->name ?: (string) $actorUserId;
            }

            $version->update([
                'status' => ChangeRequest::STATUS_APPROVED,
                'proposed_content' => $proposedContent,
                'reviewed_by' => $version->reviewed_by ?: $reviewerName,
                'reviewed_at' => $version->reviewed_at ?: now(),
            ]);
        }

        $cpanelSynced = CpanelSyncService::pushToAdvisorCpanel($publishAdvisorId, $updatedSections);

        try {
            app(ActivityLogService::class)->log([
                'action' => 'wc.change_request.approve',
                'description' => $cpanelSynced
                    ? 'Content approved and published to advisor cPanel DB & hub DB'
                    : 'Content approved in hub DB but cPanel push did not update the live site',
                'subject' => $changeRequest,
                'user' => $actorUserId ? \App\Models\User::find($actorUserId) : null,
                'properties' => [
                    'cpanel_synced' => $cpanelSynced,
                    'approver_id' => $actorUserId ?: $changeRequest->approver_id,
                ],
            ]);
        } catch (\Throwable) {
            // Activity logging must not block publish.
        }

        return [
            'success' => true,
            'cpanel_synced' => $cpanelSynced,
            'sections' => $updatedSections,
        ];
    }
}
