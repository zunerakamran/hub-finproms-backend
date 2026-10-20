<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Hub;
use App\Models\SecurityEvent;
use App\Models\SecurityIncident;
use App\Models\User;
use App\Models\ModuleDisasterSnapshot;
use App\Services\AccessRestrictionService;
use App\Services\ActingHubService;
use App\Services\HubService;
use App\Services\ModuleDisasterRecoveryService;
use App\Services\SecurityMonitoringService;
use App\Support\InputSanitizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

class SecurityMonitoringController extends Controller
{
    public function __construct(
        private readonly SecurityMonitoringService $security,
        private readonly ModuleDisasterRecoveryService $disasterRecovery,
        private readonly HubService $hubs,
        private readonly ActingHubService $actingHubs
    ) {}

    private function securityHub(?User $user): Hub
    {
        if ($user) {
            return $this->actingHubs->capabilityHub($user, 'secmon_view_events');
        }

        return $this->hubs->current();
    }

    public function options(Request $request): JsonResponse
    {
        $hub = $this->securityHub($request->user());
        $this->security->assertModuleEnabled($hub);

        return response()->json($this->security->options());
    }

    public function events(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $hub = $this->securityHub($user);
        $filters = $this->eventFilters($request);

        $paginator = $this->security->listEvents($hub, $user, $filters);

        return response()->json([
            'data' => collect($paginator->items())->map(
                fn (SecurityEvent $row) => $row->toApiArray()
            )->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'filters' => $filters,
            'options' => $this->security->options(),
        ]);
    }

    public function showEvent(Request $request, SecurityEvent $securityEvent): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $hub = $this->securityHub($user);
        $event = $this->security->showEvent($hub, $user, $securityEvent);

        return response()->json([
            'data' => $event->toApiArray(includeDetails: true),
        ]);
    }

    public function incidents(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $hub = $this->securityHub($user);
        $filters = $this->incidentFilters($request);

        $paginator = $this->security->listIncidents($hub, $user, $filters);

        return response()->json([
            'data' => collect($paginator->items())->map(
                fn (SecurityIncident $row) => $row->toApiArray()
            )->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'filters' => $filters,
            'options' => $this->security->options(),
        ]);
    }

    public function showIncident(Request $request, SecurityIncident $securityIncident): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $hub = $this->securityHub($user);
        $incident = $this->security->showIncident($hub, $user, $securityIncident);

        return response()->json([
            'data' => $incident->toApiArray(includeDetails: true),
        ]);
    }

    public function declareIncident(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $hub = $this->securityHub($user);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'category' => ['sometimes', 'string', Rule::in(SecurityEvent::CATEGORIES)],
            'severity' => ['sometimes', 'string', Rule::in(SecurityEvent::SEVERITIES)],
            'affected_module' => [
                'sometimes',
                'nullable',
                'string',
                Rule::in($this->disasterRecovery->recoverableModuleKeys()),
            ],
            'recognize_disaster' => ['sometimes', 'boolean'],
            'event_ids' => ['sometimes', 'array'],
            'event_ids.*' => ['integer', 'min:1'],
        ]);

        $data = InputSanitizer::plainKeys($data, ['title']);
        $data = InputSanitizer::richHtmlKeys($data, ['description']);

        $incident = $this->security->declareIncident($hub, $user, $data, $request);

        return response()->json([
            'data' => $incident->toApiArray(includeDetails: true),
        ], 201);
    }

    public function updateIncident(Request $request, SecurityIncident $securityIncident): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $hub = $this->securityHub($user);

        $data = $request->validate([
            'status' => ['sometimes', 'string', Rule::in(SecurityIncident::STATUSES)],
            'severity' => ['sometimes', 'string', Rule::in(SecurityEvent::SEVERITIES)],
            'resolution_summary' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'note' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'event_ids' => ['sometimes', 'array'],
            'event_ids.*' => ['integer', 'min:1'],
        ]);

        $data = InputSanitizer::richHtmlKeys($data, ['resolution_summary', 'note']);

        $incident = $this->security->updateIncident($hub, $user, $securityIncident, $data, $request);

        return response()->json([
            'data' => $incident->toApiArray(includeDetails: true),
        ]);
    }

    public function report(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $hub = $this->securityHub($user);

        $filters = $request->validate([
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date'],
        ]);

        return response()->json([
            'hub' => [
                'id' => $hub->id,
                'name' => $hub->name,
                'slug' => $hub->slug,
            ],
            'report' => $this->security->report($hub, $user, $filters),
        ]);
    }

    public function alertSettings(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $hub = $this->securityHub($user);
        $this->security->assertModuleEnabled($hub);

        return response()->json([
            'data' => $this->security->settingsFor($hub)->toApiArray(),
            'options' => $this->security->options(),
        ]);
    }

    public function updateAlertSettings(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $hub = $this->securityHub($user);

        $data = $request->validate([
            'alert_min_severity' => ['sometimes', 'string', Rule::in(SecurityEvent::SEVERITIES)],
            'alert_email_enabled' => ['sometimes', 'boolean'],
            'alert_emails' => ['sometimes', 'array'],
            'alert_emails.*' => ['email', 'max:255'],
            'failed_login_threshold' => ['sometimes', 'integer', 'min:2', 'max:100'],
            'failed_login_window_minutes' => ['sometimes', 'integer', 'min:1', 'max:1440'],
        ]);

        $settings = $this->security->updateSettings($hub, $user, $data, $request);

        return response()->json([
            'data' => $settings->toApiArray(),
        ]);
    }

    private function accessHub(?User $user): Hub
    {
        if ($user) {
            return $this->actingHubs->capabilityHub($user, 'secmon_manage_access');
        }

        return $this->hubs->current();
    }

    /**
     * @return array<string, mixed>
     */
    private function accessSettingsValidationRules(): array
    {
        return [
            'uk_only' => ['sometimes', 'boolean'],
            'uk_cities_enabled' => ['sometimes', 'boolean'],
            'uk_cities' => ['sometimes', 'array', 'max:100'],
            'uk_cities.*' => ['string', 'max:120'],
            'restrict_countries_enabled' => ['sometimes', 'boolean'],
            'restricted_countries' => ['sometimes', 'array', 'max:250'],
            'restricted_countries.*' => ['string', 'size:2'],
            'allow_countries_enabled' => ['sometimes', 'boolean'],
            'allowed_countries' => ['sometimes', 'array', 'max:250'],
            'allowed_countries.*' => ['string', 'size:2'],
            'restrict_ips_enabled' => ['sometimes', 'boolean'],
            'restricted_ips' => ['sometimes', 'array', 'max:500'],
            'restricted_ips.*' => ['string', 'max:64'],
            'allow_ips_enabled' => ['sometimes', 'boolean'],
            'allowed_ips' => ['sometimes', 'array', 'max:500'],
            'allowed_ips.*' => ['string', 'max:64'],
            'restrict_vpn_enabled' => ['sometimes', 'boolean'],
            'allow_vpn_enabled' => ['sometimes', 'boolean'],
            'enforcement_mode' => ['sometimes', 'string', Rule::in(['monitor', 'enforce'])],
        ];
    }

    public function accessSettings(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $hub = $this->accessHub($user);
        $this->security->assertModuleEnabled($hub);

        return response()->json([
            'data' => $this->security->settingsFor($hub)->toAccessRestrictionApiArray(),
            'options' => [
                'rules' => AccessRestrictionService::ruleOptions(),
                'suggested_uk_cities' => AccessRestrictionService::SUGGESTED_UK_CITIES,
            ],
        ]);
    }

    public function updateAccessSettings(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $hub = $this->accessHub($user);

        $data = $request->validate($this->accessSettingsValidationRules());
        $settings = $this->security->updateAccessSettings($hub, $user, $data, $request);

        return response()->json([
            'data' => $settings->toAccessRestrictionApiArray(),
        ]);
    }

    public function accessControlUsers(Request $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $hub = $this->accessHub($actor);
        $this->security->assertModuleEnabled($hub);

        $validated = $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:255'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = User::query()->orderBy('name')->orderBy('id');
        if (! empty($validated['q'])) {
            $term = '%'.$validated['q'].'%';
            $query->where(function ($builder) use ($term) {
                $builder->where('name', 'like', $term)
                    ->orWhere('email', 'like', $term);
            });
        }

        $paginator = $query->paginate((int) ($validated['per_page'] ?? 50));

        return response()->json([
            'users' => collect($paginator->items())->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => $u->role,
            ])->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function userAccessSettings(Request $request, int $userId): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $hub = $this->accessHub($actor);
        $this->security->assertModuleEnabled($hub);

        $target = User::query()->findOrFail($userId);
        $settings = $this->security->userAccessSettingsFor($hub, $target);

        return response()->json([
            'user' => [
                'id' => $target->id,
                'name' => $target->name,
                'email' => $target->email,
                'role' => $target->role,
            ],
            'data' => $settings->exists
                ? $settings->toAccessRestrictionApiArray()
                : array_merge(
                    app(AccessRestrictionService::class)->emptySettings(),
                    ['hub_id' => $hub->id, 'user_id' => $target->id]
                ),
            'options' => [
                'rules' => AccessRestrictionService::ruleOptions(),
                'suggested_uk_cities' => AccessRestrictionService::SUGGESTED_UK_CITIES,
            ],
        ]);
    }

    public function updateUserAccessSettings(Request $request, int $userId): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $hub = $this->accessHub($actor);
        $target = User::query()->findOrFail($userId);

        $data = $request->validate($this->accessSettingsValidationRules());
        $settings = $this->security->updateUserAccessSettings($hub, $actor, $target, $data, $request);

        return response()->json([
            'user' => [
                'id' => $target->id,
                'name' => $target->name,
                'email' => $target->email,
                'role' => $target->role,
            ],
            'data' => $settings->toAccessRestrictionApiArray(),
        ]);
    }

    public function disasterModules(Request $request): JsonResponse
    {
        $hub = $this->securityHub($request->user());
        $this->security->assertModuleEnabled($hub);

        return response()->json([
            'modules' => $this->disasterRecovery->recoverableModules(),
        ]);
    }

    public function disasterSnapshots(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $hub = $this->securityHub($user);
        $this->security->assertModuleEnabled($hub);

        $validated = $request->validate([
            'module' => [
                'sometimes',
                'nullable',
                'string',
                Rule::in($this->disasterRecovery->recoverableModuleKeys()),
            ],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $items = $this->disasterRecovery->listSnapshots(
            $hub,
            $validated['module'] ?? null,
            (int) ($validated['limit'] ?? 50)
        );

        return response()->json([
            'data' => array_map(
                fn (ModuleDisasterSnapshot $s) => $s->toApiArray(),
                $items
            ),
        ]);
    }

    public function createDisasterSnapshot(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $hub = $this->securityHub($user);
        $this->security->assertCan($hub, $user, 'secmon_manage_disaster_recovery');

        $validated = $request->validate([
            'module_key' => ['required', 'string', Rule::in($this->disasterRecovery->recoverableModuleKeys())],
            'label' => ['sometimes', 'nullable', 'string', 'max:255'],
            'security_incident_id' => ['sometimes', 'nullable', 'integer', 'exists:security_incidents,id'],
        ]);

        $incident = null;
        if (! empty($validated['security_incident_id'])) {
            $incident = SecurityIncident::query()->findOrFail($validated['security_incident_id']);
            $this->security->assertIncidentOnHub($hub, $incident);
        }

        try {
            $snapshot = $this->disasterRecovery->createSnapshot(
                $hub,
                $validated['module_key'],
                $user,
                ModuleDisasterSnapshot::TRIGGER_MANUAL,
                $validated['label'] ?? null,
                $incident
            );
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Module snapshot created.',
            'data' => $snapshot->toApiArray(),
            'incident' => $incident?->fresh()?->toApiArray(includeDetails: true),
        ], 201);
    }

    public function recognizeDisaster(Request $request, SecurityIncident $securityIncident): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $hub = $this->securityHub($user);
        $this->security->assertCan($hub, $user, 'secmon_manage_disaster_recovery');
        $this->security->assertIncidentOnHub($hub, $securityIncident);

        $validated = $request->validate([
            'affected_module' => [
                'required',
                'string',
                Rule::in($this->disasterRecovery->recoverableModuleKeys()),
            ],
        ]);

        try {
            $incident = $this->disasterRecovery->recognizeDisaster(
                $hub,
                $securityIncident,
                $validated['affected_module'],
                $user,
                $request
            );
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $snapshots = $this->disasterRecovery->listSnapshots($hub, $validated['affected_module'], 25);

        return response()->json([
            'message' => 'Disaster recognized for '.$validated['affected_module'].'.',
            'data' => $incident->toApiArray(includeDetails: true),
            'snapshots' => array_map(fn (ModuleDisasterSnapshot $s) => $s->toApiArray(), $snapshots),
        ]);
    }

    public function rollbackDisaster(Request $request, SecurityIncident $securityIncident): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $hub = $this->securityHub($user);
        $this->security->assertCan($hub, $user, 'secmon_manage_disaster_recovery');
        $this->security->assertIncidentOnHub($hub, $securityIncident);

        $validated = $request->validate([
            'snapshot_id' => ['required', 'integer', 'exists:module_disaster_snapshots,id'],
        ]);

        try {
            $result = $this->disasterRecovery->rollbackIncident(
                $hub,
                $securityIncident,
                (int) $validated['snapshot_id'],
                $user,
                $request
            );
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Module rolled back from snapshot #'.$validated['snapshot_id'].'.',
            'data' => $result['incident']->toApiArray(includeDetails: true),
            'restored_snapshot' => $result['restored_snapshot']->toApiArray(),
            'pre_rollback_snapshot' => $result['pre_rollback_snapshot']->toApiArray(),
        ]);
    }

    /**
     * On-demand security check (dependency audit). No daily schedule —
     * capable users run this when they want a fresh scan.
     */
    public function scanNow(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $hub = $this->securityHub($user);
        $result = $this->security->scanNow($hub, $user, $request);

        $payload = ['data' => $result];

        try {
            $payload['report'] = $this->security->report($hub, $user);
        } catch (\Throwable) {
            $payload['report'] = null;
        }

        return response()->json($payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function eventFilters(Request $request): array
    {
        $validated = $request->validate([
            'severity' => ['sometimes', 'nullable', 'string', 'max:20'],
            'category' => ['sometimes', 'nullable', 'string', 'max:40'],
            'status' => ['sometimes', 'nullable', 'string', 'max:40'],
            'event_type' => ['sometimes', 'nullable', 'string', 'max:120'],
            'source_ip' => ['sometimes', 'nullable', 'string', 'max:45'],
            'actor_user_id' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'q' => ['sometimes', 'nullable', 'string', 'max:255'],
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date'],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return [
            'severity' => $validated['severity'] ?? null,
            'category' => $validated['category'] ?? null,
            'status' => $validated['status'] ?? null,
            'event_type' => $validated['event_type'] ?? null,
            'source_ip' => $validated['source_ip'] ?? null,
            'actor_user_id' => $validated['actor_user_id'] ?? null,
            'q' => $validated['q'] ?? null,
            'from' => $validated['from'] ?? null,
            'to' => $validated['to'] ?? null,
            'per_page' => $validated['per_page'] ?? 25,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function incidentFilters(Request $request): array
    {
        $validated = $request->validate([
            'severity' => ['sometimes', 'nullable', 'string', 'max:20'],
            'category' => ['sometimes', 'nullable', 'string', 'max:40'],
            'status' => ['sometimes', 'nullable', 'string', 'max:40'],
            'q' => ['sometimes', 'nullable', 'string', 'max:255'],
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date'],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return [
            'severity' => $validated['severity'] ?? null,
            'category' => $validated['category'] ?? null,
            'status' => $validated['status'] ?? null,
            'q' => $validated['q'] ?? null,
            'from' => $validated['from'] ?? null,
            'to' => $validated['to'] ?? null,
            'per_page' => $validated['per_page'] ?? 25,
        ];
    }
}
