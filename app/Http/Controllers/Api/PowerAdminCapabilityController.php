<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Hub;
use App\Services\CapabilitiesMatrixService;
use App\Services\PowerAdminCapabilitiesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PowerAdminCapabilityController extends Controller
{
    public function __construct(
        private readonly PowerAdminCapabilitiesService $capabilities,
        private readonly CapabilitiesMatrixService $matrix
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
        $hubId = $request->integer('hub_id');
        $hub = $hubId
            ? Hub::query()->findOrFail($hubId)
            : Hub::query()->where('slug', 'shared')->firstOrFail();

        $hubs = Hub::query()
            ->orderByRaw("CASE WHEN type = ? THEN 0 ELSE 1 END", [Hub::TYPE_SHARED])
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
            'matrix' => ['sometimes', 'array'],
            'power_admin' => ['sometimes', 'array'],
        ]);

        $hub = Hub::query()->findOrFail($validated['hub_id']);
        $result = $this->matrix->update($hub, $validated);

        return response()->json([
            'message' => 'Capabilities matrix saved.',
            'matrix' => $result,
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
