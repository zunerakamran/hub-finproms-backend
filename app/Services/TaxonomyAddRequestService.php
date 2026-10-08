<?php

namespace App\Services;

use App\Models\Category;
use App\Models\ContentType;
use App\Models\FirmDocumentCategory;
use App\Models\GeneralComplianceContentType;
use App\Models\Hub;
use App\Models\Tag;
use App\Models\TaxonomyAddRequest;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

class TaxonomyAddRequestService
{
    public function __construct(
        private readonly CapabilitiesMatrixService $matrix,
        private readonly ActivityLogService $activityLogs,
        private readonly ActingAdvisorService $actingAdvisors,
        private readonly GeneralComplianceService $generalCompliance
    ) {}

    /**
     * @return array{
     *   targets: list<array{key: string, label: string, central_only: bool}>,
     *   statuses: list<string>
     * }
     */
    public function options(?Hub $hub = null): array
    {
        $targets = collect(TaxonomyAddRequest::TARGETS)
            ->filter(fn (string $key) => $hub === null || $this->isTargetAvailableOnHub($hub, $key))
            ->map(fn (string $key) => [
                'key' => $key,
                'label' => TaxonomyAddRequest::targetLabel($key),
                'central_only' => TaxonomyAddRequest::isCentralOnlyTarget($key),
            ])
            ->values()
            ->all();

        return [
            'targets' => $targets,
            'statuses' => TaxonomyAddRequest::STATUSES,
        ];
    }

    /**
     * @return list<string>
     */
    public function reviewableTargets(Hub $hub, User $user): array
    {
        if (! $this->canReviewAny($hub, $user)) {
            return [];
        }

        // Reviewers with Manage taxonomy requests see the full queue, including
        // legacy content_type rows that are no longer offered in the submit dropdown.
        return array_values(array_unique([
            ...TaxonomyAddRequest::TARGETS,
            TaxonomyAddRequest::TARGET_CONTENT_TYPE,
        ]));
    }

    public function canReviewAny(Hub $hub, User $user): bool
    {
        return $this->matrix->userCan($hub, $user, TaxonomyAddRequest::REVIEW_CAPABILITY);
    }

    public function canReview(Hub $hub, User $user, TaxonomyAddRequest $request): bool
    {
        return $this->canReviewAny($hub, $user);
    }

    public function isTargetAvailableOnHub(Hub $hub, string $target): bool
    {
        if (! in_array($target, TaxonomyAddRequest::TARGETS, true)) {
            return false;
        }

        if (TaxonomyAddRequest::isCentralOnlyTarget($target) && ! $hub->isCentral()) {
            return false;
        }

        if ($target === TaxonomyAddRequest::TARGET_GC_CONTENT_TYPE
            && ! $hub->hasGeneralComplianceModule()
        ) {
            return false;
        }

        if ($target === TaxonomyAddRequest::TARGET_FIRM_DOCUMENT_CATEGORY
            && ! $hub->hasFirmDocumentsFunctionality()
        ) {
            return false;
        }

        return true;
    }

    /**
     * @param  array{target: string, proposed_name: string, remarks: string}  $data
     */
    public function submit(Hub $hub, User $user, array $data, $httpRequest = null): TaxonomyAddRequest
    {
        $role = $this->matrix->effectiveRoleFor($user);
        if (! $this->matrix->roleCan($hub, $role, 'taxonomy_request_add')) {
            throw ValidationException::withMessages([
                'capability' => 'You do not have permission to request new taxonomy options.',
            ]);
        }

        $target = (string) ($data['target'] ?? '');
        $name = trim((string) ($data['proposed_name'] ?? ''));
        $remarks = trim((string) ($data['remarks'] ?? ''));

        if (! in_array($target, TaxonomyAddRequest::TARGETS, true)) {
            throw ValidationException::withMessages([
                'target' => 'Select a valid taxonomy type to request.',
            ]);
        }
        if ($name === '') {
            throw ValidationException::withMessages([
                'proposed_name' => 'A proposed name is required.',
            ]);
        }
        if (mb_strlen($name) > 100) {
            throw ValidationException::withMessages([
                'proposed_name' => 'Proposed name may not be longer than 100 characters.',
            ]);
        }
        if ($remarks === '') {
            throw ValidationException::withMessages([
                'remarks' => 'Please add remarks explaining why this option is needed.',
            ]);
        }

        $this->assertTargetAllowedOnHub($hub, $target);
        $this->assertNameAvailable($target, $name);

        $subjectUser = $this->actingAdvisors->requireSubject($user);

        $pendingExists = TaxonomyAddRequest::query()
            ->where('target', $target)
            ->where('proposed_name', $name)
            ->where('status', TaxonomyAddRequest::STATUS_PENDING)
            ->exists();
        if ($pendingExists) {
            throw ValidationException::withMessages([
                'proposed_name' => 'A pending request for that name already exists.',
            ]);
        }

        $row = TaxonomyAddRequest::query()->create([
            'user_id' => $subjectUser->id,
            'target' => $target,
            'proposed_name' => $name,
            'remarks' => $remarks,
            'status' => TaxonomyAddRequest::STATUS_PENDING,
        ]);

        $this->activityLogs->log([
            'action' => 'taxonomy_add_request.submit',
            'description' => 'Requested new '.$row->target.' “'.$row->proposed_name.'”',
            'user' => $user,
            'hub' => $hub,
            'subject' => $row,
            'request' => $httpRequest,
            'status_code' => 201,
            'properties' => [
                'target' => $row->target,
                'proposed_name' => $row->proposed_name,
            ],
        ]);

        return $row->fresh(['user']);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function listMine(Hub $hub, User $user, array $filters = []): LengthAwarePaginator
    {
        $role = $this->matrix->effectiveRoleFor($user);
        $canOwn = $this->matrix->roleCan($hub, $role, 'taxonomy_request_add');
        if (! $canOwn) {
            throw ValidationException::withMessages([
                'capability' => 'You do not have permission to view taxonomy add requests.',
            ]);
        }

        $subjectUser = $this->actingAdvisors->requireSubject($user);

        $query = TaxonomyAddRequest::query()
            ->with(['user:id,name,email,role', 'reviewedByUser:id,name,email,role'])
            ->where('user_id', $subjectUser->id)
            ->orderByDesc('id');

        $this->applyFilters($query, $filters);

        return $query->paginate($this->perPage($filters));
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function listQueue(Hub $hub, User $user, array $filters = []): LengthAwarePaginator
    {
        $targets = $this->reviewableTargets($hub, $user);
        if ($targets === []) {
            throw ValidationException::withMessages([
                'capability' => 'You do not have permission to review taxonomy add requests.',
            ]);
        }

        $query = TaxonomyAddRequest::query()
            ->with(['user:id,name,email,role', 'reviewedByUser:id,name,email,role'])
            ->whereIn('target', $targets)
            ->orderByDesc('id');

        $this->applyFilters($query, $filters);

        return $query->paginate($this->perPage($filters));
    }

    public function show(Hub $hub, User $user, TaxonomyAddRequest $request): TaxonomyAddRequest
    {
        $subjectUser = $this->actingAdvisors->requireSubject($user);
        $isOwner = (int) $request->user_id === (int) $subjectUser->id
            && $this->matrix->userCan($hub, $user, 'taxonomy_request_add');

        if (! $isOwner && ! $this->canReview($hub, $user, $request)) {
            throw ValidationException::withMessages([
                'capability' => 'You do not have permission to view this taxonomy add request.',
            ]);
        }

        return $request->loadMissing(['user:id,name,email,role', 'reviewedByUser:id,name,email,role']);
    }

    /**
     * Mark a request Approved after the reviewer has created the option manually.
     *
     * @param  array{review_note?: string|null}  $data
     */
    public function approve(Hub $hub, User $user, TaxonomyAddRequest $request, array $data = [], $httpRequest = null): TaxonomyAddRequest
    {
        if (! $this->canReview($hub, $user, $request)) {
            throw ValidationException::withMessages([
                'capability' => 'You do not have permission to approve this taxonomy add request.',
            ]);
        }

        if ($request->status !== TaxonomyAddRequest::STATUS_PENDING) {
            throw ValidationException::withMessages([
                'status' => 'Only pending requests can be approved.',
            ]);
        }

        $note = trim((string) ($data['review_note'] ?? ''));

        $request->update([
            'status' => TaxonomyAddRequest::STATUS_APPROVED,
            'review_note' => $note !== '' ? $note : null,
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
        ]);

        $fresh = $this->freshForResponse($request);

        try {
            $this->activityLogs->log([
                'action' => 'taxonomy_add_request.approve',
                'description' => 'Approved taxonomy request #'.$request->id,
                'user' => $user,
                'hub' => $hub,
                'subject' => $fresh,
                'request' => $httpRequest,
                'status_code' => 200,
                'properties' => [
                    'target' => $request->target,
                    'proposed_name' => $request->proposed_name,
                ],
            ]);
        } catch (\Throwable) {
            // Status change must succeed even if activity logging fails.
        }

        return $fresh;
    }

    /**
     * @param  array{review_note?: string|null}  $data
     */
    public function reject(Hub $hub, User $user, TaxonomyAddRequest $request, array $data = [], $httpRequest = null): TaxonomyAddRequest
    {
        if (! $this->canReview($hub, $user, $request)) {
            throw ValidationException::withMessages([
                'capability' => 'You do not have permission to reject this taxonomy add request.',
            ]);
        }

        if ($request->status !== TaxonomyAddRequest::STATUS_PENDING) {
            throw ValidationException::withMessages([
                'status' => 'Only pending requests can be rejected.',
            ]);
        }

        $note = trim((string) ($data['review_note'] ?? ''));
        if ($note === '') {
            throw ValidationException::withMessages([
                'review_note' => 'A review note is required when rejecting a request.',
            ]);
        }

        $request->update([
            'status' => TaxonomyAddRequest::STATUS_REJECTED,
            'review_note' => $note,
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
        ]);

        $fresh = $this->freshForResponse($request);

        try {
            $this->activityLogs->log([
                'action' => 'taxonomy_add_request.reject',
                'description' => 'Rejected taxonomy request #'.$request->id,
                'user' => $user,
                'hub' => $hub,
                'subject' => $fresh,
                'request' => $httpRequest,
                'status_code' => 200,
                'properties' => [
                    'target' => $request->target,
                    'proposed_name' => $request->proposed_name,
                ],
            ]);
        } catch (\Throwable) {
            // Status change must succeed even if activity logging fails.
        }

        return $fresh;
    }

    private function freshForResponse(TaxonomyAddRequest $request): TaxonomyAddRequest
    {
        try {
            $fresh = $request->fresh(['user:id,name,email,role', 'reviewedByUser:id,name,email,role']);
            if ($fresh instanceof TaxonomyAddRequest) {
                return $fresh;
            }
        } catch (\Throwable) {
            // Cross-DB reviewer lookup can fail while acting on a remote hub.
        }

        $request->refresh();

        return $request;
    }

    private function assertTargetAllowedOnHub(Hub $hub, string $target): void
    {
        if (TaxonomyAddRequest::isCentralOnlyTarget($target) && ! $hub->isCentral()) {
            throw ValidationException::withMessages([
                'target' => 'That taxonomy option can only be requested on Central.',
            ]);
        }

        if ($target === TaxonomyAddRequest::TARGET_GC_CONTENT_TYPE) {
            $this->generalCompliance->assertModuleEnabled($hub);
        }

        if ($target === TaxonomyAddRequest::TARGET_FIRM_DOCUMENT_CATEGORY
            && ! $hub->hasFirmDocumentsFunctionality()
        ) {
            throw ValidationException::withMessages([
                'target' => 'Firm documents are disabled for this hub. Enable Functionalities → Firm documents first.',
            ]);
        }
    }

    private function assertNameAvailable(string $target, string $name): void
    {
        $exists = match ($target) {
            TaxonomyAddRequest::TARGET_CONTENT_TYPE => ContentType::query()->where('name', $name)->exists(),
            TaxonomyAddRequest::TARGET_CATEGORY => Category::query()->where('name', $name)->exists(),
            TaxonomyAddRequest::TARGET_TAG => Tag::query()->where('name', $name)->exists(),
            TaxonomyAddRequest::TARGET_GC_CONTENT_TYPE => GeneralComplianceContentType::query()->where('name', $name)->exists(),
            TaxonomyAddRequest::TARGET_FIRM_DOCUMENT_CATEGORY => FirmDocumentCategory::query()->where('name', $name)->exists(),
            default => false,
        };

        if ($exists) {
            throw ValidationException::withMessages([
                'proposed_name' => 'That name is already in use.',
            ]);
        }
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\TaxonomyAddRequest>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters($query, array $filters): void
    {
        $status = $filters['status'] ?? null;
        if (is_string($status) && $status !== '' && in_array($status, TaxonomyAddRequest::STATUSES, true)) {
            $query->where('status', $status);
        }

        $target = $filters['target'] ?? null;
        $allowedTargets = [
            ...TaxonomyAddRequest::TARGETS,
            TaxonomyAddRequest::TARGET_CONTENT_TYPE,
        ];
        if (is_string($target) && $target !== '' && in_array($target, $allowedTargets, true)) {
            $query->where('target', $target);
        }

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $query->where(function ($inner) use ($q) {
                $inner->where('proposed_name', 'like', '%'.$q.'%')
                    ->orWhere('remarks', 'like', '%'.$q.'%');
            });
        }
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function perPage(array $filters): int
    {
        $perPage = (int) ($filters['per_page'] ?? 50);

        return max(1, min(100, $perPage));
    }
}
