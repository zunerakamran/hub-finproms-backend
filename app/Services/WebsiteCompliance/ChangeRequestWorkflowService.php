<?php

namespace App\Services\WebsiteCompliance;

use App\Models\ComplianceAuditEvent;
use App\Models\User;
use App\Models\WebsiteCompliance\ChangeRequest;
use App\Models\WebsiteCompliance\ChangeRequestAttachment;
use App\Models\WebsiteCompliance\ChangeRequestVersion;
use App\Models\WebsiteCompliance\Section;
use App\Support\ComplianceSupportingFiles;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use App\Services\ActingAdvisorService;
use App\Services\ActivityLogService;
use App\Services\ComplianceAuditTrailService;
use App\Services\FirmComplianceVisibilityService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ChangeRequestWorkflowService
{
    public function __construct(
        private readonly ActivityLogService $activityLogs,
        private readonly ComplianceAuditTrailService $auditTrail,
        private readonly WebsiteComplianceGate $gate,
        private readonly FirmComplianceVisibilityService $firmVisibility
    ) {}

    /**
     * Validate section edits, lock sections, and return normalized edit rows.
     *
     * @param  list<array{section_id:int, proposed_content:string, current_content?:string|null}>  $sectionEdits
     * @return list<array{section_id:int, section_name:string, current_content:mixed, proposed_content:string}>
     */
    public function prepareAndLockEdits(array $sectionEdits, User $user, ?User $actor = null): array
    {
        $edits = [];
        $actor = $actor ?? $user;
        $actingAdvisors = app(ActingAdvisorService::class);
        $canBypassLocks = $this->gate->isRemoteControlPlaneOperator($actor)
            || $this->gate->isRemoteControlPlaneOperator($user);

        foreach ($sectionEdits as $edit) {
            $section = Section::findOrFail($edit['section_id']);
            if (
                $section->is_locked
                && ! $canBypassLocks
                && $section->locked_by !== null
                && ! $actingAdvisors->mayOwnLock($actor, $section->locked_by)
                && ! $actingAdvisors->mayOwnLock($user, $section->locked_by)
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

    /**
     * @param  list<UploadedFile>  $supportingFiles
     */
    public function createVersionOne(
        ChangeRequest $changeRequest,
        string $proposedContent,
        string $status = ChangeRequest::STATUS_PENDING,
        array $supportingFiles = [],
        ?User $uploader = null,
        string $source = 'submit'
    ): ChangeRequestVersion {
        $version = ChangeRequestVersion::create([
            'request_id' => $changeRequest->id,
            'version_number' => 1,
            'proposed_content' => $proposedContent,
            'status' => $status,
            'feedback' => null,
            'submitted_by' => $changeRequest->editor_id,
            'submitted_at' => now(),
        ]);

        if ($supportingFiles !== []) {
            ComplianceSupportingFiles::assertWithinLimits($supportingFiles);
            $this->storeSupportingFilesForVersion($version, $supportingFiles, $uploader, $source);
        }

        return $version;
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

    /**
     * @param  list<UploadedFile>  $supportingFiles
     */
    public function approveWithFeedback(
        ChangeRequest $changeRequest,
        User $user,
        string $feedback,
        array $supportingFiles = [],
        ?Request $request = null
    ): ChangeRequest {
        $this->assertReviewable($changeRequest, $user);

        $fromStatus = (string) $changeRequest->status;
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

        $version = $changeRequest->fresh(['currentVersionRow.supportingFiles'])->currentVersionRow;
        if ($version) {
            $this->appendSupportingFilesToVersion($version, $supportingFiles, $user, 'approve_with_feedback');
        }

        $eventDescription = 'Change request #'.$changeRequest->id.' approved with feedback';

        $this->activityLogs->log([
            'action' => 'wc.change_request.approve_with_feedback',
            'description' => $eventDescription,
            'user' => $user,
            'subject' => $changeRequest,
            'request' => $request,
            'properties' => ['feedback' => $feedback],
        ]);

        $this->auditTrail->record([
            'module' => ComplianceAuditEvent::MODULE_WC,
            'subject' => $changeRequest,
            'event_type' => ComplianceAuditEvent::EVENT_REVIEWED,
            'actor' => $user,
            'description' => $eventDescription,
            'from_status' => $fromStatus,
            'to_status' => ChangeRequest::STATUS_APPROVED_WITH_FEEDBACK,
            'version_number' => $changeRequest->current_version,
            'metadata' => ['feedback' => $feedback],
        ]);

        return $changeRequest->fresh(['editor', 'approver', 'section', 'currentVersionRow.supportingFiles']);
    }

    /**
     * Manager-style status override: creates a new version with the chosen status + comment.
     * Content is copied from the current version. Not allowed when published or scheduled.
     *
     * @param  array{status: string, comment?: ?string}  $data
     */
    public function changeStatus(
        ChangeRequest $changeRequest,
        User $user,
        array $data,
        ?Request $request = null
    ): ChangeRequest {
        if (! $this->gate->can($user, 'wc_change_request_status')) {
            throw new HttpException(403, 'You do not have permission to change website compliance request status.');
        }

        if (! $changeRequest->relationLoaded('editor')) {
            $changeRequest->load('editor:id,firm_id');
        }

        $this->firmVisibility->assertActorCanActOnRequest(
            $user,
            $changeRequest->editor_id ? (int) $changeRequest->editor_id : null,
            $changeRequest->editor?->firm_id ? (int) $changeRequest->editor->firm_id : null,
            $changeRequest->approver_id ? (int) $changeRequest->approver_id : null
        );

        $currentStatus = (string) $changeRequest->status;
        // Locked once live on the website, or once a publish schedule is set.
        if (in_array($currentStatus, [
            ChangeRequest::STATUS_APPROVED,
            ChangeRequest::STATUS_SCHEDULED,
        ], true)) {
            throw ValidationException::withMessages([
                'status' => 'Status cannot be changed once content is published to the website or a publish schedule is set.',
            ]);
        }

        $allowedTargets = [
            ChangeRequest::STATUS_PENDING,
            ChangeRequest::STATUS_APPROVED,
            ChangeRequest::STATUS_REJECTED,
            ChangeRequest::STATUS_APPROVED_WITH_FEEDBACK,
        ];

        $status = (string) ($data['status'] ?? '');
        if (! in_array($status, $allowedTargets, true)) {
            throw ValidationException::withMessages([
                'status' => 'Invalid status. Use pending, approved, rejected, or approved_with_feedback.',
            ]);
        }

        $current = $changeRequest->currentVersionRow
            ?? $changeRequest->versions()->orderByDesc('version_number')->first();
        if (! $current) {
            throw ValidationException::withMessages([
                'version' => 'Current version is missing.',
            ]);
        }

        $comment = trim((string) ($data['comment'] ?? ''));
        $nextVersion = ((int) ($changeRequest->current_version ?: 1)) + 1;
        $proposedContent = $current->proposed_content ?? $changeRequest->proposed_content;

        if (in_array($status, [
            ChangeRequest::STATUS_REJECTED,
            ChangeRequest::STATUS_APPROVED_WITH_FEEDBACK,
            ChangeRequest::STATUS_PENDING,
        ], true)) {
            $changeRequest->loadMissing('section');
            $this->unlockSectionsFromProposedContent(
                (string) $proposedContent,
                $changeRequest->section
            );
        }

        $changeRequest->update([
            'proposed_content' => $proposedContent,
            'status' => $status,
            'current_version' => $nextVersion,
            'feedback' => $comment !== '' ? $comment : null,
            'rejection_reason' => $status === ChangeRequest::STATUS_REJECTED
                ? ($comment !== '' ? $comment : $changeRequest->rejection_reason)
                : null,
            'scheduled_at' => null,
        ]);

        $newVersionRow = ChangeRequestVersion::create([
            'request_id' => $changeRequest->id,
            'version_number' => $nextVersion,
            'proposed_content' => $proposedContent,
            'status' => $status,
            'feedback' => $comment !== '' ? $comment : null,
            'submitted_by' => $current->submitted_by,
            'submitted_at' => $current->submitted_at ?? now(),
            'reviewed_by' => $user->name ?: (string) $user->id,
            'reviewed_at' => now(),
        ]);

        $newUploads = ComplianceSupportingFiles::normalize($data['supporting_files'] ?? []);
        ComplianceSupportingFiles::assertWithinLimits($newUploads);
        $this->copySupportingFilesFromVersion($current, $newVersionRow);
        if ($newUploads !== []) {
            $newVersionRow->loadMissing('supportingFiles');
            $nextOrder = $newVersionRow->supportingFiles->isEmpty()
                ? 0
                : ((int) $newVersionRow->supportingFiles->max('sort_order')) + 1;
            $this->storeSupportingFilesForVersion($newVersionRow, $newUploads, $user, 'change_status', $nextOrder);
        }

        if ($status === ChangeRequest::STATUS_APPROVED) {
            ChangeRequestPublishService::publish(
                $changeRequest->fresh(['section', 'currentVersionRow']),
                (int) $user->id
            );
        }

        $eventDescription = 'Changed website compliance request #'.$changeRequest->id
            .' status to '.$status.' (v'.$nextVersion.')';

        $this->activityLogs->log([
            'action' => 'wc.change_request.change_status',
            'description' => $eventDescription,
            'user' => $user,
            'subject' => $changeRequest,
            'request' => $request,
            'properties' => [
                'status' => $status,
                'version' => $nextVersion,
                'has_comment' => $comment !== '',
                'previous_status' => $currentStatus,
            ],
        ]);

        $this->auditTrail->record([
            'module' => ComplianceAuditEvent::MODULE_WC,
            'subject' => $changeRequest,
            'event_type' => ComplianceAuditEvent::EVENT_STATUS_CHANGED,
            'actor' => $user,
            'description' => $eventDescription,
            'from_status' => $currentStatus,
            'to_status' => $status,
            'version_number' => $nextVersion,
            'metadata' => [
                'has_comment' => $comment !== '',
                'comment' => $comment !== '' ? $comment : null,
            ],
        ]);

        return $changeRequest->fresh(['editor', 'approver', 'section', 'currentVersionRow.supportingFiles', 'versions.supportingFiles']);
    }

    /**
     * @param  list<array{section_id:int, proposed_content:string, current_content?:string|null}>  $sectionEdits
     * @param  list<UploadedFile>  $supportingFiles
     */
    public function resubmit(
        ChangeRequest $changeRequest,
        User $user,
        array $sectionEdits,
        array $supportingFiles = [],
        ?Request $request = null
    ): ChangeRequest {
        if (! $this->isOriginalEditorOrRemoteOperator($changeRequest, $user)) {
            throw new HttpException(403, 'Only the original editor can resubmit this request.');
        }

        if ($changeRequest->status !== ChangeRequest::STATUS_REJECTED) {
            throw new HttpException(409, 'Only rejected change requests can be resubmitted.');
        }

        $this->assertEditsWithinPriorVersion($changeRequest, $sectionEdits);

        $current = $changeRequest->currentVersionRow
            ?? $changeRequest->versions()->orderByDesc('version_number')->first();
        $normalizedSupporting = ComplianceSupportingFiles::normalize($supportingFiles);
        ComplianceSupportingFiles::assertWithinLimits($normalizedSupporting);
        $hasNewSupporting = $normalizedSupporting !== [];

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

        $newVersionRow = ChangeRequestVersion::create([
            'request_id' => $changeRequest->id,
            'version_number' => $nextVersion,
            'proposed_content' => $proposedContent,
            'status' => ChangeRequest::STATUS_PENDING,
            'feedback' => null,
            'submitted_by' => $this->gate->tenantUserIdOrNull($user),
            'submitted_at' => now(),
        ]);

        if ($current) {
            if ($hasNewSupporting) {
                $this->storeSupportingFilesForVersion($newVersionRow, $normalizedSupporting, $user, 'resubmit');
            } else {
                $this->copySupportingFilesFromVersion($current, $newVersionRow);
            }
        } elseif ($hasNewSupporting) {
            $this->storeSupportingFilesForVersion($newVersionRow, $normalizedSupporting, $user, 'resubmit');
        }

        $eventDescription = 'Resubmitted change request #'.$changeRequest->id.' as version '.$nextVersion;

        $this->activityLogs->log([
            'action' => 'wc.change_request.resubmit',
            'description' => $eventDescription,
            'user' => $user,
            'subject' => $changeRequest,
            'request' => $request,
        ]);

        $this->auditTrail->record([
            'module' => ComplianceAuditEvent::MODULE_WC,
            'subject' => $changeRequest,
            'event_type' => ComplianceAuditEvent::EVENT_RESUBMITTED,
            'actor' => $user,
            'description' => $eventDescription,
            'from_status' => ChangeRequest::STATUS_REJECTED,
            'to_status' => ChangeRequest::STATUS_PENDING,
            'version_number' => $nextVersion,
        ]);

        return $changeRequest->fresh(['editor', 'approver', 'section', 'currentVersionRow.supportingFiles', 'versions.supportingFiles']);
    }

    /**
     * @param  list<array{section_id:int, proposed_content:string, current_content?:string|null}>|null  $sectionEdits
     * @param  list<UploadedFile>  $supportingFiles
     * @return array{success: bool, cpanel_synced: bool, sections: array, change_request: ChangeRequest}
     */
    public function confirmFeedback(
        ChangeRequest $changeRequest,
        User $user,
        ?array $sectionEdits = null,
        array $supportingFiles = [],
        ?Request $request = null
    ): array {
        if (! $this->isOriginalEditorOrRemoteOperator($changeRequest, $user)) {
            throw new HttpException(403, 'Only the original editor can confirm feedback.');
        }

        if ($changeRequest->status !== ChangeRequest::STATUS_APPROVED_WITH_FEEDBACK) {
            throw new HttpException(409, 'Only requests approved with feedback can be confirmed.');
        }

        $nextVersion = ((int) ($changeRequest->current_version ?: 1)) + 1;
        $current = $changeRequest->currentVersionRow
            ?? $changeRequest->versions()->orderByDesc('version_number')->first();
        $normalizedSupporting = ComplianceSupportingFiles::normalize($supportingFiles);
        ComplianceSupportingFiles::assertWithinLimits($normalizedSupporting);
        $hasNewSupporting = $normalizedSupporting !== [];

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

            $newVersionRow = ChangeRequestVersion::create([
                'request_id' => $changeRequest->id,
                'version_number' => $nextVersion,
                'proposed_content' => $proposedContent,
                'status' => ChangeRequest::STATUS_PENDING,
                'feedback' => null,
                'submitted_by' => $this->gate->tenantUserIdOrNull($user),
                'submitted_at' => now(),
            ]);

            if ($current) {
                if ($hasNewSupporting) {
                    $this->storeSupportingFilesForVersion($newVersionRow, $normalizedSupporting, $user, 'confirm_feedback');
                } else {
                    $this->copySupportingFilesFromVersion($current, $newVersionRow);
                }
            } elseif ($hasNewSupporting) {
                $this->storeSupportingFilesForVersion($newVersionRow, $normalizedSupporting, $user, 'confirm_feedback');
            }
        } else {
            // Confirm without content changes still creates a new version (match SMC/GC).
            $proposedContent = $changeRequest->resolvedProposedContent();

            $changeRequest->update([
                'proposed_content' => $proposedContent,
                'current_version' => $nextVersion,
                'feedback' => null,
            ]);

            $newVersionRow = ChangeRequestVersion::create([
                'request_id' => $changeRequest->id,
                'version_number' => $nextVersion,
                'proposed_content' => $proposedContent,
                'status' => ChangeRequest::STATUS_PENDING,
                'feedback' => null,
                'submitted_by' => $this->gate->tenantUserIdOrNull($user),
                'submitted_at' => now(),
            ]);

            if ($current) {
                if ($hasNewSupporting) {
                    $this->storeSupportingFilesForVersion($newVersionRow, $normalizedSupporting, $user, 'confirm_feedback');
                } else {
                    $this->copySupportingFilesFromVersion($current, $newVersionRow);
                }
            } elseif ($hasNewSupporting) {
                $this->storeSupportingFilesForVersion($newVersionRow, $normalizedSupporting, $user, 'confirm_feedback');
            }

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

        $eventDescription = 'Confirmed approved-with-feedback and published change request #'
            .$changeRequest->id.' as version '.$nextVersion;

        $this->activityLogs->log([
            'action' => 'wc.change_request.confirm_feedback',
            'description' => $eventDescription,
            'user' => $user,
            'subject' => $changeRequest,
            'request' => $request,
            'properties' => [
                'version' => $nextVersion,
                'revised' => $sectionEdits !== null,
            ],
        ]);

        $this->auditTrail->record([
            'module' => ComplianceAuditEvent::MODULE_WC,
            'subject' => $changeRequest,
            'event_type' => ComplianceAuditEvent::EVENT_FEEDBACK_CONFIRMED,
            'actor' => $user,
            'description' => $eventDescription,
            'from_status' => ChangeRequest::STATUS_APPROVED_WITH_FEEDBACK,
            'to_status' => ChangeRequest::STATUS_APPROVED,
            'version_number' => $nextVersion,
            'metadata' => [
                'revised' => $sectionEdits !== null,
                'cpanel_synced' => $result['cpanel_synced'] ?? null,
            ],
        ]);

        return [
            ...$result,
            'change_request' => $changeRequest->fresh(['editor', 'approver', 'section', 'currentVersionRow.supportingFiles', 'versions.supportingFiles']),
        ];
    }

    /**
     * @param  list<UploadedFile>  $files
     */
    public function appendSupportingFilesToVersion(
        ChangeRequestVersion $version,
        array $files,
        ?User $uploader = null,
        ?string $source = null
    ): void {
        $normalized = ComplianceSupportingFiles::normalize($files);
        ComplianceSupportingFiles::assertWithinLimits($normalized);
        if ($normalized === []) {
            return;
        }

        $version->loadMissing('supportingFiles');
        $nextOrder = $version->supportingFiles->isEmpty()
            ? 0
            : ((int) $version->supportingFiles->max('sort_order')) + 1;
        $this->storeSupportingFilesForVersion($version, $normalized, $uploader, $source, $nextOrder);
    }

    /**
     * @param  list<UploadedFile>  $files
     */
    private function storeSupportingFilesForVersion(
        ChangeRequestVersion $version,
        array $files,
        ?User $uploader = null,
        ?string $source = null,
        ?int $startOrder = null
    ): void {
        $baseOrder = $startOrder ?? 0;
        $attribution = ComplianceSupportingFiles::attributionPayload($uploader, $source);

        foreach (array_values($files) as $index => $file) {
            $path = $file->store('website-compliance-files', 'public');
            ChangeRequestAttachment::query()->create([
                'version_id' => $version->id,
                'original_name' => $file->getClientOriginalName(),
                'file_path' => $path,
                'file_url' => Storage::disk('public')->url($path),
                'mime_type' => $file->getClientMimeType() ?: $file->getMimeType(),
                'size_bytes' => (int) $file->getSize(),
                'sort_order' => $baseOrder + $index,
                'uploaded_by_user_id' => $attribution['uploaded_by_user_id'],
                'uploaded_by_name' => $attribution['uploaded_by_name'],
                'source' => $attribution['source'],
            ]);
        }
    }

    private function copySupportingFilesFromVersion(
        ChangeRequestVersion $from,
        ChangeRequestVersion $to
    ): void {
        $from->loadMissing('supportingFiles');
        foreach ($from->supportingFiles as $index => $attachment) {
            ChangeRequestAttachment::query()->create([
                'version_id' => $to->id,
                'original_name' => $attachment->original_name,
                'file_path' => $attachment->file_path,
                'file_url' => $attachment->file_url,
                'mime_type' => $attachment->mime_type,
                'size_bytes' => $attachment->size_bytes,
                'sort_order' => $attachment->sort_order ?? $index,
                'uploaded_by_user_id' => $attachment->uploaded_by_user_id,
                'uploaded_by_name' => $attachment->uploaded_by_name,
                'source' => $attachment->source,
            ]);
        }
    }

    public function assertReviewable(ChangeRequest $changeRequest, User $user): void
    {
        $canViewAll = $this->gate->can($user, 'wc_view_all_change_requests')
            && (string) $user->role !== User::ROLE_APPROVER;
        $canOverrideStatus = $this->gate->can($user, 'wc_change_request_status');

        if (
            ! $canViewAll
            && ! $canOverrideStatus
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

        if (in_array($changeRequest->status, [
            ChangeRequest::STATUS_APPROVED,
            ChangeRequest::STATUS_SCHEDULED,
        ], true)) {
            throw new HttpException(
                409,
                'Status cannot be changed once content is published to the website or a publish schedule is set.'
            );
        }

        // Managers with change-status may still act on rejected / approved-with-feedback.
        $allowedStatuses = $canOverrideStatus
            ? [
                ChangeRequest::STATUS_PENDING,
                ChangeRequest::STATUS_UNDER_REVIEW,
                ChangeRequest::STATUS_REJECTED,
                ChangeRequest::STATUS_APPROVED_WITH_FEEDBACK,
            ]
            : [
                ChangeRequest::STATUS_PENDING,
                ChangeRequest::STATUS_UNDER_REVIEW,
            ];

        if (! in_array($changeRequest->status, $allowedStatuses, true)) {
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

        if ((int) $changeRequest->editor_id === (int) $user->id) {
            return true;
        }

        try {
            app(ActingAdvisorService::class)->assertCanManageOwnedBy($user, (int) $changeRequest->editor_id);

            return true;
        } catch (\Illuminate\Validation\ValidationException) {
            return false;
        }
    }
}
