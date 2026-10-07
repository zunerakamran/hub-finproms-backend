<?php

namespace App\Services;

use App\Models\Category;
use App\Models\ContentType;
use App\Models\FirmDocumentCategory;
use App\Models\GeneralComplianceContentType;
use App\Models\Hub;
use App\Models\Post;
use App\Models\Tag;
use App\Models\TaxonomyAddRequest;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
    public function options(): array
    {
        return [
            'targets' => collect(TaxonomyAddRequest::TARGETS)
                ->map(fn (string $key) => [
                    'key' => $key,
                    'label' => TaxonomyAddRequest::targetLabel($key),
                    'central_only' => TaxonomyAddRequest::isCentralOnlyTarget($key),
                ])
                ->values()
                ->all(),
            'statuses' => TaxonomyAddRequest::STATUSES,
        ];
    }

    /**
     * Manage capabilities the actor can use to review requests on this hub.
     *
     * @return list<string>
     */
    public function reviewableManageCapabilities(Hub $hub, User $user): array
    {
        $caps = [];
        foreach (array_unique(array_values(TaxonomyAddRequest::TARGET_MANAGE_CAPABILITIES)) as $cap) {
            if ($this->matrix->userCan($hub, $user, $cap)) {
                $caps[] = $cap;
            }
        }

        return $caps;
    }

    /**
     * @return list<string>
     */
    public function reviewableTargets(Hub $hub, User $user): array
    {
        $targets = [];
        foreach (TaxonomyAddRequest::TARGET_MANAGE_CAPABILITIES as $target => $cap) {
            if ($this->matrix->userCan($hub, $user, $cap)) {
                $targets[] = $target;
            }
        }

        return $targets;
    }

    public function canReviewAny(Hub $hub, User $user): bool
    {
        return $this->reviewableTargets($hub, $user) !== [];
    }

    public function canReview(Hub $hub, User $user, TaxonomyAddRequest $request): bool
    {
        $cap = TaxonomyAddRequest::manageCapabilityFor((string) $request->target);
        if ($cap === null) {
            return false;
        }

        return $this->matrix->userCan($hub, $user, $cap);
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
     * Approve and auto-create the taxonomy row.
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

        $this->assertTargetAllowedOnHub($hub, (string) $request->target);
        $this->assertNameAvailable((string) $request->target, (string) $request->proposed_name);

        $note = trim((string) ($data['review_note'] ?? ''));

        $entity = DB::transaction(function () use ($request, $user, $note) {
            $created = $this->createTaxonomyEntity((string) $request->target, (string) $request->proposed_name);

            $request->update([
                'status' => TaxonomyAddRequest::STATUS_APPROVED,
                'review_note' => $note !== '' ? $note : null,
                'reviewed_by' => $user->id,
                'reviewed_at' => now(),
                'created_entity_id' => $created['id'],
            ]);

            return $created;
        });

        $fresh = $request->fresh(['user', 'reviewedByUser']);

        $this->activityLogs->log([
            'action' => 'taxonomy_add_request.approve',
            'description' => 'Approved taxonomy request #'.$request->id.' and created '.$request->target,
            'user' => $user,
            'hub' => $hub,
            'subject' => $fresh,
            'request' => $httpRequest,
            'status_code' => 200,
            'properties' => [
                'target' => $request->target,
                'proposed_name' => $request->proposed_name,
                'created_entity_id' => $entity['id'],
            ],
        ]);

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

        $fresh = $request->fresh(['user', 'reviewedByUser']);

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

        return $fresh;
    }

    private function assertTargetAllowedOnHub(Hub $hub, string $target): void
    {
        if (TaxonomyAddRequest::isCentralOnlyTarget($target) && ! $hub->isCentral()) {
            throw ValidationException::withMessages([
                'target' => 'Post / reel types, categories, and tags can only be requested on Central (Central content library taxonomy).',
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
     * @return array{id: int, name: string}
     */
    private function createTaxonomyEntity(string $target, string $name): array
    {
        return match ($target) {
            TaxonomyAddRequest::TARGET_CONTENT_TYPE => $this->createContentType($name),
            TaxonomyAddRequest::TARGET_CATEGORY => $this->createCategory($name),
            TaxonomyAddRequest::TARGET_TAG => $this->createTag($name),
            TaxonomyAddRequest::TARGET_GC_CONTENT_TYPE => $this->createGcContentType($name),
            TaxonomyAddRequest::TARGET_FIRM_DOCUMENT_CATEGORY => $this->createFirmDocumentCategory($name),
            default => throw ValidationException::withMessages([
                'target' => 'Unsupported taxonomy target.',
            ]),
        };
    }

    /**
     * @return array{id: int, name: string}
     */
    private function createContentType(string $name): array
    {
        $slug = $this->uniqueSlug(ContentType::class, $name);
        $row = ContentType::query()->create([
            'name' => $name,
            'slug' => $slug,
        ]);
        Post::clearTypeSlugMap();

        return ['id' => (int) $row->id, 'name' => $row->name];
    }

    /**
     * @return array{id: int, name: string}
     */
    private function createCategory(string $name): array
    {
        $slug = $this->uniqueSlug(Category::class, $name);
        $row = Category::query()->create([
            'name' => $name,
            'slug' => $slug,
        ]);

        return ['id' => (int) $row->id, 'name' => $row->name];
    }

    /**
     * @return array{id: int, name: string}
     */
    private function createTag(string $name): array
    {
        $row = Tag::query()->create([
            'name' => $name,
        ]);

        return ['id' => (int) $row->id, 'name' => $row->name];
    }

    /**
     * @return array{id: int, name: string}
     */
    private function createGcContentType(string $name): array
    {
        $slug = $this->uniqueSlug(GeneralComplianceContentType::class, $name);
        $row = GeneralComplianceContentType::query()->create([
            'name' => $name,
            'slug' => $slug,
        ]);

        return ['id' => (int) $row->id, 'name' => $row->name];
    }

    /**
     * @return array{id: int, name: string}
     */
    private function createFirmDocumentCategory(string $name): array
    {
        $slug = $this->uniqueSlug(FirmDocumentCategory::class, $name);
        $row = FirmDocumentCategory::query()->create([
            'name' => $name,
            'slug' => $slug,
        ]);

        return ['id' => (int) $row->id, 'name' => $row->name];
    }

    /**
     * @param  class-string  $modelClass
     */
    private function uniqueSlug(string $modelClass, string $name): string
    {
        $base = Str::slug($name) ?: 'item';
        $slug = $base;
        $i = 2;
        while ($modelClass::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i;
            $i++;
        }

        return $slug;
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
        if (is_string($target) && $target !== '' && in_array($target, TaxonomyAddRequest::TARGETS, true)) {
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
