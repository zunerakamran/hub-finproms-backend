<?php

namespace App\Services;

use App\Models\ComplianceAuditEvent;
use App\Models\Firm;
use App\Models\GeneralComplianceContentType;
use App\Models\GeneralComplianceRequest;
use App\Models\GeneralComplianceRequestAttachment;
use App\Models\GeneralComplianceRequestVersion;
use App\Models\Hub;
use App\Models\User;
use App\Support\ComplianceSupportingFiles;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class GeneralComplianceService
{
    public const ATTACHMENT_MIMES = 'pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,jpg,jpeg,png,gif,webp,zip';

    public const MAX_ATTACHMENTS = 10;

    public const MAX_ATTACHMENT_KB = 10240;

    public function __construct(
        private readonly CapabilitiesMatrixService $matrix,
        private readonly GeneralComplianceMailService $mail,
        private readonly ActivityLogService $activityLogs,
        private readonly ComplianceAuditTrailService $auditTrail,
        private readonly FirmComplianceVisibilityService $firmVisibility,
        private readonly ActingAdvisorService $actingAdvisors
    ) {}

    public function assertModuleEnabled(Hub $hub): void
    {
        if (! $hub->hasGeneralComplianceModule()) {
            throw ValidationException::withMessages([
                'module' => 'General Compliance is not enabled for this hub.',
            ]);
        }
    }

    /**
     * Users whose role currently has gc_review_requests on this hub.
     *
     * @return Collection<int, User>
     */
    public function reviewersForHub(Hub $hub, ?Firm $submitterFirm = null, bool $filterByFirm = false): Collection
    {
        $roles = array_values(array_filter(
            CapabilitiesMatrixService::MATRIX_ROLES,
            fn (string $role) => $this->matrix->roleCan($hub, $role, 'gc_review_requests')
        ));

        if ($roles === []) {
            return collect();
        }

        $reviewers = User::query()
            ->with('firm:id,name,is_central')
            ->whereIn('role', $roles)
            ->where(function ($q) {
                $q->where('is_suspended', false)->orWhereNull('is_suspended');
            })
            ->where(function ($q) {
                $q->where('is_discontinued', false)->orWhereNull('is_discontinued');
            })
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role', 'firm_id']);

        if ($filterByFirm) {
            return $this->firmVisibility->filterAssigneesForSubmitterFirm($reviewers, $submitterFirm);
        }

        return $reviewers;
    }

    /**
     * @param  array{
     *   description: string,
     *   content_type: string,
     *   attachments?: list<UploadedFile>,
     *   supporting_files?: list<UploadedFile>
     * }  $data
     */
    public function submit(Hub $hub, User $user, array $data, $request = null): GeneralComplianceRequest
    {
        $this->assertModuleEnabled($hub);

        $subject = $this->actingAdvisors->requireSubject($user);
        $onBehalfById = $this->actingAdvisors->onBehalfById($user, $subject);

        $description = trim((string) ($data['description'] ?? ''));
        if ($description === '') {
            throw ValidationException::withMessages([
                'description' => 'A description is required.',
            ]);
        }

        $contentType = $this->assertValidContentType($data['content_type'] ?? null);

        $attachments = $this->attachmentsFromData($data);
        $supportingFiles = $this->supportingFilesFromData($data);
        $this->assertAttachmentLimits($attachments, 'attachments');
        ComplianceSupportingFiles::assertWithinLimits($supportingFiles);

        $compliance = DB::transaction(function () use ($subject, $user, $onBehalfById, $description, $contentType, $attachments, $supportingFiles) {
            $compliance = GeneralComplianceRequest::query()->create([
                'user_id' => $subject->id,
                'name' => $subject->name,
                'current_version' => 1,
                'submission_date' => now(),
                'on_behalf_by_user_id' => $onBehalfById,
            ]);

            $version = GeneralComplianceRequestVersion::query()->create([
                'request_id' => $compliance->id,
                'version_number' => 1,
                'description' => $description,
                'content_type' => $contentType,
                'submitted_by' => $user->id,
                'submitted_at' => now(),
                'status' => GeneralComplianceRequest::STATUS_PENDING,
                'feedback' => '',
                'on_behalf_by_user_id' => $onBehalfById,
            ]);

            if ($attachments !== []) {
                $this->storeFilesForVersion(
                    $version,
                    $attachments,
                    GeneralComplianceRequestAttachment::KIND_ATTACHMENT,
                    $user,
                    'submit'
                );
            }
            if ($supportingFiles !== []) {
                $this->storeFilesForVersion(
                    $version,
                    $supportingFiles,
                    GeneralComplianceRequestAttachment::KIND_SUPPORTING_FILE,
                    $user,
                    'submit'
                );
            }

            return $compliance->fresh([
                'currentVersionRow.attachments',
                'currentVersionRow.supportingFiles',
                'user',
                'onBehalfBy',
            ]);
        });

        $this->mail->notifyRequestSubmitted($compliance, $subject);

        $eventDescription = 'Submitted general compliance request #'.$compliance->id
            .($onBehalfById ? ' on behalf of user #'.$subject->id : '');

        $this->activityLogs->log([
            'action' => 'gc.submit',
            'description' => $eventDescription,
            'user' => $user,
            'hub' => $hub,
            'subject' => $compliance,
            'request' => $request,
            'status_code' => 201,
            'properties' => [
                'version' => 1,
                'status' => GeneralComplianceRequest::STATUS_PENDING,
                'content_type' => $contentType,
                'attachment_count' => count($attachments),
                'supporting_file_count' => count($supportingFiles),
                'on_behalf_of_user_id' => $onBehalfById ? $subject->id : null,
            ],
        ]);

        $this->auditTrail->record([
            'module' => ComplianceAuditEvent::MODULE_GC,
            'subject' => $compliance,
            'event_type' => ComplianceAuditEvent::EVENT_SUBMITTED,
            'actor' => $user,
            'hub' => $hub,
            'description' => $eventDescription,
            'to_status' => GeneralComplianceRequest::STATUS_PENDING,
            'version_number' => 1,
            'related_user' => $onBehalfById ? $subject : null,
            'metadata' => [
                'content_type' => $contentType,
                'attachment_count' => count($attachments),
                'supporting_file_count' => count($supportingFiles),
                'on_behalf_of_user_id' => $onBehalfById ? $subject->id : null,
            ],
        ]);

        return $compliance;
    }

    /**
     * @param  array{description: string, content_type: string, attachments?: list<UploadedFile>, supporting_files?: list<UploadedFile>}  $data
     */
    public function resubmit(Hub $hub, User $user, GeneralComplianceRequest $compliance, array $data, $request = null): GeneralComplianceRequest
    {
        $this->assertModuleEnabled($hub);
        $this->assertOwner($compliance, $user);

        $current = $compliance->currentVersionRow;
        if (! $current || $current->status !== GeneralComplianceRequest::STATUS_REJECTED) {
            throw ValidationException::withMessages([
                'status' => 'Only rejected requests can be resubmitted.',
            ]);
        }

        $description = trim((string) ($data['description'] ?? ''));
        if ($description === '') {
            throw ValidationException::withMessages([
                'description' => 'Please provide an updated description.',
            ]);
        }

        $contentType = $this->assertValidContentType(
            $data['content_type'] ?? $current->content_type
        );

        $attachments = $this->attachmentsFromData($data);
        $supportingFiles = $this->supportingFilesFromData($data);
        $this->assertAttachmentLimits($attachments, 'attachments');
        ComplianceSupportingFiles::assertWithinLimits($supportingFiles);
        $hasNewAttachments = $attachments !== [];
        $hasNewSupportingFiles = $supportingFiles !== [];

        $newVersion = (int) $compliance->current_version + 1;
        $onBehalfById = $this->actingAdvisors->onBehalfById($user, $this->actingAdvisors->requireSubject($user));

        DB::transaction(function () use (
            $compliance,
            $user,
            $description,
            $contentType,
            $attachments,
            $supportingFiles,
            $hasNewAttachments,
            $hasNewSupportingFiles,
            $current,
            $newVersion,
            $onBehalfById
        ) {
            $compliance->update([
                'current_version' => $newVersion,
                'on_behalf_by_user_id' => $onBehalfById ?? $compliance->on_behalf_by_user_id,
            ]);

            $version = GeneralComplianceRequestVersion::query()->create([
                'request_id' => $compliance->id,
                'version_number' => $newVersion,
                'description' => $description,
                'content_type' => $contentType,
                'submitted_by' => $user->id,
                'submitted_at' => now(),
                'status' => GeneralComplianceRequest::STATUS_PENDING,
                'feedback' => '',
                'on_behalf_by_user_id' => $onBehalfById,
            ]);

            if ($hasNewAttachments) {
                $this->storeFilesForVersion(
                    $version,
                    $attachments,
                    GeneralComplianceRequestAttachment::KIND_ATTACHMENT,
                    $user,
                    'resubmit'
                );
            } else {
                $this->copyFilesFromVersion(
                    $current,
                    $version,
                    GeneralComplianceRequestAttachment::KIND_ATTACHMENT
                );
            }

            if ($hasNewSupportingFiles) {
                $this->storeFilesForVersion(
                    $version,
                    $supportingFiles,
                    GeneralComplianceRequestAttachment::KIND_SUPPORTING_FILE,
                    $user,
                    'resubmit'
                );
            } else {
                $this->copyFilesFromVersion(
                    $current,
                    $version,
                    GeneralComplianceRequestAttachment::KIND_SUPPORTING_FILE
                );
            }
        });

        $compliance = $compliance->fresh([
            'currentVersionRow.attachments',
            'currentVersionRow.supportingFiles',
            'assignee',
            'user',
            'onBehalfBy',
        ]);

        if ($compliance->assignee) {
            $this->mail->notifyResubmitted($compliance, $compliance->assignee, $user);
        }

        $eventDescription = 'Resubmitted general compliance request #'.$compliance->id.' as v'.$newVersion;

        $this->activityLogs->log([
            'action' => 'gc.resubmit',
            'description' => $eventDescription,
            'user' => $user,
            'hub' => $hub,
            'subject' => $compliance,
            'request' => $request,
            'status_code' => 200,
            'properties' => [
                'version' => $newVersion,
                'status' => GeneralComplianceRequest::STATUS_PENDING,
                'content_type' => $contentType,
                'assigned_to' => $compliance->assigned_to,
                'new_attachments' => $hasNewAttachments,
                'new_supporting_files' => $hasNewSupportingFiles,
            ],
        ]);

        $this->auditTrail->record([
            'module' => ComplianceAuditEvent::MODULE_GC,
            'subject' => $compliance,
            'event_type' => ComplianceAuditEvent::EVENT_RESUBMITTED,
            'actor' => $user,
            'hub' => $hub,
            'description' => $eventDescription,
            'from_status' => GeneralComplianceRequest::STATUS_REJECTED,
            'to_status' => GeneralComplianceRequest::STATUS_PENDING,
            'version_number' => $newVersion,
            'related_user' => $compliance->assignee,
            'metadata' => [
                'content_type' => $contentType,
                'assigned_to' => $compliance->assigned_to,
                'new_attachments' => $hasNewAttachments,
                'new_supporting_files' => $hasNewSupportingFiles,
            ],
        ]);

        return $compliance;
    }

    /**
     * Confirm or re-upload after "Approved with Feedback".
     *
     * @param  array{attachments?: list<UploadedFile>, supporting_files?: list<UploadedFile>}  $data
     */
    public function confirmApprovedWithFeedback(
        Hub $hub,
        User $user,
        GeneralComplianceRequest $compliance,
        array $data,
        $request = null
    ): GeneralComplianceRequest {
        $this->assertModuleEnabled($hub);
        $this->assertOwner($compliance, $user);

        $current = $compliance->currentVersionRow;
        if (! $current || $current->status !== GeneralComplianceRequest::STATUS_APPROVED_WITH_FEEDBACK) {
            throw ValidationException::withMessages([
                'status' => 'Only requests approved with feedback can be confirmed this way.',
            ]);
        }

        $attachments = $this->attachmentsFromData($data);
        $supportingFiles = $this->supportingFilesFromData($data);
        $this->assertAttachmentLimits($attachments, 'attachments');
        ComplianceSupportingFiles::assertWithinLimits($supportingFiles);
        $hasNewAttachments = $attachments !== [];
        $hasNewSupportingFiles = $supportingFiles !== [];
        $hasNewFiles = $hasNewAttachments || $hasNewSupportingFiles;

        $newVersion = (int) $compliance->current_version + 1;
        $feedback = $hasNewFiles ? $current->feedback : '';

        DB::transaction(function () use (
            $compliance,
            $user,
            $current,
            $newVersion,
            $attachments,
            $supportingFiles,
            $hasNewAttachments,
            $hasNewSupportingFiles,
            $feedback
        ) {
            $onBehalfById = $this->actingAdvisors->onBehalfById(
                $user,
                $this->actingAdvisors->requireSubject($user)
            );

            $compliance->update([
                'current_version' => $newVersion,
                'on_behalf_by_user_id' => $onBehalfById ?? $compliance->on_behalf_by_user_id,
            ]);

            $version = GeneralComplianceRequestVersion::query()->create([
                'request_id' => $compliance->id,
                'version_number' => $newVersion,
                'description' => $current->description,
                'content_type' => $current->content_type,
                'submitted_by' => $user->id,
                'submitted_at' => now(),
                'status' => GeneralComplianceRequest::STATUS_APPROVED,
                'feedback' => $feedback,
                'reviewed_by' => $current->reviewed_by,
                'reviewed_at' => $current->reviewed_at,
                'on_behalf_by_user_id' => $onBehalfById,
            ]);

            if ($hasNewAttachments) {
                $this->storeFilesForVersion(
                    $version,
                    $attachments,
                    GeneralComplianceRequestAttachment::KIND_ATTACHMENT,
                    $user,
                    'confirm_feedback'
                );
            } else {
                $this->copyFilesFromVersion(
                    $current,
                    $version,
                    GeneralComplianceRequestAttachment::KIND_ATTACHMENT
                );
            }

            if ($hasNewSupportingFiles) {
                $this->storeFilesForVersion(
                    $version,
                    $supportingFiles,
                    GeneralComplianceRequestAttachment::KIND_SUPPORTING_FILE,
                    $user,
                    'confirm_feedback'
                );
            } else {
                $this->copyFilesFromVersion(
                    $current,
                    $version,
                    GeneralComplianceRequestAttachment::KIND_SUPPORTING_FILE
                );
            }
        });

        $compliance = $compliance->fresh([
            'currentVersionRow.attachments',
            'currentVersionRow.supportingFiles',
            'user',
            'onBehalfBy',
        ]);

        $eventDescription = 'Confirmed approved-with-feedback for request #'.$compliance->id.' as v'.$newVersion;

        $this->activityLogs->log([
            'action' => 'gc.confirm_feedback',
            'description' => $eventDescription,
            'user' => $user,
            'hub' => $hub,
            'subject' => $compliance,
            'request' => $request,
            'status_code' => 200,
            'properties' => [
                'version' => $newVersion,
                'new_attachments' => $hasNewAttachments,
                'new_supporting_files' => $hasNewSupportingFiles,
                'status' => GeneralComplianceRequest::STATUS_APPROVED,
            ],
        ]);

        $this->auditTrail->record([
            'module' => ComplianceAuditEvent::MODULE_GC,
            'subject' => $compliance,
            'event_type' => ComplianceAuditEvent::EVENT_FEEDBACK_CONFIRMED,
            'actor' => $user,
            'hub' => $hub,
            'description' => $eventDescription,
            'from_status' => GeneralComplianceRequest::STATUS_APPROVED_WITH_FEEDBACK,
            'to_status' => GeneralComplianceRequest::STATUS_APPROVED,
            'version_number' => $newVersion,
            'metadata' => [
                'new_attachments' => $hasNewAttachments,
                'new_supporting_files' => $hasNewSupportingFiles,
            ],
        ]);

        return $compliance;
    }

    public function assign(
        Hub $hub,
        User $actor,
        GeneralComplianceRequest $compliance,
        ?int $assignTo,
        $request = null
    ): GeneralComplianceRequest {
        $this->assertModuleEnabled($hub);

        $canAssign = $this->matrix->roleCan($hub, (string) $actor->role, 'gc_assign_requests');
        $canReview = $this->matrix->roleCan($hub, (string) $actor->role, 'gc_review_requests');

        if (! $canAssign && ! $canReview) {
            throw ValidationException::withMessages([
                'capability' => 'You do not have permission to assign general compliance requests.',
            ]);
        }

        if (! $compliance->relationLoaded('user')) {
            $compliance->load('user:id,firm_id');
        }
        $this->firmVisibility->assertActorCanActOnRequest(
            $actor,
            (int) $compliance->user_id,
            $compliance->user?->firm_id ? (int) $compliance->user->firm_id : null,
            $compliance->assigned_to ? (int) $compliance->assigned_to : null
        );

        // Reviewers without assign capability may only pick up unassigned requests for themselves.
        if (! $canAssign) {
            if (! $assignTo || (int) $assignTo !== (int) $actor->id) {
                throw ValidationException::withMessages([
                    'assigned_to' => 'You can only assign this request to yourself.',
                ]);
            }
            if ($compliance->assigned_to !== null && (int) $compliance->assigned_to !== (int) $actor->id) {
                throw ValidationException::withMessages([
                    'assigned_to' => 'This request is already assigned to another reviewer.',
                ]);
            }
        }

        $compliance->loadMissing(['assignee', 'currentVersionRow']);
        $previousAssignee = $compliance->assignee;
        $currentStatus = $compliance->currentStatus();

        if ($assignTo) {
            $approver = User::query()->findOrFail($assignTo);
            if (! $this->matrix->roleCan($hub, (string) $approver->role, 'gc_review_requests')) {
                throw ValidationException::withMessages([
                    'assigned_to' => 'Selected user does not have review capability on this hub.',
                ]);
            }

            $compliance->loadMissing('user.firm');
            if (! $this->firmVisibility->userIsEligibleAssignee($approver, $compliance->user?->firm)) {
                throw ValidationException::withMessages([
                    'assigned_to' => 'Selected reviewer is not allowed for this request’s firm visibility settings.',
                ]);
            }

            $compliance->update([
                'assigned_to' => $approver->id,
                'assigned_date' => now(),
                'assigned_by' => $actor->id,
            ]);

            $this->mail->notifyApproverAssigned($compliance->fresh(), $approver, $actor);

            $eventDescription = 'Assigned general compliance request #'.$compliance->id.' to '.$approver->name;

            $this->activityLogs->log([
                'action' => 'gc.assign',
                'description' => $eventDescription,
                'user' => $actor,
                'hub' => $hub,
                'subject' => $compliance,
                'request' => $request,
                'status_code' => 200,
                'properties' => [
                    'assigned_to' => $approver->id,
                    'assigned_to_name' => $approver->name,
                    'self_assign' => (int) $approver->id === (int) $actor->id,
                ],
            ]);

            $this->auditTrail->record([
                'module' => ComplianceAuditEvent::MODULE_GC,
                'subject' => $compliance,
                'event_type' => ComplianceAuditEvent::EVENT_ASSIGNED,
                'actor' => $actor,
                'hub' => $hub,
                'description' => $eventDescription,
                'from_status' => $currentStatus,
                'to_status' => $currentStatus,
                'version_number' => $compliance->current_version,
                'related_user' => $approver,
                'metadata' => [
                    'previous_assignee_id' => $previousAssignee?->id,
                    'previous_assignee_name' => $previousAssignee?->name,
                    'previous_assignee_email' => $previousAssignee?->email,
                    'previous_assignee_role' => $previousAssignee?->role,
                    'self_assign' => (int) $approver->id === (int) $actor->id,
                ],
            ]);
        } else {
            $compliance->update([
                'assigned_to' => null,
                'assigned_date' => null,
                'assigned_by' => null,
            ]);

            $eventDescription = 'Unassigned general compliance request #'.$compliance->id
                .($previousAssignee ? ' (was '.$previousAssignee->name.')' : '');

            $this->activityLogs->log([
                'action' => 'gc.unassign',
                'description' => $eventDescription,
                'user' => $actor,
                'hub' => $hub,
                'subject' => $compliance,
                'request' => $request,
                'status_code' => 200,
                'properties' => [
                    'previous_assignee_id' => $previousAssignee?->id,
                ],
            ]);

            $this->auditTrail->record([
                'module' => ComplianceAuditEvent::MODULE_GC,
                'subject' => $compliance,
                'event_type' => ComplianceAuditEvent::EVENT_UNASSIGNED,
                'actor' => $actor,
                'hub' => $hub,
                'description' => $eventDescription,
                'from_status' => $currentStatus,
                'to_status' => $currentStatus,
                'version_number' => $compliance->current_version,
                'related_user' => $previousAssignee,
            ]);
        }

        return $compliance->fresh([
            'currentVersionRow.attachments',
            'currentVersionRow.supportingFiles',
            'assignee',
            'user',
        ]);
    }

    /**
     * @param  array{status: string, feedback?: ?string, supporting_files?: list<UploadedFile>}  $data
     */
    public function review(
        Hub $hub,
        User $actor,
        GeneralComplianceRequest $compliance,
        array $data,
        $request = null
    ): GeneralComplianceRequest {
        $this->assertModuleEnabled($hub);

        $canViewAll = $this->matrix->roleCan($hub, (string) $actor->role, 'gc_view_all_requests')
            || $this->matrix->roleCan($hub, (string) $actor->role, 'gc_assign_requests');

        if (! $canViewAll && (int) $compliance->assigned_to !== (int) $actor->id) {
            throw ValidationException::withMessages([
                'assigned_to' => 'You can only review requests assigned to you.',
            ]);
        }

        if (! $compliance->relationLoaded('user')) {
            $compliance->load('user:id,firm_id');
        }
        $this->firmVisibility->assertActorCanActOnRequest(
            $actor,
            (int) $compliance->user_id,
            $compliance->user?->firm_id ? (int) $compliance->user->firm_id : null,
            $compliance->assigned_to ? (int) $compliance->assigned_to : null
        );

        $status = (string) $data['status'];
        if (! in_array($status, GeneralComplianceRequest::STATUSES, true)) {
            throw ValidationException::withMessages([
                'status' => 'Invalid status.',
            ]);
        }

        $feedback = trim((string) ($data['feedback'] ?? ''));
        $supportingFiles = $this->supportingFilesFromData($data);
        ComplianceSupportingFiles::assertWithinLimits($supportingFiles);

        $version = $compliance->currentVersionRow;
        if (! $version) {
            throw ValidationException::withMessages([
                'version' => 'Current version is missing.',
            ]);
        }

        $fromStatus = $version->status;

        $version->update([
            'status' => $status,
            'feedback' => $feedback,
            'reviewed_by' => $actor->name,
            'reviewed_at' => now(),
        ]);

        if ($supportingFiles !== []) {
            $version->loadMissing('supportingFiles');
            $nextOrder = $version->supportingFiles->isEmpty()
                ? 0
                : ((int) $version->supportingFiles->max('sort_order')) + 1;
            $this->storeFilesForVersion(
                $version,
                $supportingFiles,
                GeneralComplianceRequestAttachment::KIND_SUPPORTING_FILE,
                $actor,
                'review',
                $nextOrder
            );
        }

        $compliance = $compliance->fresh([
            'currentVersionRow.attachments',
            'currentVersionRow.supportingFiles',
            'user',
            'assignee',
        ]);

        if ($compliance->user) {
            $this->mail->notifyStatusUpdated(
                $compliance,
                $compliance->user,
                $status,
                $feedback,
                $actor->name
            );
        }

        $eventDescription = 'Reviewed general compliance request #'.$compliance->id.' → '.$status;

        $this->activityLogs->log([
            'action' => 'gc.review',
            'description' => $eventDescription,
            'user' => $actor,
            'hub' => $hub,
            'subject' => $compliance,
            'request' => $request,
            'status_code' => 200,
            'properties' => [
                'status' => $status,
                'version' => $compliance->current_version,
                'has_feedback' => $feedback !== '',
                'supporting_file_count' => count($supportingFiles),
            ],
        ]);

        $this->auditTrail->record([
            'module' => ComplianceAuditEvent::MODULE_GC,
            'subject' => $compliance,
            'event_type' => ComplianceAuditEvent::EVENT_REVIEWED,
            'actor' => $actor,
            'hub' => $hub,
            'description' => $eventDescription,
            'from_status' => $fromStatus,
            'to_status' => $status,
            'version_number' => $compliance->current_version,
            'metadata' => [
                'has_feedback' => $feedback !== '',
                'feedback' => $feedback !== '' ? $feedback : null,
                'supporting_file_count' => count($supportingFiles),
            ],
        ]);

        return $compliance;
    }

    /**
     * Manager-style status override: creates a new version with the chosen status + comment.
     * Description, attachments, and supporting files are copied from the current version.
     *
     * @param  array{status: string, comment?: ?string, supporting_files?: list<UploadedFile>}  $data
     */
    public function changeStatus(
        Hub $hub,
        User $actor,
        GeneralComplianceRequest $compliance,
        array $data,
        $request = null
    ): GeneralComplianceRequest {
        $this->assertModuleEnabled($hub);

        if (! $this->matrix->roleCan($hub, (string) $actor->role, 'gc_change_request_status')) {
            throw ValidationException::withMessages([
                'capability' => 'You do not have permission to change general compliance request status.',
            ]);
        }

        if (! $compliance->relationLoaded('user')) {
            $compliance->load('user:id,firm_id');
        }
        $this->firmVisibility->assertActorCanActOnRequest(
            $actor,
            (int) $compliance->user_id,
            $compliance->user?->firm_id ? (int) $compliance->user->firm_id : null,
            $compliance->assigned_to ? (int) $compliance->assigned_to : null
        );

        $status = (string) $data['status'];
        if (! in_array($status, GeneralComplianceRequest::STATUSES, true)) {
            throw ValidationException::withMessages([
                'status' => 'Invalid status.',
            ]);
        }

        $current = $compliance->currentVersionRow;
        if (! $current) {
            throw ValidationException::withMessages([
                'version' => 'Current version is missing.',
            ]);
        }

        $comment = trim((string) ($data['comment'] ?? ''));
        $supportingFiles = $this->supportingFilesFromData($data);
        ComplianceSupportingFiles::assertWithinLimits($supportingFiles);
        $newVersion = (int) $compliance->current_version + 1;
        $fromStatus = $current->status;

        DB::transaction(function () use ($compliance, $actor, $current, $newVersion, $status, $comment, $supportingFiles) {
            $compliance->update(['current_version' => $newVersion]);

            $version = GeneralComplianceRequestVersion::query()->create([
                'request_id' => $compliance->id,
                'version_number' => $newVersion,
                'description' => $current->description,
                'content_type' => $current->content_type,
                'submitted_by' => $current->submitted_by,
                'submitted_at' => $current->submitted_at ?? now(),
                'status' => $status,
                'feedback' => $comment,
                'reviewed_by' => $actor->name,
                'reviewed_at' => now(),
            ]);

            $this->copyFilesFromVersion($current, $version);

            if ($supportingFiles !== []) {
                $version->loadMissing('supportingFiles');
                $nextOrder = $version->supportingFiles->isEmpty()
                    ? 0
                    : ((int) $version->supportingFiles->max('sort_order')) + 1;
                $this->storeFilesForVersion(
                    $version,
                    $supportingFiles,
                    GeneralComplianceRequestAttachment::KIND_SUPPORTING_FILE,
                    $actor,
                    'change_status',
                    $nextOrder
                );
            }
        });

        $compliance = $compliance->fresh([
            'currentVersionRow.attachments',
            'currentVersionRow.supportingFiles',
            'user',
            'assignee',
        ]);

        if ($compliance->user) {
            $this->mail->notifyStatusUpdated(
                $compliance,
                $compliance->user,
                $status,
                $comment,
                $actor->name
            );
        }

        $eventDescription = 'Changed status of general compliance request #'.$compliance->id
            .' → '.$status.' (v'.$newVersion.')';

        $this->activityLogs->log([
            'action' => 'gc.change_status',
            'description' => $eventDescription,
            'user' => $actor,
            'hub' => $hub,
            'subject' => $compliance,
            'request' => $request,
            'status_code' => 200,
            'properties' => [
                'status' => $status,
                'version' => $newVersion,
                'has_comment' => $comment !== '',
                'supporting_file_count' => count($supportingFiles),
            ],
        ]);

        $this->auditTrail->record([
            'module' => ComplianceAuditEvent::MODULE_GC,
            'subject' => $compliance,
            'event_type' => ComplianceAuditEvent::EVENT_STATUS_CHANGED,
            'actor' => $actor,
            'hub' => $hub,
            'description' => $eventDescription,
            'from_status' => $fromStatus,
            'to_status' => $status,
            'version_number' => $newVersion,
            'metadata' => [
                'has_comment' => $comment !== '',
                'comment' => $comment !== '' ? $comment : null,
                'supporting_file_count' => count($supportingFiles),
            ],
        ]);

        return $compliance;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function listForActor(Hub $hub, User $actor, array $filters = []): LengthAwarePaginator
    {
        $this->assertModuleEnabled($hub);

        $canViewAll = $this->matrix->roleCan($hub, (string) $actor->role, 'gc_view_all_requests')
            || $this->matrix->roleCan($hub, (string) $actor->role, 'gc_assign_requests')
            || $this->matrix->roleCan($hub, (string) $actor->role, 'gc_change_request_status');
        $canReview = $this->matrix->roleCan($hub, (string) $actor->role, 'gc_review_requests');
        $canViewOwn = $this->matrix->roleCan($hub, (string) $actor->role, 'gc_view_own_requests')
            || $this->matrix->roleCan($hub, (string) $actor->role, 'gc_submit_request');

        $query = GeneralComplianceRequest::query()
            ->with([
                'currentVersionRow.attachments',
                'currentVersionRow.supportingFiles',
                'assignee:id,name,email',
                'user:id,name,email,firm_id',
                'user.firm:id,name,is_central,compliance_visible_to_own,compliance_visible_to_central,compliance_visible_to_firm_id',
                'onBehalfBy:id,name,email',
            ])
            ->orderByDesc('id');

        if ($canViewAll) {
            // Full queue, then firm-visibility scope below.
        } elseif ($canReview) {
            // Own assignments + unassigned (so reviewers can pick up / assign to themselves).
            $query->where(function ($q) use ($actor) {
                $q->where('assigned_to', $actor->id)->orWhereNull('assigned_to');
            });
        } elseif ($canViewOwn) {
            $subject = $this->actingAdvisors->subjectOrNull($actor);
            if (! $subject) {
                $query->whereRaw('1 = 0');
            } else {
                $query->where('user_id', $subject->id);
            }
        } else {
            throw ValidationException::withMessages([
                'capability' => 'You do not have permission to view general compliance requests.',
            ]);
        }

        if ($canViewAll || $canReview) {
            $this->firmVisibility->scopeQueryForActor($query, $actor, 'user', 'assigned_to');
        }

        $this->applyFilters($query, $filters);

        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 20)));

        return $query->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{summary: array<string, mixed>, rows: list<array<string, mixed>>}
     */
    public function report(Hub $hub, array $filters = [], ?User $actor = null): array
    {
        $this->assertModuleEnabled($hub);

        $query = GeneralComplianceRequest::query()
            ->with([
                'currentVersionRow.attachments',
                'currentVersionRow.supportingFiles',
                'assignee:id,name,email,role',
                'assigner:id,name,email,role',
                'user:id,name,email,firm_id,role',
                'user.firm:id,name',
                'onBehalfBy:id,name,email,role',
            ])
            ->withCount('versions')
            ->orderByDesc('id');

        if ($actor) {
            $this->firmVisibility->scopeQueryForActor($query, $actor, 'user', 'assigned_to');
        }

        $this->applyFilters($query, $filters);

        $limit = max(1, min(500, (int) ($filters['limit'] ?? 500)));
        $rows = $query->limit($limit)->get();
        $auditByRequest = $this->auditTrail->forSubjects(
            ComplianceAuditEvent::MODULE_GC,
            $rows->pluck('id')->all()
        );
        $byStatus = [];
        foreach (GeneralComplianceRequest::STATUSES as $status) {
            $byStatus[$status] = 0;
        }
        $approvedFirstTime = 0;
        $approvedMultiple = 0;

        $export = [];
        foreach ($rows as $row) {
            $status = $row->currentVersionRow?->status ?? GeneralComplianceRequest::STATUS_PENDING;
            $byStatus[$status] = ($byStatus[$status] ?? 0) + 1;
            $versionCount = (int) ($row->versions_count ?? 0);
            if ($status === GeneralComplianceRequest::STATUS_APPROVED) {
                if ($versionCount <= 1) {
                    $approvedFirstTime++;
                } else {
                    $approvedMultiple++;
                }
            }

            $attachments = $row->currentVersionRow?->attachments ?? collect();
            $supportingFiles = $row->currentVersionRow?->supportingFiles ?? collect();
            $attribution = ActingAdvisorService::attributionLabel(
                $row->name,
                $row->onBehalfBy?->name
            );
            $auditTrail = $auditByRequest[(int) $row->id] ?? [];
            $export[] = [
                'id' => $row->id,
                'submitted_by' => $attribution ? ($row->onBehalfBy?->name ?: $row->name) : $row->name,
                'submitter_email' => $row->user?->email,
                'submitter_role' => $row->user?->role,
                'firm_name' => $row->user?->firm?->name,
                'on_behalf_by' => $row->onBehalfBy?->name,
                'on_behalf_by_email' => $row->onBehalfBy?->email,
                'on_behalf_by_role' => $row->onBehalfBy?->role,
                'on_behalf_of' => $attribution ? $row->name : null,
                'current_version' => $row->current_version,
                'version_count' => $versionCount,
                'description' => $row->currentVersionRow?->description,
                'content_type' => $row->currentVersionRow?->content_type,
                'attachment_count' => $attachments->count(),
                'attachment_names' => $attachments->pluck('original_name')->implode('; '),
                'supporting_file_count' => $supportingFiles->count(),
                'supporting_file_names' => $supportingFiles->pluck('original_name')->implode('; '),
                'status' => $status,
                'assigned_to' => $row->assignee?->name,
                'assigned_to_email' => $row->assignee?->email,
                'assigned_to_role' => $row->assignee?->role,
                'assigned_by' => $row->assigner?->name,
                'assigned_by_email' => $row->assigner?->email,
                'assigned_by_role' => $row->assigner?->role,
                'assigned_date' => optional($row->assigned_date)?->toDateTimeString(),
                'reviewed_by' => $row->currentVersionRow?->reviewed_by,
                'feedback' => $row->currentVersionRow?->feedback,
                'submission_date' => optional($row->submission_date)?->toDateTimeString(),
                'reviewed_at' => optional($row->currentVersionRow?->reviewed_at)?->toDateTimeString(),
                'audit_trail' => $auditTrail,
                'audit_trail_summary' => $this->auditTrail->summarizeForExport($auditTrail),
            ];
        }

        $requestIds = $rows->pluck('id')->map(fn ($id) => (int) $id)->all();

        return [
            'summary' => [
                'total' => $rows->count(),
                'by_status' => $byStatus,
                'approved_right_first_time' => $approvedFirstTime,
                'approved_multiple_attempts' => $approvedMultiple,
            ],
            'rows' => $export,
            'audit_events' => $this->auditTrail->forHub(
                ComplianceAuditEvent::MODULE_GC,
                $hub,
                $requestIds
            ),
            'hub' => [
                'id' => $hub->id,
                'name' => $hub->name,
                'slug' => $hub->slug,
            ],
        ];
    }

    /**
     * @return array{labels: list<string>, datasets: list<array{label: string, data: list<int>}>}
     */
    public function approverWorkload(Hub $hub, ?string $from = null, ?string $to = null): array
    {
        $this->assertModuleEnabled($hub);

        $query = GeneralComplianceRequest::query()
            ->selectRaw('assigned_to, COUNT(*) as total')
            ->whereNotNull('assigned_to')
            ->groupBy('assigned_to');

        if ($from) {
            $query->whereDate('submission_date', '>=', $from);
        }
        if ($to) {
            $query->whereDate('submission_date', '<=', $to);
        }

        $counts = $query->pluck('total', 'assigned_to');
        $users = User::query()->whereIn('id', $counts->keys())->get()->keyBy('id');

        $labels = [];
        $data = [];
        foreach ($counts as $userId => $total) {
            $labels[] = $users[$userId]->name ?? ('User #'.$userId);
            $data[] = (int) $total;
        }

        return [
            'labels' => $labels,
            'datasets' => [
                ['label' => 'Assigned requests', 'data' => $data],
            ],
        ];
    }

    /**
     * @return array{labels: list<string>, datasets: list<array{label: string, data: list<int>}>}
     */
    public function advisorComparison(Hub $hub, ?string $from = null, ?string $to = null, ?string $status = null): array
    {
        $this->assertModuleEnabled($hub);

        $query = GeneralComplianceRequest::query()
            ->from('general_compliance_requests as r')
            ->join('general_compliance_request_versions as v', function ($join) {
                $join->on('r.id', '=', 'v.request_id')
                    ->whereColumn('r.current_version', 'v.version_number');
            })
            ->selectRaw('r.user_id, COUNT(*) as total')
            ->groupBy('r.user_id');

        if ($from) {
            $query->whereDate('r.submission_date', '>=', $from);
        }
        if ($to) {
            $query->whereDate('r.submission_date', '<=', $to);
        }
        if ($status) {
            if ($status === 'Approved (Right First Time)') {
                $query->where('v.status', GeneralComplianceRequest::STATUS_APPROVED)
                    ->whereRaw('(SELECT COUNT(*) FROM general_compliance_request_versions cv WHERE cv.request_id = r.id) = 1');
            } elseif ($status === 'Approved (Multiple Attempts)') {
                $query->where('v.status', GeneralComplianceRequest::STATUS_APPROVED)
                    ->whereRaw('(SELECT COUNT(*) FROM general_compliance_request_versions cv WHERE cv.request_id = r.id) > 1');
            } else {
                $query->where('v.status', $status);
            }
        }

        $counts = $query->pluck('total', 'user_id');
        $users = User::query()->whereIn('id', $counts->keys())->get()->keyBy('id');

        $labels = [];
        $data = [];
        foreach ($counts as $userId => $total) {
            $labels[] = $users[$userId]->name ?? ('User #'.$userId);
            $data[] = (int) $total;
        }

        return [
            'labels' => $labels,
            'datasets' => [
                ['label' => 'Requests', 'data' => $data],
            ],
        ];
    }

    private function assertOwner(GeneralComplianceRequest $compliance, User $user): void
    {
        $this->actingAdvisors->assertCanManageOwnedBy($user, (int) $compliance->user_id);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<UploadedFile>
     */
    private function attachmentsFromData(array $data): array
    {
        return $this->normalizeUploadedFiles($data['attachments'] ?? []);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<UploadedFile>
     */
    private function supportingFilesFromData(array $data): array
    {
        return ComplianceSupportingFiles::normalize($data['supporting_files'] ?? []);
    }

    /**
     * @param  mixed  $files
     * @return list<UploadedFile>
     */
    private function normalizeUploadedFiles($files): array
    {
        if ($files instanceof UploadedFile) {
            return [$files];
        }

        if (! is_array($files)) {
            return [];
        }

        $out = [];
        foreach ($files as $file) {
            if ($file instanceof UploadedFile && $file->isValid()) {
                $out[] = $file;
            }
        }

        return $out;
    }

    /**
     * @param  list<UploadedFile>  $files
     */
    private function assertAttachmentLimits(array $files, string $field = 'attachments'): void
    {
        if (count($files) > self::MAX_ATTACHMENTS) {
            throw ValidationException::withMessages([
                $field => 'You may upload at most '.self::MAX_ATTACHMENTS.' files.',
            ]);
        }
    }

    private function assertValidContentType(mixed $value): string
    {
        $name = trim((string) $value);
        if ($name === '') {
            throw ValidationException::withMessages([
                'content_type' => 'Content type is required.',
            ]);
        }

        $exists = GeneralComplianceContentType::query()->where('name', $name)->exists();
        if (! $exists) {
            throw ValidationException::withMessages([
                'content_type' => 'Select a valid content type.',
            ]);
        }

        return $name;
    }

    /**
     * @param  list<UploadedFile>  $files
     */
    private function storeFilesForVersion(
        GeneralComplianceRequestVersion $version,
        array $files,
        string $kind,
        ?User $uploader = null,
        ?string $source = null,
        ?int $startOrder = null
    ): void {
        $baseOrder = $startOrder ?? 0;
        $attribution = ComplianceSupportingFiles::attributionPayload($uploader, $source);

        foreach (array_values($files) as $index => $file) {
            $path = $file->store('general-compliance', 'public');
            GeneralComplianceRequestAttachment::query()->create([
                'version_id' => $version->id,
                'kind' => $kind,
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

    /**
     * Copy files from one version to another. When $kind is null, copies both kinds.
     */
    private function copyFilesFromVersion(
        GeneralComplianceRequestVersion $from,
        GeneralComplianceRequestVersion $to,
        ?string $kind = null
    ): void {
        if ($kind === GeneralComplianceRequestAttachment::KIND_ATTACHMENT) {
            $from->loadMissing('attachments');
            $files = $from->attachments;
        } elseif ($kind === GeneralComplianceRequestAttachment::KIND_SUPPORTING_FILE) {
            $from->loadMissing('supportingFiles');
            $files = $from->supportingFiles;
        } else {
            $from->loadMissing('allFiles');
            $files = $from->allFiles;
        }

        foreach ($files as $index => $file) {
            GeneralComplianceRequestAttachment::query()->create([
                'version_id' => $to->id,
                'kind' => $file->kind ?: GeneralComplianceRequestAttachment::KIND_ATTACHMENT,
                'original_name' => $file->original_name,
                'file_path' => $file->file_path,
                'file_url' => $file->file_url,
                'mime_type' => $file->mime_type,
                'size_bytes' => $file->size_bytes,
                'sort_order' => $file->sort_order ?? $index,
                'uploaded_by_user_id' => $file->uploaded_by_user_id,
                'uploaded_by_name' => $file->uploaded_by_name,
                'source' => $file->source,
            ]);
        }
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\GeneralComplianceRequest>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters($query, array $filters): void
    {
        if (! empty($filters['user_id'])) {
            $query->where('user_id', (int) $filters['user_id']);
        }
        if (! empty($filters['assigned_to'])) {
            $query->where('assigned_to', (int) $filters['assigned_to']);
        }
        if (! empty($filters['from'])) {
            $query->whereDate('submission_date', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->whereDate('submission_date', '<=', $filters['to']);
        }
        if (! empty($filters['q'])) {
            $q = '%'.$filters['q'].'%';
            $query->where(function ($inner) use ($q) {
                $inner->where('name', 'like', $q)
                    ->orWhereHas('currentVersionRow', function ($v) use ($q) {
                        $v->where('description', 'like', $q)
                            ->orWhere('reviewed_by', 'like', $q);
                    });
            });
        }
        if (! empty($filters['status'])) {
            $status = (string) $filters['status'];
            $query->whereHas('currentVersionRow', function ($v) use ($status) {
                if ($status === 'Approved (Right First Time)') {
                    $v->where('status', GeneralComplianceRequest::STATUS_APPROVED)
                        ->whereRaw('(SELECT COUNT(*) FROM general_compliance_request_versions cv WHERE cv.request_id = general_compliance_request_versions.request_id) = 1');
                } elseif ($status === 'Approved (Multiple Attempts)') {
                    $v->where('status', GeneralComplianceRequest::STATUS_APPROVED)
                        ->whereRaw('(SELECT COUNT(*) FROM general_compliance_request_versions cv WHERE cv.request_id = general_compliance_request_versions.request_id) > 1');
                } else {
                    $v->where('status', $status);
                }
            });
        }
    }
}
