<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GdprIncident;
use App\Services\ActivityLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class GdprIncidentController extends Controller
{
    public function __construct(
        private readonly ActivityLogService $activityLogs
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'nullable', 'string', Rule::in([
                GdprIncident::STATUS_OPEN,
                GdprIncident::STATUS_INVESTIGATING,
                GdprIncident::STATUS_CONTAINED,
                GdprIncident::STATUS_CLOSED,
            ])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $query = GdprIncident::query()
            ->with('reportedBy:id,name,email,role')
            ->orderByDesc('id');

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        $paginator = $query->paginate(
            (int) ($validated['per_page'] ?? 50),
            ['*'],
            'page',
            max(1, (int) ($validated['page'] ?? 1))
        );

        return response()->json([
            'incidents' => $paginator->getCollection()->map(fn (GdprIncident $i) => $i->toApiArray())->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'runbook' => $this->runbook(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatedPayload($request);
        $incident = new GdprIncident($validated);
        $incident->reported_by_user_id = $request->user()?->id;
        $this->applyNotificationTimestamps($incident, $validated);
        $incident->save();

        $this->log($request, 'gdpr.incident_created', $incident);

        return response()->json([
            'message' => 'Incident logged.',
            'incident' => $incident->fresh('reportedBy')?->toApiArray(),
        ], 201);
    }

    public function update(Request $request, int $incident): JsonResponse
    {
        $model = GdprIncident::query()->findOrFail($incident);
        $validated = $this->validatedPayload($request, true);
        $model->fill($validated);
        $this->applyNotificationTimestamps($model, $validated);
        $model->save();

        $this->log($request, 'gdpr.incident_updated', $model);

        return response()->json([
            'message' => 'Incident updated.',
            'incident' => $model->fresh('reportedBy')?->toApiArray(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedPayload(Request $request, bool $partial = false): array
    {
        $required = $partial ? ['sometimes'] : ['required'];

        return $request->validate([
            'title' => array_merge($required, ['string', 'max:255']),
            'summary' => array_merge($required, ['string', 'max:20000']),
            'severity' => ['sometimes', 'string', Rule::in([
                GdprIncident::SEVERITY_UNKNOWN,
                GdprIncident::SEVERITY_LOW,
                GdprIncident::SEVERITY_MEDIUM,
                GdprIncident::SEVERITY_HIGH,
                GdprIncident::SEVERITY_CRITICAL,
            ])],
            'status' => ['sometimes', 'string', Rule::in([
                GdprIncident::STATUS_OPEN,
                GdprIncident::STATUS_INVESTIGATING,
                GdprIncident::STATUS_CONTAINED,
                GdprIncident::STATUS_CLOSED,
            ])],
            'discovered_at' => ['sometimes', 'nullable', 'date'],
            'occurred_at' => ['sometimes', 'nullable', 'date'],
            'ico_notified' => ['sometimes', 'boolean'],
            'individuals_notified' => ['sometimes', 'boolean'],
            'affected_estimate' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'actions_taken' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:20000'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function applyNotificationTimestamps(GdprIncident $incident, array $validated): void
    {
        if (array_key_exists('ico_notified', $validated)) {
            $incident->ico_notified = (bool) $validated['ico_notified'];
            if ($incident->ico_notified && ! $incident->ico_notified_at) {
                $incident->ico_notified_at = now();
            }
            if (! $incident->ico_notified) {
                $incident->ico_notified_at = null;
            }
        }

        if (array_key_exists('individuals_notified', $validated)) {
            $incident->individuals_notified = (bool) $validated['individuals_notified'];
            if ($incident->individuals_notified && ! $incident->individuals_notified_at) {
                $incident->individuals_notified_at = now();
            }
            if (! $incident->individuals_notified) {
                $incident->individuals_notified_at = null;
            }
        }
    }

    private function log(Request $request, string $action, GdprIncident $incident): void
    {
        try {
            $this->activityLogs->log([
                'action' => $action,
                'description' => $action.' #'.$incident->id.': '.$incident->title,
                'user' => $request->user(),
                'request' => $request,
                'subject' => $incident,
                'status_code' => 200,
                'properties' => [
                    'incident_id' => $incident->id,
                    'status' => $incident->status,
                    'severity' => $incident->severity,
                ],
            ]);
        } catch (\Throwable) {
            //
        }
    }

    /**
     * @return array{steps: list<string>, ico_url: string, sar_owners: string}
     */
    private function runbook(): array
    {
        return [
            'steps' => [
                '1. Detect & contain — stop further unauthorised access or data loss.',
                '2. Assess risk — what personal data, how many people, likelihood of harm.',
                '3. If required, notify the ICO without undue delay and within 72 hours of becoming aware.',
                '4. If high risk to individuals, notify affected people without undue delay.',
                '5. Log the incident here, record actions taken, and review lessons learned.',
                '6. Subject-access / erasure requests: use GDPR / data requests (hub-admin with dashboard_manage_gdpr).',
            ],
            'ico_url' => 'https://ico.org.uk/for-organisations/report-a-breach/',
            'sar_owners' => 'Hub roles with “Manage GDPR / data requests” handle SARs and erasure on this hub. Platform DPAs with processors (Stripe, email, hosting) are commercial/legal outside this app. Appoint a DPO only if legal thresholds are met.',
        ];
    }
}
