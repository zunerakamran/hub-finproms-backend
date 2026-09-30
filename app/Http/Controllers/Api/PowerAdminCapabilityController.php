<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Hub;
use App\Services\ActingHubService;
use App\Services\CapabilitiesMatrixService;
use App\Services\HubRolesService;
use App\Services\PowerAdminCapabilitiesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class PowerAdminCapabilityController extends Controller
{
    public function __construct(
        private readonly PowerAdminCapabilitiesService $capabilities,
        private readonly CapabilitiesMatrixService $matrix,
        private readonly ActingHubService $actingHubs,
        private readonly HubRolesService $hubRoles
    ) {}

    /**
     * Flat Power Admin capabilities (also used by /capabilities/me).
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'capabilities' => $this->capabilities->forAdmin(),
            'resolved' => $this->capabilities->resolved(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'capabilities' => ['required', 'array'],
        ]);

        $input = $this->normalizeListPayload($validated['capabilities']);
        $unknown = array_diff(array_keys($input), array_keys(PowerAdminCapabilitiesService::DEFINITIONS));
        if ($unknown !== []) {
            return response()->json([
                'message' => 'Unknown capability keys: '.implode(', ', $unknown),
            ], 422);
        }

        $items = $this->capabilities->update($input);

        return response()->json([
            'message' => 'Power Admin capabilities updated.',
            'capabilities' => $items,
            'resolved' => $this->capabilities->resolved(),
        ]);
    }

    /**
     * Full role × capability matrix for a hub (includes Power Admin column).
     */
    public function matrix(Request $request): JsonResponse
    {
        // Ensure platform pa_* defaults exist on a freshly seeded Central DB.
        $this->capabilities->seedDefaults();

        $hubId = $request->integer('hub_id');
        if (! $hubId && $request->user()) {
            $hubId = $this->actingHubs->actingHub($request->user())->id;
        }
        // Prefer explicit hub → acting hub → Central/control-plane row → any hub.
        // Never hard-require slug=shared (fresh Central has no Shared registry row yet).
        $hub = $hubId
            ? Hub::query()->findOrFail($hubId)
            : (Hub::query()->where('type', Hub::TYPE_CENTRAL)->first()
                ?? Hub::query()->where('type', Hub::TYPE_SHARED)->first()
                ?? Hub::query()->orderBy('id')->firstOrFail());

        $hubs = Hub::query()
            ->orderByRaw('CASE
                WHEN type = ? THEN 0
                WHEN type = ? THEN 1
                ELSE 2
            END', [Hub::TYPE_CENTRAL, Hub::TYPE_SHARED])
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'type']);

        return response()->json([
            'hubs' => $hubs,
            'matrix' => $this->matrix->matrix($hub),
        ]);
    }

    public function updateMatrix(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'hub_id' => ['required', 'integer', 'exists:hubs,id'],
            'behaviour' => ['sometimes', 'array'],
            'modules' => ['sometimes', 'array'],
            'matrix' => ['sometimes', 'array'],
            'power_admin' => ['sometimes', 'array'],
        ]);

        $hub = Hub::query()->findOrFail($validated['hub_id']);
        $actingWhiteLabel = null;
        $actor = $request->user();
        if ($actor && $this->actingHubs->isActingRemotely($actor)) {
            $actingWhiteLabel = $this->actingHubs->actingHub($actor);
        }
        try {
            $result = $this->matrix->update($hub, $validated, $actingWhiteLabel);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Capabilities matrix saved.',
            'matrix' => $result,
            'resolved' => $this->capabilities->resolved(),
        ]);
    }

    /**
     * Add a catalog or custom role to every hub’s Capabilities matrix.
     */
    public function addRole(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'key' => ['sometimes', 'nullable', 'string', 'max:41'],
            'label' => ['sometimes', 'nullable', 'string', 'max:100'],
            'hub_id' => ['sometimes', 'nullable', 'integer', 'exists:hubs,id'],
        ]);

        try {
            $added = $this->hubRoles->addRoleToAllHubs(
                $validated['key'] ?? null,
                $validated['label'] ?? null
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $hubId = (int) ($validated['hub_id'] ?? 0);
        if (! $hubId && $request->user()) {
            $hubId = (int) $this->actingHubs->actingHub($request->user())->id;
        }
        $hub = $hubId
            ? Hub::query()->findOrFail($hubId)
            : (Hub::query()->where('type', Hub::TYPE_CENTRAL)->first()
                ?? Hub::query()->orderBy('id')->firstOrFail());

        return response()->json([
            'message' => 'Role added to all hubs.',
            'role' => $added['role'],
            'available_to_add' => $added['available_to_add'],
            'custom_roles' => $added['custom_roles'],
            'added_to_all_hubs' => $added['added_to_all_hubs'],
            'matrix' => $this->matrix->matrix($hub),
            'resolved' => $this->capabilities->resolved(),
        ]);
    }

    /**
     * @param  array<int|string, mixed>  $capabilities
     * @return array<string, mixed>
     */
    private function normalizeListPayload(array $capabilities): array
    {
        if (array_is_list($capabilities)) {
            $map = [];
            foreach ($capabilities as $row) {
                if (! is_array($row) || ! isset($row['key'])) {
                    continue;
                }
                $enabled = $row['enabled'] ?? $row['value'] ?? null;
                if ($enabled === null) {
                    continue;
                }
                $map[(string) $row['key']] = $enabled;
            }

            return $map;
        }

        return $capabilities;
    }
}
