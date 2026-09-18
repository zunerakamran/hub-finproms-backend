<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Hub;
use App\Services\ActingHubService;
use App\Services\ComplianceStatusDisplayNameService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class ComplianceStatusDisplayNameController extends Controller
{
    public function __construct(
        private readonly ActingHubService $actingHubs,
        private readonly ComplianceStatusDisplayNameService $complianceStatusDisplayNames
    ) {}

    public function index(Request $request): JsonResponse
    {
        $hub = $this->targetHub($request);

        return response()->json([
            'hub' => [
                'id' => $hub->id,
                'name' => $hub->name,
                'slug' => $hub->slug,
                'type' => $hub->type,
            ],
            'statuses' => $this->complianceStatusDisplayNames->editableStatuses($hub),
            'compliance_status_labels' => $this->complianceStatusDisplayNames->labels($hub),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'statuses' => ['required', 'array'],
            'statuses.*' => ['nullable', 'string', 'max:100'],
        ]);

        $hub = $this->targetHub($request);

        try {
            $payload = $this->complianceStatusDisplayNames->update($hub, $validated['statuses']);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Compliance status display names updated successfully.',
            'hub' => [
                'id' => $hub->id,
                'name' => $hub->name,
                'slug' => $hub->slug,
                'type' => $hub->type,
            ],
            ...$payload,
        ]);
    }

    private function targetHub(Request $request): Hub
    {
        return $this->actingHubs->targetHub($request->user());
    }
}
