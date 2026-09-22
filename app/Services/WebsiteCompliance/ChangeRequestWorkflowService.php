<?php

namespace App\Services\WebsiteCompliance;

use App\Models\User;
use App\Models\WebsiteCompliance\ChangeRequest;
use App\Models\WebsiteCompliance\ChangeRequestVersion;
use App\Models\WebsiteCompliance\Section;
use App\Services\ActivityLogService;
use App\Services\FirmComplianceVisibilityService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ChangeRequestWorkflowService
{
    public function __construct(
        private readonly ActivityLogService $activityLogs,
        private readonly WebsiteComplianceGate $gate,
        private readonly FirmComplianceVisibilityService $firmVisibility
    ) {}

    /**
     * Validate section edits, lock sections, and return normalized edit rows.
     *
     * @param  list<array{section_id:int, proposed_content:string, current_content?:string|null}>  $sectionEdits
     * @return list<array{section_id:int, section_name:string, current_content:mixed, proposed_content:string}>
     */
    public function prepareAndLockEdits(array $sectionEdits, User $user): array
    {
        $edits = [];
        $tenantUserId = $this->gate->tenantUserIdOrNull($user);
        $canBypassLocks = $this->gate->isRemoteControlPlaneOperator($user);

        foreach ($sectionEdits as $edit) {
            $section = Section::findOrFail($edit['section_id']);
            if (
                $section->is_locked
                && ! $canBypassLocks
                && $section->locked_by !== null
                && (int) $section->locked_by !== (int) ($tenantUserId ?? $user->id)
            ) {
                throw new HttpException(409, "Section '{$section->name}' is locked by another user");
            }

            $section->update(['is_locked' => true, 'locked_by' => null]);

            $edits[] = [
                'section_id' => $section->id,
                'section_name' => $section->name,
                'current_content' => $edit['current_content'] ?? $section->content,
                'proposed_content' => $edit['proposed_content'],
            ];
        }

        return $edits;
    }

    public function unlockSectionsFromProposedContent(?string $proposedContent, ?Section $section = null): void
    {
        $decoded = json_decode((string) $proposedContent, true);

        if (is_array($decoded)) {
            foreach ($decoded as $editItem) {
                if (! isset($editItem['section_id'])) {
                    continue;
                }
                $sec = Section::find($editItem['section_id']);
                if ($sec) {
                    $sec->update(['is_locked' => false, 'locked_by' => null]);
                }
            }

            return;
        }

        if ($section) {
            $section->update(['is_locked' => false, 'locked_by' => null]);
        }
    }

    public function createVersionOne(ChangeRequest $changeRequest, string $proposedContent, string $status = ChangeRequest::STATUS_PENDING): ChangeRequestVersion
    {
        return ChangeRequestVersion::create([
            'request_id' => $changeRequest->id,
            'version_number' => 1,
            'proposed_content' => $proposedContent,
            'status' => $status,
            'feedback' => null,
            'submitted_by' => $changeRequest->editor_id,
            'submitted_at' => now(),
        ]);
    }

    public function syncCurrentVersionStatus(
        ChangeRequest $changeRequest,
        string $status,
        ?User $reviewer = null,
        ?string $feedback = null
    ): void {
        $changeRequest->loadMissing('currentVersionRow');
        $version = $changeRequest->currentVersionRow;
        if (! $version) {
            return;
        }

        $payload = ['status' => $status];
        if ($feedback !== null) {
            $payload['feedback'] = $feedback;
        }
        if ($reviewer) {
            $payload['reviewed_by'] = $reviewer->name ?: (string) $reviewer->id;
            $payload['reviewed_at'] = now();
        }

        $version->update($payload);
    }

    public function approveWithFeedback(ChangeRequest $changeRequest, User $user, string $feedback, ?Request $request = null): ChangeRequest
    {
        $this->assertReviewable($changeRequest, $user);

        $proposed = $changeRequest->resolvedProposedContent();
        $changeRequest->loadMissing('section');
        $this->unlockSectionsFromProposedContent($proposed, $changeRequest->section);

        $changeRequest->update([
            'status' => ChangeRequest::STATUS_APPROVED_WITH_FEEDBACK,
            'feedback' => $feedback,
            'approver_id' => $changeRequest->approver_id ?: $this->gate->tenantUserIdOrNull($user),
            'rejection_reason' => null,
            'scheduled_at' => null,
        ]);

        $this->syncCurrentVersionStatus(
            $changeRequest->fresh(['currentVersionRow']),
            ChangeRequest::STATUS_APPROVED_WITH_FEEDBACK,
            $user,
            $feedback
        );

        $this->activityLogs->log([
            'action' => 'wc.change_request.approve_with_feedback',
            'description' => 'Change request approved with feedback',
            'user' => $user,
            'subject' => $changeRequest,
            'request' => $request,
            'properties' => ['feedback' => $feedback],
        ]);

        return $changeRequest->fresh(['editor', 'approver', 'section', 'currentVersionRow']);
    }

    /**
     * @param  list<array{section_id:int, proposed_content:string, current_content?:string|null}>  $sectionEdits
     */
    public function resubmit(ChangeRequest $changeRequest, User $user, array $sectionEdits, ?Request $request = null): ChangeRequest
    {
        if (! $this->isOriginalEditorOrRemoteOperator($changeRequest, $user)) {
            throw new HttpException(403, 'Only the original editor can resubmit this request.');
        }

        if ($changeRequest->status !== ChangeRequest::STATUS_REJECTED) {
            throw new HttpException(409, 'Only rejected change requests can be resubmitted.');
        }

        $this->assertEditsWithinPriorVersion($changeRequest, $sectionEdits);

        $edits = $this->prepareAndLockEdits($sectionEdits, $user);
        $proposedContent = json_encode($edits);
        $nextVersion = ((int) ($changeRequest->current_version ?: 1)) + 1;
        $primarySectionId = count($edits) === 1 ? $edits[0]['section_id'] : null;

        $changeRequest->update([
            'section_id' => $primarySectionId,
            'proposed_content' => $proposedContent,
            'status' => ChangeRequest::STATUS_PENDING,
            'current_version' => $nextVersion,
            'approver_id' => null,
            'rejection_reason' => null,
            'feedback' => null,
            'scheduled_at' => null,
        ]);

        ChangeRequestVersion::create([
            'request_id' => $changeRequest->id,
            'version_number' => $nextVersion,
            'proposed_content' => $proposedContent,
            'status' => ChangeRequest::STATUS_PENDING,
            'feedback' => null,
            'submitted_by' => $this->gate->tenantUserIdOrNull($user),
            'submitted_at' => now(),
        ]);

        $this->activityLogs->log([
            'action' => 'wc.change_request.resubmit',
            'description' => 'Resubmitted change request as version '.$nextVersion,
            'user' => $user,
            'subject' => $changeRequest,
            'request' => $request,
        ]);

        return $changeRequest->fresh(['editor', 'approver', 'section', 'currentVersionRow', 'versions']);
    }

    /**
     * @param  list<array{section_id:int, proposed_content:string, current_content?:string|null}>|null  $sectionEdits
     * @return array{success: bool, cpanel_synced: bool, sections: array, change_request: ChangeRequest}
     */
    public function confirmFeedback(ChangeRequest $changeRequest, User $user, ?array $sectionEdits = null, ?Request $request = null): array
    {
        if (! $this->isOriginalEditorOrRemoteOperator($changeRequest, $user)) {
            throw new HttpException(403, 'Only the original editor can confirm feedback.');
        }

        if ($changeRequest->status !== ChangeRequest::STATUS_APPROVED_WITH_FEEDBACK) {
            throw new HttpException(409, 'Only requests approved with feedback can be confirmed.');
        }

        $nextVersion = ((int) ($changeRequest->current_version ?: 1)) + 1;

        if ($sectionEdits !== null) {
            if ($sectionEdits === []) {
                throw ValidationException::withMessages([
                    'section_edits' => ['At least one section edit is required when revising before confirm.'],
                ]);
            }

            $this->assertEditsWithinPriorVersion($changeRequest, $sectionEdits);

            $edits = $this->prepareAndLockEdits($sectionEdits, $user);
            $proposedContent = json_encode($edits);
            $primarySectionId = count($edits) === 1 ? $edits[0]['section_id'] : null;

            $changeRequest->update([
                'section_id' => $primarySectionId,
                'proposed_content' => $proposedContent,
                'current_version' => $nextVersion,
                'feedback' => null,
            ]);

            ChangeRequestVersion::create([
                'request_id' => $changeRequest->id,
                'version_number' => $nextVersion,
                'proposed_content' => $proposedContent,
                'status' => ChangeRequest::STATUS_PENDING,
                'feedback' => null,
                'submitted_by' => $this->gate->tenantUserIdOrNull($user),
                'submitted_at' => now(),
            ]);
        } else {
            // Confirm without content changes still creates a new version (match SMC/GC).
            $proposedContent = $changeRequest->resolvedProposedContent();

            $changeRequest->update([
                'proposed_content' => $proposedContent,
                'current_version' => $nextVersion,
                'feedback' => null,
            ]);

            ChangeRequestVersion::create([
                'request_id' => $changeRequest->id,
                'version_number' => $nextVersion,
                'proposed_content' => $proposedContent,
                'status' => ChangeRequest::STATUS_PENDING,
                'feedback' => null,
                'submitted_by' => $this->gate->tenantUserIdOrNull($user),
                'submitted_at' => now(),
            ]);

            // Re-lock briefly so publish unlock path stays consistent.
            $decoded = json_decode((string) $proposedContent, true);
            if (is_array($decoded)) {
                foreach ($decoded as $editItem) {
                    if (! isset($editItem['section_id'])) {
                        continue;
                    }
                    $sec = Section::find($editItem['section_id']);
                    if ($sec) {
                        $sec->update(['is_locked' => true, 'locked_by' => null]);
                    }
                }
            }
        }

        $changeRequest = $changeRequest->fresh(['section', 'currentVersionRow']);
        $result = ChangeRequestPublishService::publish($changeRequest, $user->id);

        $this->activityLogs->log([
            'action' => 'wc.change_request.confirm_feedback',
            'description' => 'Confirmed approved-with-feedback and published as version '.$nextVersion,
            'user' => $user,
            'subject' => $changeRequest,
            'request' => $request,
            'properties' => [
                'version' => $nextVersion,
                'revised' => $sectionEdits !== null,
            ],
        ]);

        return [
            ...$result,
            'change_request' => $changeRequest->fresh(['editor', 'approver', 'section', 'currentVersionRow', 'versions']),
        ];
    }

    public function assertReviewable(ChangeRequest $changeRequest, User $user): void
    {
        $canViewAll = $this->gate->can($user, 'wc_view_all_change_requests')
            && (string) $user->role !== User::ROLE_APPROVER;

        if (
            ! $canViewAll
            && ! $this->gate->isRemoteControlPlaneOperator($user)
            && $changeRequest->approver_id !== null
            && (int) $changeRequest->approver_id !== (int) ($this->gate->tenantUserIdOrNull($user) ?? $user->id)
        ) {
            throw new HttpException(403, 'Unauthorized');
        }

        if (! $changeRequest->relationLoaded('editor')) {
            $changeRequest->load('editor:id,firm_id');
        }

        if (! $this->firmVisibility->actorCanViewRequest(
            $user,
            $changeRequest->editor_id ? (int) $changeRequest->editor_id : null,
            $changeRequest->editor?->firm_id ? (int) $changeRequest->editor->firm_id : null,
            $changeRequest->approver_id ? (int) $changeRequest->approver_id : null
        )) {
            throw new HttpException(403, 'Unauthorized');
        }

        if (! in_array($changeRequest->status, [
            ChangeRequest::STATUS_PENDING,
            ChangeRequest::STATUS_UNDER_REVIEW,
            ChangeRequest::STATUS_SCHEDULED,
        ], true)) {
            throw new HttpException(409, 'This request can no longer be reviewed.');
        }
    }

    /**
     * When revising a previous version, only sections from that version may be edited.
     *
     * @param  list<array{section_id:int, proposed_content:string, current_content?:string|null}>  $sectionEdits
     */
    public function assertEditsWithinPriorVersion(ChangeRequest $changeRequest, array $sectionEdits): void
    {
        $allowed = $changeRequest->sectionIdsFromProposedContent();
        if ($allowed === []) {
            return;
        }

        $allowedLookup = array_fill_keys($allowed, true);
        $invalidIds = [];

        foreach ($sectionEdits as $edit) {
            $sectionId = (int) ($edit['section_id'] ?? 0);
            if ($sectionId < 1 || ! isset($allowedLookup[$sectionId])) {
                $invalidIds[] = $sectionId;
            }
        }

        if ($invalidIds === []) {
            return;
        }

        throw ValidationException::withMessages([
            'section_edits' => [
                'Only sections from the previous version can be edited. New sections are not allowed.',
            ],
            'section_edits.*.section_id' => [
                'Invalid section id(s): '.implode(', ', array_values(array_unique($invalidIds))).'. Allowed: '.implode(', ', $allowed).'.',
            ],
        ]);
    }

    private function isOriginalEditorOrRemoteOperator(ChangeRequest $changeRequest, User $user): bool
    {
        if ($this->gate->isRemoteControlPlaneOperator($user)) {
            return true;
        }

        return (int) $changeRequest->editor_id === (int) $user->id;
    }
}
