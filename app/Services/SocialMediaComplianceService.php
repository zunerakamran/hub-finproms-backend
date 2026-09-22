<?php

namespace App\Services;

use App\Models\SocialMediaComplianceRequest;
use App\Models\SocialMediaComplianceRequestVersion;
use App\Models\Hub;
use App\Models\Post;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class SocialMediaComplianceService
{
    public function __construct(
        private readonly CapabilitiesMatrixService $matrix,
        private readonly SocialMediaComplianceMailService $mail,
        private readonly ActivityLogService $activityLogs,
        private readonly FirmComplianceVisibilityService $firmVisibility
    ) {}

    public function assertModuleEnabled(Hub $hub): void
    {
        if (! $hub->hasSocialMediaComplianceModule()) {
            throw ValidationException::withMessages([
                'module' => 'Social Media Compliance is not enabled for this hub.',
            ]);
        }
    }

    /**
     * Users whose role currently has smc_review_requests on this hub.
     *
     * @return Collection<int, User>
     */
    public function reviewersForHub(Hub $hub): Collection
    {
        $roles = array_values(array_filter(
            CapabilitiesMatrixService::MATRIX_ROLES,
            fn (string $role) => $this->matrix->roleCan($hub, $role, 'smc_review_requests')
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
     *   post_id: int,
     *   description?: ?string,
     *   image?: ?UploadedFile
     * }  $data
     */
    public function submit(Hub $hub, User $user, array $data, $request = null): SocialMediaComplianceRequest
    {
        $this->assertModuleEnabled($hub);

        $post = Post::query()->findOrFail((int) $data['post_id']);

        if (! $user->hasPurchased($post)) {
            throw ValidationException::withMessages([
                'post_id' => 'You can only submit posts you have purchased for social media compliance.',
            ]);
        }

        $description = trim((string) ($data['description'] ?? ''));
        if ($description === '') {
            throw ValidationException::withMessages([
                'description' => 'A description is required.',
            ]);
        }

        [$imagePath, $imageUrl] = $this->resolveImage($data['image'] ?? null, $post);

        $compliance = DB::transaction(function () use ($user, $post, $description, $imagePath, $imageUrl) {
            $compliance = SocialMediaComplianceRequest::query()->create([
                'user_id' => $user->id,
                'post_id' => $post->id,
                'name' => $user->name,
                'current_version' => 1,
                'submission_date' => now(),
            ]);

            SocialMediaComplianceRequestVersion::query()->create([
                'request_id' => $compliance->id,
                'version_number' => 1,
                'description' => $description,
                'image_path' => $imagePath,
                'image_url' => $imageUrl,
                'submitted_by' => $user->id,
                'submitted_at' => now(),
                'status' => SocialMediaComplianceRequest::STATUS_PENDING,
                'feedback' => '',
            ]);

            return $compliance->fresh(['currentVersionRow', 'post', 'user']);
        });

        $this->mail->notifyRequestSubmitted($compliance, $user);

        $this->activityLogs->log([
            'action' => 'smc.submit',
            'description' => 'Submitted social media compliance request #'.$compliance->id.' for post #'.$post->id,
            'user' => $user,
            'hub' => $hub,
            'subject' => $compliance,
            'request' => $request,
            'status_code' => 201,
            'properties' => [
                'post_id' => $post->id,
                'version' => 1,
                'status' => SocialMediaComplianceRequest::STATUS_PENDING,
            ],
        ]);

        return $compliance;
    }

    /**
     * @param  array{description: string, image?: ?UploadedFile}  $data
     */
    public function resubmit(Hub $hub, User $user, SocialMediaComplianceRequest $compliance, array $data, $request = null): SocialMediaComplianceRequest
    {
        $this->assertModuleEnabled($hub);
        $this->assertOwner($compliance, $user);

        $current = $compliance->currentVersionRow;
        if (! $current || $current->status !== SocialMediaComplianceRequest::STATUS_REJECTED) {
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

        $imagePath = $current->image_path;
        $imageUrl = $current->image_url;
        if (! empty($data['image']) && $data['image'] instanceof UploadedFile) {
            [$imagePath, $imageUrl] = $this->storeUploadedImage($data['image']);
        }

        $newVersion = (int) $compliance->current_version + 1;

        DB::transaction(function () use ($compliance, $user, $description, $imagePath, $imageUrl, $newVersion) {
            $compliance->update(['current_version' => $newVersion]);

            SocialMediaComplianceRequestVersion::query()->create([
                'request_id' => $compliance->id,
                'version_number' => $newVersion,
                'description' => $description,
                'image_path' => $imagePath,
                'image_url' => $imageUrl,
                'submitted_by' => $user->id,
                'submitted_at' => now(),
                'status' => SocialMediaComplianceRequest::STATUS_PENDING,
                'feedback' => '',
            ]);
        });

        $compliance = $compliance->fresh(['currentVersionRow', 'assignee', 'post', 'user']);

        if ($compliance->assignee) {
            $this->mail->notifyResubmitted($compliance, $compliance->assignee, $user);
        }

        $this->activityLogs->log([
            'action' => 'smc.resubmit',
            'description' => 'ReSubmitted social media compliance request #'.$compliance->id.' as v'.$newVersion,
            'user' => $user,
            'hub' => $hub,
            'subject' => $compliance,
            'request' => $request,
            'status_code' => 200,
            'properties' => [
                'version' => $newVersion,
                'status' => SocialMediaComplianceRequest::STATUS_PENDING,
                'assigned_to' => $compliance->assigned_to,
            ],
        ]);

        return $compliance;
    }

    /**
     * Confirm or re-upload after "Approved with Feedback".
     *
     * @param  array{image?: ?UploadedFile}  $data
     */
    public function confirmApprovedWithFeedback(
        Hub $hub,
        User $user,
        SocialMediaComplianceRequest $compliance,
        array $data,
        $request = null
    ): SocialMediaComplianceRequest {
        $this->assertModuleEnabled($hub);
        $this->assertOwner($compliance, $user);

        $current = $compliance->currentVersionRow;
        if (! $current || $current->status !== SocialMediaComplianceRequest::STATUS_APPROVED_WITH_FEEDBACK) {
            throw ValidationException::withMessages([
                'status' => 'Only requests approved with feedback can be confirmed this way.',
            ]);
        }

        $newVersion = (int) $compliance->current_version + 1;
        $imagePath = $current->image_path;
        $imageUrl = $current->image_url;
        $feedback = $current->feedback;
        $hasNewImage = ! empty($data['image']) && $data['image'] instanceof UploadedFile;

        if ($hasNewImage) {
            [$imagePath, $imageUrl] = $this->storeUploadedImage($data['image']);
        } else {
            $feedback = '';
        }

        DB::transaction(function () use (
            $compliance,
            $user,
            $current,
            $newVersion,
            $imagePath,
            $imageUrl,
            $feedback
        ) {
            $compliance->update(['current_version' => $newVersion]);

            SocialMediaComplianceRequestVersion::query()->create([
                'request_id' => $compliance->id,
                'version_number' => $newVersion,
                'description' => $current->description,
                'image_path' => $imagePath,
                'image_url' => $imageUrl,
                'submitted_by' => $user->id,
                'submitted_at' => now(),
                'status' => SocialMediaComplianceRequest::STATUS_APPROVED,
                'feedback' => $feedback,
                'reviewed_by' => $current->reviewed_by,
                'reviewed_at' => $current->reviewed_at,
            ]);
        });

        $compliance = $compliance->fresh(['currentVersionRow', 'post', 'user']);

        $this->activityLogs->log([
            'action' => 'smc.confirm_feedback',
            'description' => 'Confirmed approved-with-feedback for request #'.$compliance->id.' as v'.$newVersion,
            'user' => $user,
            'hub' => $hub,
            'subject' => $compliance,
            'request' => $request,
            'status_code' => 200,
            'properties' => [
                'version' => $newVersion,
                'new_image' => $hasNewImage,
                'status' => SocialMediaComplianceRequest::STATUS_APPROVED,
            ],
        ]);

        return $compliance;
    }

    public function assign(
        Hub $hub,
        User $actor,
        SocialMediaComplianceRequest $compliance,
        ?int $assignTo,
        $request = null
    ): SocialMediaComplianceRequest {
        $this->assertModuleEnabled($hub);

        $canAssign = $this->matrix->roleCan($hub, (string) $actor->role, 'smc_assign_requests');
        $canReview = $this->matrix->roleCan($hub, (string) $actor->role, 'smc_review_requests');

        if (! $canAssign && ! $canReview) {
            throw ValidationException::withMessages([
                'capability' => 'You do not have permission to assign social media compliance requests.',
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

        if ($assignTo) {
            $approver = User::query()->findOrFail($assignTo);
            if (! $this->matrix->roleCan($hub, (string) $approver->role, 'smc_review_requests')) {
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
                'action' => 'smc.assign',
                'description' => 'Assigned social media compliance request #'.$compliance->id.' to '.$approver->name,
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
                'action' => 'smc.unassign',
                'description' => 'UnAssigned social media compliance request #'.$compliance->id,
                'user' => $actor,
                'hub' => $hub,
                'subject' => $compliance,
                'request' => $request,
                'status_code' => 200,
                'properties' => [],
            ]);
        }

        return $compliance->fresh(['currentVersionRow', 'assignee', 'post', 'user']);
    }

    /**
     * @param  array{status: string, feedback?: ?string}  $data
     */
    public function review(
        Hub $hub,
        User $actor,
        SocialMediaComplianceRequest $compliance,
        array $data,
        $request = null
    ): SocialMediaComplianceRequest {
        $this->assertModuleEnabled($hub);

        $canViewAll = $this->matrix->roleCan($hub, (string) $actor->role, 'smc_view_all_requests')
            || $this->matrix->roleCan($hub, (string) $actor->role, 'smc_assign_requests');

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
        if (! in_array($status, SocialMediaComplianceRequest::STATUSES, true)) {
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

        $compliance = $compliance->fresh(['currentVersionRow', 'user', 'post', 'assignee']);

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
            'action' => 'smc.review',
            'description' => 'Reviewed social media compliance request #'.$compliance->id.' → '.$status,
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
     * Manager-style status override: creates a new version with the chosen status + comment.
     * Content (description / image) is copied from the current version.
     *
     * @param  array{status: string, comment?: ?string}  $data
     */
    public function changeStatus(
        Hub $hub,
        User $actor,
        SocialMediaComplianceRequest $compliance,
        array $data,
        $request = null
    ): SocialMediaComplianceRequest {
        $this->assertModuleEnabled($hub);

        if (! $this->matrix->roleCan($hub, (string) $actor->role, 'smc_change_request_status')) {
            throw ValidationException::withMessages([
                'capability' => 'You do not have permission to change social media compliance request status.',
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
        if (! in_array($status, SocialMediaComplianceRequest::STATUSES, true)) {
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
        $newVersion = (int) $compliance->current_version + 1;

        DB::transaction(function () use ($compliance, $actor, $current, $newVersion, $status, $comment) {
            $compliance->update(['current_version' => $newVersion]);

            SocialMediaComplianceRequestVersion::query()->create([
                'request_id' => $compliance->id,
                'version_number' => $newVersion,
                'description' => $current->description,
                'image_path' => $current->image_path,
                'image_url' => $current->image_url,
                'submitted_by' => $current->submitted_by,
                'submitted_at' => $current->submitted_at ?? now(),
                'status' => $status,
                'feedback' => $comment,
                'reviewed_by' => $actor->name,
                'reviewed_at' => now(),
            ]);
        });

        $compliance = $compliance->fresh(['currentVersionRow', 'user', 'post', 'assignee']);

        if ($compliance->user) {
            $this->mail->notifyStatusUpdated(
                $compliance,
                $compliance->user,
                $status,
                $comment,
                $actor->name
            );
        }

        $this->activityLogs->log([
            'action' => 'smc.change_status',
            'description' => 'Changed status of social media compliance request #'.$compliance->id.' → '.$status.' (v'.$newVersion.')',
            'user' => $actor,
            'hub' => $hub,
            'subject' => $compliance,
            'request' => $request,
            'status_code' => 200,
            'properties' => [
                'status' => $status,
                'version' => $newVersion,
                'has_comment' => $comment !== '',
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

        $canViewAll = $this->matrix->roleCan($hub, (string) $actor->role, 'smc_view_all_requests')
            || $this->matrix->roleCan($hub, (string) $actor->role, 'smc_assign_requests')
            || $this->matrix->roleCan($hub, (string) $actor->role, 'smc_change_request_status');
        $canReview = $this->matrix->roleCan($hub, (string) $actor->role, 'smc_review_requests');
        $canViewOwn = $this->matrix->roleCan($hub, (string) $actor->role, 'smc_view_own_requests')
            || $this->matrix->roleCan($hub, (string) $actor->role, 'smc_submit_request');

        $query = SocialMediaComplianceRequest::query()
            ->with(['currentVersionRow', 'assignee:id,name,email', 'post:id,title,type', 'user:id,name,email,firm_id'])
            ->orderByDesc('id');

        if ($canViewAll) {
            // Full queue, then firm-visibility scope below.
        } elseif ($canReview) {
            // Own assignments + unassigned (so reviewers can pick up / assign to themselves).
            $query->where(function ($q) use ($actor) {
                $q->where('assigned_to', $actor->id)->orWhereNull('assigned_to');
            });
        } elseif ($canViewOwn) {
            $query->where('user_id', $actor->id);
        } else {
            throw ValidationException::withMessages([
                'capability' => 'You do not have permission to view social media compliance requests.',
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

        $query = SocialMediaComplianceRequest::query()
            ->with(['currentVersionRow', 'assignee:id,name,email', 'user:id,name,email,firm_id', 'post:id,title'])
            ->orderByDesc('id');

        if ($actor) {
            $this->firmVisibility->scopeQueryForActor($query, $actor, 'user', 'assigned_to');
        }

        $this->applyFilters($query, $filters);

        $rows = $query->get();
        $byStatus = [];
        foreach (SocialMediaComplianceRequest::STATUSES as $status) {
            $byStatus[$status] = 0;
        }
        $approvedFirstTime = 0;
        $approvedMultiple = 0;

        $export = [];
        foreach ($rows as $row) {
            $status = $row->currentVersionRow?->status ?? SocialMediaComplianceRequest::STATUS_PENDING;
            $byStatus[$status] = ($byStatus[$status] ?? 0) + 1;
            $versionCount = SocialMediaComplianceRequestVersion::query()->where('request_id', $row->id)->count();
            if ($status === SocialMediaComplianceRequest::STATUS_APPROVED) {
                if ($versionCount <= 1) {
                    $approvedFirstTime++;
                } else {
                    $approvedMultiple++;
                }
            }

            $export[] = [
                'id' => $row->id,
                'submitted_by' => $row->name,
                'submitter_email' => $row->user?->email,
                'post_id' => $row->post_id,
                'post_title' => $row->post?->title,
                'current_version' => $row->current_version,
                'version_count' => $versionCount,
                'description' => $row->currentVersionRow?->description,
                'image_url' => $row->currentVersionRow?->resolvedImageUrl(),
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

        $query = SocialMediaComplianceRequest::query()
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

        $query = SocialMediaComplianceRequest::query()
            ->from('social_media_compliance_requests as r')
            ->join('social_media_compliance_request_versions as v', function ($join) {
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
                $query->where('v.status', SocialMediaComplianceRequest::STATUS_APPROVED)
                    ->whereRaw('(SELECT COUNT(*) FROM social_media_compliance_request_versions cv WHERE cv.request_id = r.id) = 1');
            } elseif ($status === 'Approved (Multiple Attempts)') {
                $query->where('v.status', SocialMediaComplianceRequest::STATUS_APPROVED)
                    ->whereRaw('(SELECT COUNT(*) FROM social_media_compliance_request_versions cv WHERE cv.request_id = r.id) > 1');
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

    private function assertOwner(SocialMediaComplianceRequest $compliance, User $user): void
    {
        if ((int) $compliance->user_id !== (int) $user->id) {
            throw ValidationException::withMessages([
                'request' => 'You can only manage your own social media compliance requests.',
            ]);
        }
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function resolveImage(?UploadedFile $image, Post $post): array
    {
        if ($image) {
            return $this->storeUploadedImage($image);
        }

        if ($post->attachment_path) {
            return [$post->attachment_path, $post->attachment_url];
        }

        throw ValidationException::withMessages([
            'image' => 'An image is required (upload one or use a post that has an attachment).',
        ]);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function storeUploadedImage(UploadedFile $image): array
    {
        $path = $image->store('social-media-compliance', 'public');

        return [$path, Storage::disk('public')->url($path)];
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\SocialMediaComplianceRequest>  $query
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
                    $v->where('status', SocialMediaComplianceRequest::STATUS_APPROVED)
                        ->whereRaw('(SELECT COUNT(*) FROM social_media_compliance_request_versions cv WHERE cv.request_id = social_media_compliance_request_versions.request_id) = 1');
                } elseif ($status === 'Approved (Multiple Attempts)') {
                    $v->where('status', SocialMediaComplianceRequest::STATUS_APPROVED)
                        ->whereRaw('(SELECT COUNT(*) FROM social_media_compliance_request_versions cv WHERE cv.request_id = social_media_compliance_request_versions.request_id) > 1');
                } else {
                    $v->where('status', $status);
                }
            });
        }
    }
}
