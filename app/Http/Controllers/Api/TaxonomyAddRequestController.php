<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Hub;
use App\Models\TaxonomyAddRequest;
use App\Models\User;
use App\Services\ActingHubService;
use App\Services\HubService;
use App\Services\TaxonomyAddRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TaxonomyAddRequestController extends Controller
{
    public function __construct(
        private readonly TaxonomyAddRequestService $requests,
        private readonly HubService $hubs,
        private readonly ActingHubService $actingHubs
    ) {}

    private function requestHub(?User $user): Hub
    {
        if ($user) {
            return $this->actingHubs->capabilityHub($user, 'taxonomy_request_add');
        }

        return $this->hubs->current();
    }

    public function options(Request $request): JsonResponse
    {
        $hub = $this->requestHub($request->user());

        return response()->json($this->requests->options());
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $hub = $this->requestHub($user);

        $validated = $request->validate([
            'target' => ['required', 'string', Rule::in(TaxonomyAddRequest::TARGETS)],
            'proposed_name' => ['required', 'string', 'max:100'],
            'remarks' => ['required', 'string', 'max:5000'],
        ]);

        $row = $this->requests->submit($hub, $user, $validated, $request);

        return response()->json([
            'message' => 'Taxonomy add request submitted.',
            'data' => $row->toApiArray(),
        ], 201);
    }

    public function mine(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $hub = $this->requestHub($user);
        $filters = $this->listFilters($request);

        $paginator = $this->requests->listMine($hub, $user, $filters);

        return response()->json([
            'data' => collect($paginator->items())->map(
                fn (TaxonomyAddRequest $row) => $row->toApiArray()
            )->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'filters' => $filters,
            'options' => $this->requests->options(),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $hub = $this->requestHub($user);
        $filters = $this->listFilters($request);

        $paginator = $this->requests->listQueue($hub, $user, $filters);

        return response()->json([
            'data' => collect($paginator->items())->map(
                fn (TaxonomyAddRequest $row) => $row->toApiArray()
            )->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'filters' => $filters,
            'reviewable_targets' => $this->requests->reviewableTargets($hub, $user),
            'options' => $this->requests->options(),
        ]);
    }

    public function show(Request $request, TaxonomyAddRequest $taxonomyAddRequest): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $hub = $this->requestHub($user);
        $row = $this->requests->show($hub, $user, $taxonomyAddRequest);

        return response()->json([
            'data' => $row->toApiArray(),
        ]);
    }

    public function approve(Request $request, TaxonomyAddRequest $taxonomyAddRequest): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $hub = $this->requestHub($user);

        $validated = $request->validate([
            'review_note' => ['nullable', 'string', 'max:5000'],
        ]);

        $row = $this->requests->approve($hub, $user, $taxonomyAddRequest, $validated, $request);

        return response()->json([
            'message' => 'Request approved and taxonomy option created.',
            'data' => $row->toApiArray(),
        ]);
    }

    public function reject(Request $request, TaxonomyAddRequest $taxonomyAddRequest): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $hub = $this->requestHub($user);

        $validated = $request->validate([
            'review_note' => ['required', 'string', 'max:5000'],
        ]);

        $row = $this->requests->reject($hub, $user, $taxonomyAddRequest, $validated, $request);

        return response()->json([
            'message' => 'Request rejected.',
            'data' => $row->toApiArray(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function listFilters(Request $request): array
    {
        return [
            'status' => $request->query('status'),
            'target' => $request->query('target'),
            'q' => $request->query('q'),
            'per_page' => $request->query('per_page', 50),
            'page' => $request->query('page', 1),
        ];
    }
}
