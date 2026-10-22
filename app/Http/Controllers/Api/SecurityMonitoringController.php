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
            'restricted_cities' => ['sometimes', 'array', 'max:250'],
            'restricted_cities.*' => ['string', 'max:120'],
            'allow_countries_enabled' => ['sometimes', 'boolean'],
            'allowed_countries' => ['sometimes', 'array', 'max:250'],
            'allowed_countries.*' => ['string', 'size:2'],
            'allowed_cities' => ['sometimes', 'array', 'max:250'],
            'allowed_cities.*' => ['string', 'max:120'],
            'restrict_ips_enabled' => ['sometimes', 'boolean'],
            'restricted_ips' => ['sometimes', 'array', 'max:500'],
            // Single IP / CIDR / hyphen range (e.g. 203.0.113.10-203.0.113.50); IPv6 ranges need headroom.
            'restricted_ips.*' => ['string', 'max:128'],
            'allow_ips_enabled' => ['sometimes', 'boolean'],
            'allowed_ips' => ['sometimes', 'array', 'max:500'],
            'allowed_ips.*' => ['string', 'max:128'],
            'restrict_vpn_enabled' => ['sometimes', 'boolean'],
            'allow_vpn_enabled' => ['sometimes', 'boolean'],
            'enforcement_mode' => ['sometimes', 'string', Rule::in(['monitor', 'enforce'])],
            'acknowledge_self_lockout' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: array<string, mixed>, 1: bool}
     */
    private function splitAccessSettingsPayload(array $data): array
    {
        $acknowledge = (bool) ($data['acknowledge_self_lockout'] ?? false);
        unset($data['acknowledge_self_lockout']);

        return [$data, $acknowledge];
    }

    /**
     * @param  array<string, mixed>  $preview
     */
    private function selfLockoutConflictResponse(array $preview): JsonResponse
    {
        return response()->json([
            'message' => 'These rules would block your current IP in Enforce mode. Confirm to save anyway, or switch to Monitor / adjust rules.',
            'code' => 'self_lockout_risk',
            'preview' => $preview,
        ], 409);
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

        [$data, $acknowledge] = $this->splitAccessSettingsPayload(
            $request->validate($this->accessSettingsValidationRules())
        );
        $this->security->validateAccessSettingsInput($data);

        $preview = $this->security->previewSelfLockout($hub, $user, $data, 'hub', null, $request);
        $currentPreview = $this->security->previewSelfLockout(
            $hub,
            $user,
            $this->security->settingsFor($hub)->toAccessRestrictionApiArray(),
            'hub',
            null,
            $request
        );
        if (($preview['result'] ?? '') === 'block'
            && ($currentPreview['result'] ?? '') !== 'block'
            && ! $acknowledge
        ) {
            return $this->selfLockoutConflictResponse($preview);
        }

        $settings = $this->security->updateAccessSettings($hub, $user, $data, $request);

        return response()->json([
            'data' => $settings->toAccessRestrictionApiArray(),
            'self_lockout_preview' => $preview,
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

        $paginator = $query->with('roleAssignments')->paginate((int) ($validated['per_page'] ?? 50));

        return response()->json([
            'users' => collect($paginator->items())->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => $u->role,
                'roles' => $u->roleKeys(),
                'role_labels' => $u->role_labels,
                'primary_role' => $u->role,
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
                'roles' => $target->roleKeys(),
                'role_labels' => $target->role_labels,
                'primary_role' => $target->role,
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

        [$data, $acknowledge] = $this->splitAccessSettingsPayload(
            $request->validate($this->accessSettingsValidationRules())
        );
        $this->security->validateAccessSettingsInput($data);

        $preview = $this->security->previewSelfLockout($hub, $actor, $data, 'user', $target, $request);
        $existing = $this->security->userAccessSettingsFor($hub, $target);
        $currentPreview = $this->security->previewSelfLockout(
            $hub,
            $actor,
            $existing->exists
                ? $existing->toAccessRestrictionApiArray()
                : app(AccessRestrictionService::class)->emptySettings(),
            'user',
            $target,
            $request
        );
        if (($preview['result'] ?? '') === 'block'
            && ($currentPreview['result'] ?? '') !== 'block'
            && ! $acknowledge
            && (int) $actor->id === (int) $target->id
        ) {
            return $this->selfLockoutConflictResponse($preview);
        }

        $settings = $this->security->updateUserAccessSettings($hub, $actor, $target, $data, $request);

        return response()->json([
            'user' => [
                'id' => $target->id,
                'name' => $target->name,
                'email' => $target->email,
                'role' => $target->role,
                'roles' => $target->roleKeys(),
                'role_labels' => $target->role_labels,
                'primary_role' => $target->role,
            ],
            'data' => $settings->toAccessRestrictionApiArray(),
            'self_lockout_preview' => $preview,
        ]);
    }

    public function restoreAccessPolicyChange(Request $request, int $changeId): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $hub = $this->accessHub($actor);

        $validated = $request->validate([
            'snapshot' => ['sometimes', 'string', Rule::in(['before', 'after'])],
        ]);

        $event = SecurityEvent::query()->findOrFail($changeId);
        $settings = $this->security->restoreAccessPolicyChange(
            $hub,
            $actor,
            $event,
            $validated['snapshot'] ?? 'before',
            $request
        );

        $payload = [
            'restored_from_event_id' => $event->id,
            'snapshot' => ($validated['snapshot'] ?? 'before'),
            'data' => $settings->toAccessRestrictionApiArray(),
        ];

        if ($settings instanceof \App\Models\UserAccessRestrictionSetting) {
            $target = User::query()->find($settings->user_id);
            $payload['user'] = $target ? [
                'id' => $target->id,
                'name' => $target->name,
                'email' => $target->email,
                'role' => $target->role,
                'roles' => $target->roleKeys(),
                'role_labels' => $target->role_labels,
                'primary_role' => $target->role,
            ] : null;
            $payload['scope'] = 'user';
        } else {
            $payload['scope'] = 'hub';
        }

        return response()->json($payload);
    }

    public function emergencyUnlockAccess(Request $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $hub = $this->accessHub($actor);

        $validated = $request->validate([
            'keep_rules' => ['sometimes', 'boolean'],
        ]);

        $result = $this->security->emergencyUnlockAccess(
            $hub,
            $actor,
            $request,
            (bool) ($validated['keep_rules'] ?? false)
        );

        return response()->json([
            'message' => ($result['keep_rules'] ?? false)
                ? 'Enforcement switched to Monitor. Rules kept.'
                : 'Blocking access gates cleared and enforcement set to Monitor.',
            'data' => $result['settings'] ?? null,
            'user_rows' => $result['user_rows'] ?? 0,
        ]);
    }

    public function accessBlockedAttempts(Request $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $hub = $this->accessHub($actor);

        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'q' => ['sometimes', 'nullable', 'string', 'max:120'],
            'event_type' => ['sometimes', 'nullable', 'string', Rule::in(SecurityMonitoringService::ACCESS_BLOCKED_ATTEMPT_EVENTS)],
        ]);

        $paginator = $this->security->listAccessBlockedAttempts($hub, $actor, [
            'per_page' => $validated['per_page'] ?? 25,
            'q' => $validated['q'] ?? null,
            'event_type' => $validated['event_type'] ?? null,
        ]);

        return response()->json([
            'data' => collect($paginator->items())->map(function (SecurityEvent $row) {
                $base = $row->toApiArray(includeDetails: true);
                $props = (array) ($base['properties'] ?? []);

                return [
                    'id' => $base['id'],
                    'occurred_at' => $base['occurred_at'],
                    'event_type' => $base['event_type'],
                    'title' => $base['title'],
                    'description' => $base['description'],
                    'severity' => $base['severity'],
                    'actor' => $base['actor'],
                    'source_ip' => $base['source_ip'] ?? ($props['source_ip'] ?? null),
                    'country' => $props['country'] ?? null,
                    'city' => $props['city'] ?? null,
                    'path' => $props['path'] ?? null,
                    'http_method' => $props['http_method'] ?? null,
                    'reason' => $props['reason'] ?? null,
                    'vpn' => $props['vpn'] ?? null,
                    'monitor_only' => (bool) ($props['monitor_only'] ?? false),
                    'user' => (($props['user_id'] ?? null) ?: ($base['actor']['id'] ?? null))
                        ? [
                            'id' => $props['user_id'] ?? ($base['actor']['id'] ?? null),
                            'name' => $props['user_name'] ?? ($base['actor']['name'] ?? null),
                            'email' => $props['user_email'] ?? ($base['actor']['email'] ?? null),
                        ]
                        : null,
                ];
            })->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function accessPolicyChanges(Request $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $hub = $this->accessHub($actor);

        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'scope' => ['sometimes', 'nullable', 'string', Rule::in(['hub', 'user'])],
            'user_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $paginator = $this->security->listAccessPolicyChanges($hub, $actor, [
            'per_page' => $validated['per_page'] ?? 25,
            'scope' => $validated['scope'] ?? null,
            'user_id' => $validated['user_id'] ?? null,
        ]);

        return response()->json([
            'data' => collect($paginator->items())->map(function (SecurityEvent $row) {
                $base = $row->toApiArray(includeDetails: true);
                $props = (array) ($base['properties'] ?? []);

                return [
                    'id' => $base['id'],
                    'occurred_at' => $base['occurred_at'],
                    'title' => $base['title'],
                    'description' => $base['description'],
                    'actor' => $base['actor'],
                    'source_ip' => $base['source_ip'],
                    'scope' => $props['scope'] ?? null,
                    'target_user' => ($props['target_user_id'] ?? null)
                        ? [
                            'id' => $props['target_user_id'],
                            'name' => $props['target_user_name'] ?? null,
                            'email' => $props['target_user_email'] ?? null,
                        ]
                        : null,
                    'changed_keys' => $props['changed_keys'] ?? [],
                    'changes' => $props['changes'] ?? [],
                    'change_summary' => $props['change_summary'] ?? null,
                    'can_restore' => ! empty($props['before']) || ! empty($props['after']),
                    'has_before' => ! empty($props['before']),
                    'has_after' => ! empty($props['after']),
                ];
            })->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /**
     * Dry-run access rules for an IP (and optional user / geo hints). Never blocks.
     */
    public function simulateAccess(Request $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $hub = $this->accessHub($actor);
        $this->security->assertModuleEnabled($hub);

        $data = $request->validate([
            'ip' => ['sometimes', 'nullable', 'string', 'max:64'],
            'user_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'country' => ['sometimes', 'nullable', 'string', 'size:2'],
            'city' => ['sometimes', 'nullable', 'string', 'max:120'],
            'vpn' => ['sometimes', 'nullable', 'boolean'],
        ]);

        $ip = trim((string) ($data['ip'] ?? ''));
        $usingRequestIp = $ip === '';
        if ($usingRequestIp) {
            $ip = (string) $request->ip();
        }

        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return response()->json([
                'message' => 'A valid IPv4 or IPv6 address is required.',
                'errors' => ['ip' => ['A valid IPv4 or IPv6 address is required.']],
            ], 422);
        }

        $asUser = null;
        if (! empty($data['user_id'])) {
            $asUser = User::query()->findOrFail((int) $data['user_id']);
        }

        $overrides = [];
        if (array_key_exists('country', $data) && $data['country'] !== null && $data['country'] !== '') {
            $overrides['country'] = strtoupper((string) $data['country']);
        }
        if (array_key_exists('city', $data) && $data['city'] !== null && $data['city'] !== '') {
            $overrides['city'] = (string) $data['city'];
        }
        if (array_key_exists('vpn', $data) && $data['vpn'] !== null) {
            $overrides['vpn'] = (bool) $data['vpn'];
        }

        $simulation = app(AccessRestrictionService::class)->simulateForHub(
            $hub,
            $ip,
            $asUser,
            $overrides
        );

        $actorIp = (string) $request->ip();
        $selfLockoutRisk = $actorIp !== ''
            && strcasecmp($actorIp, $ip) === 0
            && in_array($simulation['result'] ?? '', ['block', 'would_block'], true);

        return response()->json([
            'data' => array_merge($simulation, [
                'using_request_ip' => $usingRequestIp,
                'self_lockout_risk' => $selfLockoutRisk,
            ]),
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
