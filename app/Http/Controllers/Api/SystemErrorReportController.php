<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Hub;
use App\Models\SystemErrorReport;
use App\Models\User;
use App\Services\ActingHubService;
use App\Services\HubService;
use App\Services\SystemErrorReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SystemErrorReportController extends Controller
{
    public function __construct(
        private readonly SystemErrorReportService $errors,
        private readonly HubService $hubs,
        private readonly ActingHubService $actingHubs
    ) {}

    private function errorHub(?User $user, string $capability = 'st_view_system_errors'): Hub
    {
        if ($user) {
            return $this->actingHubs->capabilityHub($user, $capability);
        }

        return $this->hubs->current();
    }

    /**
     * Authenticated client beacon — stores/dedupes a frontend (or reported) error.
     * Always returns success JSON so the SPA never loops on failures.
     */
    public function store(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();
        // Resolve acting content hub (same as other ST routes); do not require view-errors cap to report.
        $hub = $this->errorHub($user, 'support_tickets');

        $data = $request->validate([
            'message' => ['required', 'string', 'max:1000'],
            'source' => ['nullable', Rule::in([
                SystemErrorReport::SOURCE_FRONTEND,
                SystemErrorReport::SOURCE_BACKEND,
            ])],
            'level' => ['nullable', 'string', 'max:20'],
            'exception_class' => ['nullable', 'string', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
            'stack_trace' => ['nullable', 'string', 'max:8000'],
            'stack' => ['nullable', 'string', 'max:8000'],
            'page_url' => ['nullable', 'string', 'max:500'],
            'url' => ['nullable', 'string', 'max:500'],
            'route' => ['nullable', 'string', 'max:255'],
            'http_method' => ['nullable', 'string', 'max:20'],
            'browser_info' => ['nullable', 'string', 'max:500'],
            'properties' => ['nullable', 'array'],
        ]);

        $data['source'] = $data['source'] ?? SystemErrorReport::SOURCE_FRONTEND;

        try {
            $row = $this->errors->ingest($hub, $user, $data, $request);
        } catch (\Throwable) {
            return response()->json(['ok' => false], 200);
        }

        return response()->json([
            'ok' => true,
            'id' => $row?->id,
            'deduped' => $row ? ((int) $row->occurrence_count > 1) : false,
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $hub = $this->errorHub($user);

        $filters = [
            'status' => (string) $request->query('status', ''),
            'source' => (string) $request->query('source', ''),
            'q' => (string) $request->query('q', ''),
            'per_page' => (int) $request->query('per_page', 50),
        ];

        $paginator = $this->errors->list($hub, $filters);

        return response()->json([
            'data' => collect($paginator->items())->map(
                fn (SystemErrorReport $row) => $row->toApiArray()
            )->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'open_count' => $this->errors->openCount($hub),
            ],
            'filters' => $filters,
            'statuses' => SystemErrorReport::STATUSES,
        ]);
    }

    public function show(Request $request, SystemErrorReport $systemErrorReport): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $hub = $this->errorHub($user);
        $row = $this->errors->show($hub, $systemErrorReport);

        return response()->json([
            'data' => $row->toApiArray(includeDetails: true),
        ]);
    }

    public function updateStatus(Request $request, SystemErrorReport $systemErrorReport): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $hub = $this->errorHub($user);

        $data = $request->validate([
            'status' => ['required', Rule::in(SystemErrorReport::STATUSES)],
            'staff_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $row = $this->errors->updateStatus($hub, $user, $systemErrorReport, $data);

        return response()->json([
            'data' => $row->toApiArray(includeDetails: true),
        ]);
    }
}
