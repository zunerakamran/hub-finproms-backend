<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityAnomaly;
use App\Models\User;
use App\Services\ActivityAnomalyService;
use App\Services\ActivityLogService;
use App\Services\HubService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ActivityAnomalyController extends Controller
{
    public function __construct(
        private readonly ActivityAnomalyService $anomalies,
        private readonly ActivityLogService $activityLogs,
        private readonly HubService $hubs
    ) {}

    public function options(Request $request): JsonResponse
    {
        return response()->json($this->anomalies->options());
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $this->filters($request);
        $hub = $this->hubs->current();
        $paginator = $this->anomalies->list($hub, $filters);

        return response()->json([
            'data' => collect($paginator->items())->map(
                fn (ActivityAnomaly $row) => $row->toApiArray()
            )->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'filters' => $filters,
            'options' => $this->anomalies->options(),
        ]);
    }

    public function show(Request $request, ActivityAnomaly $activityAnomaly): JsonResponse
    {
        $hub = $this->hubs->current();
        $anomaly = $this->anomalies->show($hub, $activityAnomaly);

        return response()->json([
            'data' => $anomaly->toApiArray(includeDetails: true),
        ]);
    }

    public function update(Request $request, ActivityAnomaly $activityAnomaly): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $hub = $this->hubs->current();

        $data = $request->validate([
            'status' => ['required', 'string', Rule::in([
                ActivityAnomaly::STATUS_OPEN,
                ActivityAnomaly::STATUS_REVIEWED,
                ActivityAnomaly::STATUS_DISMISSED,
            ])],
        ]);

        $anomaly = $this->anomalies->updateStatus($hub, $user, $activityAnomaly, $data, $request);

        return response()->json([
            'data' => $anomaly->toApiArray(includeDetails: true),
        ]);
    }

    public function escalate(Request $request, ActivityAnomaly $activityAnomaly): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $hub = $this->hubs->current();

        $anomaly = $this->anomalies->escalate($hub, $user, $activityAnomaly, $request);

        return response()->json([
            'data' => $anomaly->toApiArray(includeDetails: true),
        ]);
    }

    public function detectNow(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $hub = $this->hubs->current();

        $result = $this->anomalies->detectForHub($hub);

        $this->activityLogs->log([
            'action' => 'activity.anomaly_detect_now',
            'description' => 'Ran on-demand activity anomaly detection (created='.$result['created'].')',
            'user' => $user,
            'hub' => $hub,
            'request' => $request,
            'properties' => $result,
        ]);

        return response()->json([
            'data' => [
                'created' => $result['created'],
                'by_type' => $result['by_type'],
                'detected_at' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * @return array{
     *   anomaly_type?: string|null,
     *   severity?: string|null,
     *   status?: string|null,
     *   q?: string|null,
     *   from?: string|null,
     *   to?: string|null,
     *   user_id?: int|null,
     *   per_page?: int|null
     * }
     */
    private function filters(Request $request): array
    {
        $validated = $request->validate([
            'anomaly_type' => ['sometimes', 'nullable', 'string', 'max:120'],
            'severity' => ['sometimes', 'nullable', 'string', Rule::in(ActivityAnomaly::SEVERITIES)],
            'status' => ['sometimes', 'nullable', 'string', Rule::in(ActivityAnomaly::STATUSES)],
            'q' => ['sometimes', 'nullable', 'string', 'max:255'],
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date'],
            'user_id' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return [
            'anomaly_type' => $validated['anomaly_type'] ?? null,
            'severity' => $validated['severity'] ?? null,
            'status' => $validated['status'] ?? null,
            'q' => $validated['q'] ?? null,
            'from' => $validated['from'] ?? null,
            'to' => $validated['to'] ?? null,
            'user_id' => array_key_exists('user_id', $validated) ? $validated['user_id'] : null,
            'per_page' => $validated['per_page'] ?? 25,
        ];
    }
}
