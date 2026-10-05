<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Hub;
use App\Models\User;
use App\Services\ActingHubService;
use App\Services\WhiteLabelUserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Read-only list of all users on the current (or acting) hub.
 * Gated by dashboard_view_hub_users — distinct from platform Users & roles.
 */
class HubUsersController extends Controller
{
    public function __construct(
        private readonly ActingHubService $actingHubs,
        private readonly WhiteLabelUserService $whiteLabelUsers
    ) {}

    public function index(Request $request): JsonResponse
    {
        $targetHub = $this->targetHub($request);
        $assignableRoles = $this->assignableRoles($targetHub);

        $validated = $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:255'],
            'role' => ['sometimes', 'nullable', 'string', Rule::in($assignableRoles)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 50);
        $page = max(1, (int) ($validated['page'] ?? 1));

        if ($remoteHub = $this->actingContentHub($request)) {
            try {
                $listed = $this->whiteLabelUsers->paginate(
                    $remoteHub,
                    $validated['q'] ?? null,
                    $validated['role'] ?? null,
                    $perPage,
                    $page
                );
            } catch (InvalidArgumentException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            return response()->json([
                'users' => $listed['users'],
                'meta' => $listed['meta'],
                'roles' => $this->roleOptions($remoteHub),
                'hub' => $this->hubPayload($remoteHub),
                'acting_remotely' => true,
            ]);
        }

        $query = User::query()->with('firm:id,name')->orderBy('name')->orderBy('id');

        if (! empty($validated['q'])) {
            $term = '%'.$validated['q'].'%';
            $query->where(function ($builder) use ($term) {
                $builder->where('name', 'like', $term)
                    ->orWhere('email', 'like', $term);
            });
        }

        if (! empty($validated['role'])) {
            $query->where('role', $validated['role']);
        }

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);
        $labelHub = $targetHub;

        return response()->json([
            'users' => $paginator->getCollection()
                ->map(fn (User $user) => $this->whiteLabelUsers->serialize($user, $labelHub))
                ->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'roles' => $this->roleOptions($labelHub),
            'hub' => $labelHub ? $this->hubPayload($labelHub) : null,
            'acting_remotely' => false,
        ]);
    }

    private function targetHub(Request $request): ?Hub
    {
        $user = $request->user();
        if (! $user) {
            return null;
        }

        try {
            return $this->actingHubs->targetHub($user);
        } catch (\Throwable) {
            return null;
        }
    }

    private function actingContentHub(Request $request): ?Hub
    {
        $user = $request->user();
        if (! $user || ! $this->actingHubs->isActingRemotely($user)) {
            return null;
        }

        try {
            return $this->actingHubs->requireActingContentHub($user);
        } catch (InvalidArgumentException $e) {
            throw new HttpException(422, $e->getMessage());
        }
    }

    /**
     * @return list<string>
     */
    private function assignableRoles(?Hub $hub): array
    {
        return array_keys(User::ROLE_LABELS);
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    private function roleOptions(?Hub $hub): array
    {
        $roles = [];
        foreach ($this->assignableRoles($hub) as $role) {
            $roles[] = [
                'key' => $role,
                'label' => $hub
                    ? $hub->roleLabel($role)
                    : (User::ROLE_LABELS[$role] ?? $role),
            ];
        }

        return $roles;
    }

    /**
     * @return array{id: int, name: string, slug: string, type: string}
     */
    private function hubPayload(Hub $hub): array
    {
        return [
            'id' => $hub->id,
            'name' => $hub->name,
            'slug' => $hub->slug,
            'type' => $hub->type,
        ];
    }
}
