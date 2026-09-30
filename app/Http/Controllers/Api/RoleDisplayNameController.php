<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Hub;
use App\Services\ActingHubService;
use App\Services\HubRolesService;
use App\Services\RoleDisplayNameService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class RoleDisplayNameController extends Controller
{
    public function __construct(
        private readonly ActingHubService $actingHubs,
        private readonly RoleDisplayNameService $roleDisplayNames,
        private readonly HubRolesService $hubRoles
    ) {}

    public function index(Request $request): JsonResponse
    {
        $hub = $this->targetHub($request);
        $rolesMeta = $this->hubRoles->matrixRolesPayload($hub);

        return response()->json([
            'hub' => [
                'id' => $hub->id,
                'name' => $hub->name,
                'slug' => $hub->slug,
                'type' => $hub->type,
            ],
            'roles' => $this->editableRolesFromMeta($rolesMeta['roles']),
            'role_labels' => $this->roleDisplayNames->labels($hub),
            'available_to_add' => $rolesMeta['available_to_add'],
            'custom_roles' => $rolesMeta['custom_roles'],
            'added_to_hub' => $rolesMeta['added_to_hub'],
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

        $hub = $hub->fresh() ?? $hub;
        $rolesMeta = $this->hubRoles->matrixRolesPayload($hub);

        return response()->json([
            'message' => 'Role display names updated successfully.',
            'hub' => [
                'id' => $hub->id,
                'name' => $hub->name,
                'slug' => $hub->slug,
                'type' => $hub->type,
            ],
            ...$payload,
            'available_to_add' => $rolesMeta['available_to_add'],
            'custom_roles' => $rolesMeta['custom_roles'],
            'added_to_hub' => $rolesMeta['added_to_hub'],
        ]);
    }

    /**
     * Add a catalog or custom role to the acting hub’s matrix (Manage roles).
     */
    public function addRole(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'key' => ['sometimes', 'nullable', 'string', 'max:41'],
            'label' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        $hub = $this->targetHub($request);

        try {
            $added = $this->hubRoles->addRoleToHub(
                $hub,
                $validated['key'] ?? null,
                $validated['label'] ?? null
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $hub = $hub->fresh() ?? $hub;

        return response()->json([
            'message' => 'Role added to '.$hub->name.'.',
            'hub' => [
                'id' => $hub->id,
                'name' => $hub->name,
                'slug' => $hub->slug,
                'type' => $hub->type,
            ],
            'role' => $added['role'],
            'roles' => $this->editableRolesFromMeta(
                $this->hubRoles->matrixRolesPayload($hub)['roles']
            ),
            'role_labels' => $this->roleDisplayNames->labels($hub),
            'available_to_add' => $added['available_to_add'],
            'custom_roles' => $added['custom_roles'],
            'added_to_hub' => $added['added_to_hub'],
        ]);
    }

    /**
     * Remove a role from the acting hub’s Capabilities matrix (Manage roles).
     */
    public function removeRole(Request $request, string $role): JsonResponse
    {
        $hub = $this->targetHub($request);

        try {
            $removed = $this->hubRoles->removeRoleFromHub($hub, $role);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $hub = $hub->fresh() ?? $hub;

        return response()->json([
            'message' => 'Role removed from '.$hub->name.'.',
            'hub' => [
                'id' => $hub->id,
                'name' => $hub->name,
                'slug' => $hub->slug,
                'type' => $hub->type,
            ],
            'removed' => $removed['removed'],
            'roles' => $this->editableRolesFromMeta(
                $this->hubRoles->matrixRolesPayload($hub)['roles']
            ),
            'role_labels' => $this->roleDisplayNames->labels($hub),
            'available_to_add' => $removed['available_to_add'],
            'custom_roles' => $removed['custom_roles'],
            'added_to_hub' => $removed['added_to_hub'],
        ]);
    }

    private function targetHub(Request $request): Hub
    {
        return $this->actingHubs->targetHub($request->user());
    }

    /**
     * @param  list<array<string, mixed>>  $roles
     * @return list<array{key: string, label: string, default_label: string, user_count: int, can_delete: bool, is_custom: bool, is_must: bool}>
     */
    private function editableRolesFromMeta(array $roles): array
    {
        $out = [];
        foreach ($roles as $role) {
            if (! is_array($role) || ! isset($role['key'])) {
                continue;
            }
            $out[] = [
                'key' => (string) $role['key'],
                'label' => (string) ($role['label'] ?? $role['default_label'] ?? $role['key']),
                'default_label' => (string) ($role['default_label'] ?? $role['key']),
                'user_count' => (int) ($role['user_count'] ?? 0),
                'can_delete' => (bool) ($role['can_delete'] ?? false),
                'is_custom' => (bool) ($role['is_custom'] ?? false),
                'is_must' => (bool) ($role['is_must'] ?? false),
            ];
        }

        return $out;
    }
}
