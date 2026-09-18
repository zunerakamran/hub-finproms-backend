<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Hub;
use App\Services\ActingHubService;
use App\Services\RoleDisplayNameService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class RoleDisplayNameController extends Controller
{
    public function __construct(
        private readonly ActingHubService $actingHubs,
        private readonly RoleDisplayNameService $roleDisplayNames
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
            'roles' => $this->roleDisplayNames->editableRoles($hub),
            'role_labels' => $this->roleDisplayNames->labels($hub),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'roles' => ['required', 'array'],
            'roles.*' => ['nullable', 'string', 'max:100'],
        ]);

        $hub = $this->targetHub($request);

        try {
            $payload = $this->roleDisplayNames->update($hub, $validated['roles']);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Role display names updated successfully.',
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
