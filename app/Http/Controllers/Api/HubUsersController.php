<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Hub;
use App\Models\User;
use App\Services\ActingHubService;
use App\Services\HubUserAdminService;
use App\Services\WhiteLabelUserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Hub → Users listing + per-action admin mutations.
 * List gated by dashboard_view_hub_users; each action has its own capability.
 */
class HubUsersController extends Controller
{
    public function __construct(
        private readonly ActingHubService $actingHubs,
        private readonly WhiteLabelUserService $whiteLabelUsers,
        private readonly HubUserAdminService $hubUserAdmin,
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
                'module_options' => $this->moduleOptions($remoteHub),
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
            $role = (string) $validated['role'];
            $query->where(function ($builder) use ($role) {
                $builder->where('role', $role)
                    ->orWhereHas('roleAssignments', fn ($r) => $r->where('role', $role));
            });
        }

        $paginator = $query->with(['firm:id,name', 'roleAssignments'])->paginate($perPage, ['*'], 'page', $page);
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
            'module_options' => $this->moduleOptions($labelHub),
            'hub' => $labelHub ? $this->hubPayload($labelHub) : null,
            'acting_remotely' => false,
        ]);
    }

    public function loginHistory(Request $request, int $userId): JsonResponse
    {
        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        try {
            $payload = $this->hubUserAdmin->loginHistory(
                $request,
                $userId,
                (int) ($validated['per_page'] ?? 50),
                max(1, (int) ($validated['page'] ?? 1)),
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }

        return response()->json($payload);
    }

    public function securityToggleLogs(Request $request, int $userId): JsonResponse
    {
        try {
            $payload = $this->hubUserAdmin->securityToggleLogs($request, $userId);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }

        return response()->json($payload);
    }

    public function suspend(Request $request, int $userId): JsonResponse
    {
        $validated = $request->validate([
            'remarks' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        try {
            $user = $this->hubUserAdmin->suspend($request, $userId, $validated['remarks'] ?? null);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        } catch (ValidationException $e) {
            throw $e;
        }

        return response()->json([
            'message' => 'User suspended / discontinued.',
            'user' => $user,
        ]);
    }

    public function changePassword(Request $request, int $userId): JsonResponse
    {
        try {
            $result = $this->hubUserAdmin->changePassword($request, $userId);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }

        return response()->json([
            'message' => 'New password generated and emailed to the verified address.',
            'user' => $result['user'],
        ]);
    }

    public function updateRoles(Request $request, int $userId): JsonResponse
    {
        $validated = $request->validate([
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['required', 'string', Rule::in(array_keys(User::ROLE_LABELS))],
        ]);

        try {
            $user = $this->hubUserAdmin->updateRoles($request, $userId, $validated['roles']);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }

        return response()->json([
            'message' => 'Roles updated.',
            'user' => $user,
        ]);
    }

    public function updateModules(Request $request, int $userId): JsonResponse
    {
        $validated = $request->validate([
            'unrestricted' => ['sometimes', 'boolean'],
            'modules' => ['sometimes', 'nullable', 'array'],
            'modules.*' => ['string', Rule::in(Hub::MODULE_KEYS)],
        ]);

        $modules = null;
        if (! ($validated['unrestricted'] ?? false)) {
            $modules = array_values($validated['modules'] ?? []);
        }

        try {
            $user = $this->hubUserAdmin->updateModules($request, $userId, $modules);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }

        return response()->json([
            'message' => 'Modules updated.',
            'user' => $user,
        ]);
    }

    public function changeEmail(Request $request, int $userId): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        try {
            $user = $this->hubUserAdmin->changeEmail($request, $userId, $validated['email']);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }

        return response()->json([
            'message' => 'Registered email updated. Verification email sent.',
            'user' => $user,
        ]);
    }

    public function toggleOtp(Request $request, int $userId): JsonResponse
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        try {
            $user = $this->hubUserAdmin->toggleOtp($request, $userId, (bool) $validated['enabled']);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }

        return response()->json([
            'message' => $validated['enabled'] ? 'Login OTP enabled.' : 'Login OTP disabled.',
            'user' => $user,
        ]);
    }

    public function toggleAuthenticator(Request $request, int $userId): JsonResponse
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        try {
            $user = $this->hubUserAdmin->toggleAuthenticator($request, $userId, (bool) $validated['enabled']);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }

        $message = $validated['enabled']
            ? 'MS Authenticator requested — user must complete setup on Update profile.'
            : 'MS Authenticator disabled.';

        return response()->json([
            'message' => $message,
            'user' => $user,
        ]);
    }

    public function updateTraining(Request $request, int $userId): JsonResponse
    {
        $validated = $request->validate([
            'training_attended' => ['required', 'boolean'],
        ]);

        try {
            $user = $this->hubUserAdmin->updateTraining($request, $userId, (bool) $validated['training_attended']);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }

        return response()->json([
            'message' => 'Training status updated.',
            'user' => $user,
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
     * @return list<array{key: string, label: string}>
     */
    private function moduleOptions(?Hub $hub): array
    {
        if (! $hub) {
            return [];
        }

        $options = [];
        foreach ($hub->enabledModuleKeys() as $key) {
            $options[] = [
                'key' => $key,
                'label' => $hub->moduleLabel($key),
            ];
        }

        return $options;
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
