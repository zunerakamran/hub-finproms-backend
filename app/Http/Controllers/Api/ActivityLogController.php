<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ActivityLogService;
use App\Services\HubService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    public function __construct(
        private readonly ActivityLogService $activityLogs,
        private readonly HubService $hubs
    ) {}

    /**
     * Paginated activity log feed for the current hub.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $this->filters($request);
        $hub = $this->hubs->current();
        $paginator = $this->activityLogs->list($hub, $filters);

        $this->activityLogs->log([
            'action' => 'activity_logs.view',
            'description' => 'Viewed activity log list',
            'request' => $request,
            'status_code' => 200,
            'properties' => ['filters' => $filters],
        ]);

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'filters' => $filters,
        ]);
    }

    /**
     * Aggregated activity report (counts by action / user / day).
     */
    public function report(Request $request): JsonResponse
    {
        $filters = $this->filters($request);
        unset($filters['per_page']);

        $hub = $this->hubs->current();
        $report = $this->activityLogs->report($hub, $filters);

        $this->activityLogs->log([
            'action' => 'activity_logs.report',
            'description' => 'Viewed activity log report',
            'request' => $request,
            'status_code' => 200,
            'properties' => ['filters' => $filters],
        ]);

        return response()->json([
            'hub' => [
                'id' => $hub->id,
                'name' => $hub->name,
                'slug' => $hub->slug,
            ],
            'report' => $report,
        ]);
    }

    /**
     * @return array{
     *   user_id?: int|null,
     *   action?: string|null,
     *   q?: string|null,
     *   from?: string|null,
     *   to?: string|null,
     *   per_page?: int|null
     * }
     */
    private function filters(Request $request): array
    {
        $validated = $request->validate([
            'user_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'action' => ['sometimes', 'nullable', 'string', 'max:120'],
            'q' => ['sometimes', 'nullable', 'string', 'max:255'],
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date'],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return [
            'user_id' => $validated['user_id'] ?? null,
            'action' => $validated['action'] ?? null,
            'q' => $validated['q'] ?? null,
            'from' => $validated['from'] ?? null,
            'to' => $validated['to'] ?? null,
            'per_page' => $validated['per_page'] ?? 25,
        ];
    }
}
