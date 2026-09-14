<?php

namespace App\Services;

use App\Models\GeneralComplianceRequest;
use App\Models\GeneralComplianceRequestAttachment;
use App\Models\GeneralComplianceRequestVersion;
use App\Models\Hub;
use App\Models\User;
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
        private readonly ActivityLogService $activityLogs
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
    public function reviewersForHub(Hub $hub): Collection
    {
        $roles = array_values(array_filter(
            CapabilitiesMatrixService::MATRIX_ROLES,
            fn (string $role) => $this->matrix->roleCan($hub, $role, 'gc_review_requests')
        ));

        if ($roles === []) {
            return collect();
        }

        return User::query()
            ->whereIn('role', $roles)
            ->where(function ($q) {
                $q->where('is_suspended', false)->orWhereNull('is_suspended');
            })
            ->where(function ($q) {
                $q->where('is_discontinued', false)->orWhereNull('is_discontinued');
            })
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role']);
    }

    /**
     * @param  array{
     *   description: string,
     *   attachments: list<UploadedFile>
     * }  $data
     */
    public function submit(Hub $hub, User $user, array $data, $request = null): GeneralComplianceRequest
    {
        $this->assertModuleEnabled($hub);

        $description = trim((string) ($data['description'] ?? ''));
        if ($description === '') {
            throw ValidationException::withMessages([
                'description' => 'A description is required.',
            ]);
        }

        $files = $this->normalizeUploadedFiles($data['attachments'] ?? []);
        if ($files === []) {
            throw ValidationException::withMessages([
                'attachments' => 'At least one attachment is required.',
            ]);
        }
        $this->assertAttachmentLimits($files);

        $compliance = DB::transaction(function () use ($user, $description, $files) {
            $compliance = GeneralComplianceRequest::query()->create([
                'user_id' => $user->id,
                'name' => $user->name,
                'current_version' => 1,
                'submission_date' => now(),
            ]);

            $version = GeneralComplianceRequestVersion::query()->create([
                'request_id' => $compliance->id,
                'version_number' => 1,
                'description' => $description,
                'submitted_by' => $user->id,
                'submitted_at' => now(),
                'status' => GeneralComplianceRequest::STATUS_PENDING,
                'feedback' => '',
            ]);

            $this->storeAttachmentsForVersion($version, $files);

            return $compliance->fresh(['currentVersionRow.attachments', 'user']);
        });

        $this->mail->notifyRequestSubmitted($compliance, $user);

        $this->activityLogs->log([
            'action' => 'gc.submit',
            'description' => 'Submitted general compliance request #'.$compliance->id,
            'user' => $user,
            'hub' => $hub,
            'subject' => $compliance,
            'request' => $request,
            'status_code' => 201,
            'properties' => [
                'version' => 1,
                'status' => GeneralComplianceRequest::STATUS_PENDING,
                'attachment_count' => count($files),
            ],
        ]);

        return $compliance;
    }

    /**
     * @param  array{description: string, attachments?: list<UploadedFile>}  $data
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

        $files = $this->normalizeUploadedFiles($data['attachments'] ?? []);
        $this->assertAttachmentLimits($files);
        $hasNewFiles = $files !== [];

        $newVersion = (int) $compliance->current_version + 1;

        DB::transaction(function () use ($compliance, $user, $description, $files, $hasNewFiles, $current, $newVersion) {
            $compliance->update(['current_version' => $newVersion]);

            $version = GeneralComplianceRequestVersion::query()->create([
                'request_id' => $compliance->id,
                'version_number' => $newVersion,
                'description' => $description,
                'submitted_by' => $user->id,
                'submitted_at' => now(),
                'status' => GeneralComplianceRequest::STATUS_PENDING,
                'feedback' => '',
            ]);

            if ($hasNewFiles) {
                $this->storeAttachmentsForVersion($version, $files);
            } else {
                $this->copyAttachmentsFromVersion($current, $version);
            }
        });

        $compliance = $compliance->fresh(['currentVersionRow.attachments', 'assignee', 'user']);

        if ($compliance->assignee) {
            $this->mail->notifyResubmitted($compliance, $compliance->assignee, $user);
        }

        $this->activityLogs->log([
            'action' => 'gc.resubmit',
            'description' => 'ReSubmitted general compliance request #'.$compliance->id.' as v'.$newVersion,
            'user' => $user,
            'hub' => $hub,
            'subject' => $compliance,
            'request' => $request,
            'status_code' => 200,
            'properties' => [
                'version' => $newVersion,
                'status' => GeneralComplianceRequest::STATUS_PENDING,
                'assigned_to' => $compliance->assigned_to,
                'new_attachments' => $hasNewFiles,
            ],
        ]);

        return $compliance;
    }

    /**
     * Confirm or re-upload after "Approved with Feedback".
     *
     * @param  array{attachments?: list<UploadedFile>}  $data
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

        $files = $this->normalizeUploadedFiles($data['attachments'] ?? []);
        $this->assertAttachmentLimits($files);
        $hasNewFiles = $files !== [];

        $newVersion = (int) $compliance->current_version + 1;
        $feedback = $hasNewFiles ? $current->feedback : '';

        DB::transaction(function () use (
            $compliance,
            $user,
            $current,
            $newVersion,
            $files,
            $hasNewFiles,
            $feedback
        ) {
            $compliance->update(['current_version' => $newVersion]);

            $version = GeneralComplianceRequestVersion::query()->create([
                'request_id' => $compliance->id,
                'version_number' => $newVersion,
                'description' => $current->description,
                'submitted_by' => $user->id,
                'submitted_at' => now(),
                'status' => GeneralComplianceRequest::STATUS_APPROVED,
                'feedback' => $feedback,
                'reviewed_by' => $current->reviewed_by,
                'reviewed_at' => $current->reviewed_at,
            ]);

            if ($hasNewFiles) {
                $this->storeAttachmentsForVersion($version, $files);
            } else {
                $this->copyAttachmentsFromVersion($current, $version);
            }
        });

        $compliance = $compliance->fresh(['currentVersionRow.attachments', 'user']);

        $this->activityLogs->log([
            'action' => 'gc.confirm_feedback',
            'description' => 'Confirmed approved-with-feedback for request #'.$compliance->id.' as v'.$newVersion,
            'user' => $user,
            'hub' => $hub,
            'subject' => $compliance,
            'request' => $request,
            'status_code' => 200,
            'properties' => [
                'version' => $newVersion,
                'new_attachments' => $hasNewFiles,
                'status' => GeneralComplianceRequest::STATUS_APPROVED,
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

        if ($assignTo) {
            $approver = User::query()->findOrFail($assignTo);
            if (! $this->matrix->roleCan($hub, (string) $approver->role, 'gc_review_requests')) {
                throw ValidationException::withMessages([
                    'assigned_to' => 'Selected user does not have review capability on this hub.',
                ]);
            }

            $compliance->update([
                'assigned_to' => $approver->id,
                'assigned_date' => now(),
                'assigned_by' => $actor->id,
            ]);

            $this->mail->notifyApproverAssigned($compliance->fresh(), $approver, $actor);

            $this->activityLogs->log([
                'action' => 'gc.assign',
                'description' => 'Assigned general compliance request #'.$compliance->id.' to '.$approver->name,
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
        } else {
            $compliance->update([
                'assigned_to' => null,
                'assigned_date' => null,
                'assigned_by' => null,
            ]);

            $this->activityLogs->log([
                'action' => 'gc.unassign',
                'description' => 'UnAssigned general compliance request #'.$compliance->id,
                'user' => $actor,
                'hub' => $hub,
                'subject' => $compliance,
                'request' => $request,
                'status_code' => 200,
                'properties' => [],
            ]);
        }

        return $compliance->fresh(['currentVersionRow.attachments', 'assignee', 'user']);
    }

    /**
     * @param  array{status: string, feedback?: ?string}  $data
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

        $status = (string) $data['status'];
        if (! in_array($status, GeneralComplianceRequest::STATUSES, true)) {
            throw ValidationException::withMessages([
                'status' => 'Invalid status.',
            ]);
        }

        $feedback = trim((string) ($data['feedback'] ?? ''));
        $version = $compliance->currentVersionRow;
        if (! $version) {
            throw ValidationException::withMessages([
                'version' => 'Current version is missing.',
            ]);
        }

        $version->update([
            'status' => $status,
            'feedback' => $feedback,
            'reviewed_by' => $actor->name,
            'reviewed_at' => now(),
        ]);

        $compliance = $compliance->fresh(['currentVersionRow.attachments', 'user', 'assignee']);

        if ($compliance->user) {
            $this->mail->notifyStatusUpdated(
                $compliance,
                $compliance->user,
                $status,
                $feedback,
                $actor->name
            );
        }

        $this->activityLogs->log([
            'action' => 'gc.review',
            'description' => 'Reviewed general compliance request #'.$compliance->id.' → '.$status,
            'user' => $actor,
            'hub' => $hub,
            'subject' => $compliance,
            'request' => $request,
            'status_code' => 200,
            'properties' => [
                'status' => $status,
                'version' => $compliance->current_version,
                'has_feedback' => $feedback !== '',
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
            || $this->matrix->roleCan($hub, (string) $actor->role, 'gc_assign_requests');
        $canReview = $this->matrix->roleCan($hub, (string) $actor->role, 'gc_review_requests');
        $canViewOwn = $this->matrix->roleCan($hub, (string) $actor->role, 'gc_view_own_requests')
            || $this->matrix->roleCan($hub, (string) $actor->role, 'gc_submit_request');

        $query = GeneralComplianceRequest::query()
            ->with(['currentVersionRow.attachments', 'assignee:id,name,email', 'user:id,name,email'])
            ->orderByDesc('id');

        if ($canViewAll) {
            // Full queue
        } elseif ($canReview) {
            // Own assignments + unassigned (so reviewers can pick up / assign to themselves).
            $query->where(function ($q) use ($actor) {
                $q->where('assigned_to', $actor->id)->orWhereNull('assigned_to');
            });
        } elseif ($canViewOwn) {
            $query->where('user_id', $actor->id);
        } else {
            throw ValidationException::withMessages([
                'capability' => 'You do not have permission to view general compliance requests.',
            ]);
        }

        $this->applyFilters($query, $filters);

        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 20)));

        return $query->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{summary: array<string, mixed>, rows: list<array<string, mixed>>}
     */
    public function report(Hub $hub, array $filters = []): array
    {
        $this->assertModuleEnabled($hub);

        $query = GeneralComplianceRequest::query()
            ->with(['currentVersionRow.attachments', 'assignee:id,name,email', 'user:id,name,email'])
            ->orderByDesc('id');

        $this->applyFilters($query, $filters);

        $rows = $query->get();
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
            $versionCount = GeneralComplianceRequestVersion::query()->where('request_id', $row->id)->count();
            if ($status === GeneralComplianceRequest::STATUS_APPROVED) {
                if ($versionCount <= 1) {
                    $approvedFirstTime++;
                } else {
                    $approvedMultiple++;
                }
            }

            $attachments = $row->currentVersionRow?->attachments ?? collect();
            $export[] = [
                'id' => $row->id,
                'submitted_by' => $row->name,
                'submitter_email' => $row->user?->email,
                'current_version' => $row->current_version,
                'version_count' => $versionCount,
                'description' => $row->currentVersionRow?->description,
                'attachment_count' => $attachments->count(),
                'attachment_names' => $attachments->pluck('original_name')->implode('; '),
                'status' => $status,
                'assigned_to' => $row->assignee?->name,
                'assigned_to_email' => $row->assignee?->email,
                'reviewed_by' => $row->currentVersionRow?->reviewed_by,
                'feedback' => $row->currentVersionRow?->feedback,
                'submission_date' => optional($row->submission_date)?->toDateTimeString(),
                'reviewed_at' => optional($row->currentVersionRow?->reviewed_at)?->toDateTimeString(),
            ];
        }

        return [
            'summary' => [
                'total' => $rows->count(),
                'by_status' => $byStatus,
                'approved_right_first_time' => $approvedFirstTime,
                'approved_multiple_attempts' => $approvedMultiple,
            ],
            'rows' => $export,
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
        if ((int) $compliance->user_id !== (int) $user->id) {
            throw ValidationException::withMessages([
                'request' => 'You can only manage your own general compliance requests.',
            ]);
        }
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
    private function assertAttachmentLimits(array $files): void
    {
        if (count($files) > self::MAX_ATTACHMENTS) {
            throw ValidationException::withMessages([
                'attachments' => 'You may upload at most '.self::MAX_ATTACHMENTS.' files.',
            ]);
        }
    }

    /**
     * @param  list<UploadedFile>  $files
     */
    private function storeAttachmentsForVersion(GeneralComplianceRequestVersion $version, array $files): void
    {
        foreach (array_values($files) as $index => $file) {
            $path = $file->store('general-compliance', 'public');
            GeneralComplianceRequestAttachment::query()->create([
                'version_id' => $version->id,
                'original_name' => $file->getClientOriginalName(),
                'file_path' => $path,
                'file_url' => Storage::disk('public')->url($path),
                'mime_type' => $file->getClientMimeType() ?: $file->getMimeType(),
                'size_bytes' => (int) $file->getSize(),
                'sort_order' => $index,
            ]);
        }
    }

    private function copyAttachmentsFromVersion(
        GeneralComplianceRequestVersion $from,
        GeneralComplianceRequestVersion $to
    ): void {
        $from->loadMissing('attachments');
        foreach ($from->attachments as $index => $attachment) {
            GeneralComplianceRequestAttachment::query()->create([
                'version_id' => $to->id,
                'original_name' => $attachment->original_name,
                'file_path' => $attachment->file_path,
                'file_url' => $attachment->file_url,
                'mime_type' => $attachment->mime_type,
                'size_bytes' => $attachment->size_bytes,
                'sort_order' => $attachment->sort_order ?? $index,
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
