<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Firm;
use App\Models\Hub;
use App\Models\User;
use App\Services\ActingHubService;
use App\Services\AdminNewUserRegistrationMailService;
use App\Services\FunctionalMailService;
use App\Services\WhiteLabelFirmService;
use App\Services\WhiteLabelUserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PowerAdminUserController extends Controller
{
    /**
     * Roles Power Admin may assign from the dashboard.
     *
     * @var list<string>
     */
    public const ASSIGNABLE_ROLES = [
        User::ROLE_POWER_ADMIN,
        User::ROLE_FINPROMS_ADMIN,
        User::ROLE_CLIENT_ADMIN,
        User::ROLE_MANAGER,
        User::ROLE_APPROVER,
        User::ROLE_ADVISOR,
        User::ROLE_ADMIN_STAFF,
        User::ROLE_USER,
    ];

    public function __construct(
        private readonly AdminNewUserRegistrationMailService $adminNewUserMail,
        private readonly FunctionalMailService $functionalMail,
        private readonly ActingHubService $actingHubs,
        private readonly WhiteLabelUserService $whiteLabelUsers,
        private readonly WhiteLabelFirmService $whiteLabelFirms
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:255'],
            'role' => ['sometimes', 'nullable', 'string', Rule::in(self::ASSIGNABLE_ROLES)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 50);

        if ($hub = $this->actingWhiteLabelHub($request)) {
            try {
                $listed = $this->whiteLabelUsers->paginate(
                    $hub,
                    $validated['q'] ?? null,
                    $validated['role'] ?? null,
                    $perPage,
                    max(1, (int) $request->integer('page', 1))
                );
            } catch (InvalidArgumentException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            return response()->json([
                'users' => $listed['users'],
                'meta' => $listed['meta'],
                'roles' => $this->roleOptions($hub),
                'firms' => $this->firmOptions($hub),
                'acting_on_white_label' => true,
                'target_hub' => $this->targetHubPayload($hub),
            ]);
        }

        $query = User::query()->with('firm:id,name')->orderBy('name')->orderBy('id');

        if (! empty($validated['q'])) {
            $term = '%'.$validated['q'].'%';
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', $term)
                    ->orWhere('email', 'like', $term);
            });
        }

        if (! empty($validated['role'])) {
            $query->where('role', $validated['role']);
        }

        $paginator = $query->paginate($perPage);
        $labelHub = $this->actingHubs->targetHub($request->user());

        return response()->json([
            'users' => $paginator->getCollection()->map(fn (User $user) => $this->serialize($user, $labelHub))->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'roles' => $this->roleOptions($labelHub),
            'firms' => $this->firmOptions(),
            'acting_on_white_label' => false,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $hub = $this->actingWhiteLabelHub($request);
        $validated = $this->validatedPayload($request, null, skipUnique: $hub !== null);
        $validated = $this->normalizeFirmForRole($validated);
        $validated = $this->normalizeAdminStaffPermission($validated);

        if ($hub = $this->actingWhiteLabelHub($request)) {
            try {
                $user = $this->whiteLabelUsers->create($hub, $validated);
            } catch (InvalidArgumentException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            } catch (ValidationException $e) {
                throw $e;
            }

            $mailUser = $this->mailableUser($user);
            $this->adminNewUserMail->send($mailUser, $hub);
            $this->functionalMail->accountCreatedByAdmin($mailUser, $hub);

            return response()->json([
                'message' => 'User created on '.$hub->name.' (white-labelled database).',
                'user' => $user,
                'roles' => $this->roleOptions(),
                'firms' => $this->firmOptions($hub),
                'acting_on_white_label' => true,
                'target_hub' => $this->targetHubPayload($hub),
            ], 201);
        }

        $user = User::query()->create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'role' => $validated['role'],
            'credits' => $validated['credits'] ?? 0,
            'is_advisor' => $validated['is_advisor'] ?? ($validated['role'] === User::ROLE_ADVISOR),
            'allows_admin_staff_acting' => (bool) ($validated['allows_admin_staff_acting'] ?? false),
            'has_unlimited_credits' => $validated['has_unlimited_credits'] ?? false,
            'is_suspended' => $validated['is_suspended'] ?? false,
            'firm_id' => $validated['firm_id'] ?? null,
        ]);

        $this->adminNewUserMail->send($user);
        $this->functionalMail->accountCreatedByAdmin($user);

        return response()->json([
            'message' => 'User created successfully.',
            'user' => $this->serialize($user->fresh('firm')),
            'roles' => $this->roleOptions(),
            'firms' => $this->firmOptions(),
            'acting_on_white_label' => false,
        ], 201);
    }

    public function show(Request $request, int $user): JsonResponse
    {
        if ($hub = $this->actingWhiteLabelHub($request)) {
            try {
                $payload = $this->whiteLabelUsers->find($hub, $user);
            } catch (InvalidArgumentException $e) {
                return response()->json(['message' => $e->getMessage()], 404);
            }

            return response()->json([
                'user' => $payload,
                'roles' => $this->roleOptions(),
                'firms' => $this->firmOptions($hub),
                'acting_on_white_label' => true,
                'target_hub' => $this->targetHubPayload($hub),
            ]);
        }

        $model = User::query()->with('firm:id,name')->findOrFail($user);

        return response()->json([
            'user' => $this->serialize($model),
            'roles' => $this->roleOptions(),
            'firms' => $this->firmOptions(),
            'acting_on_white_label' => false,
        ]);
    }

    public function update(Request $request, int $user): JsonResponse
    {
        if ($hub = $this->actingWhiteLabelHub($request)) {
            try {
                $existing = $this->whiteLabelUsers->find($hub, $user);
            } catch (InvalidArgumentException $e) {
                return response()->json(['message' => $e->getMessage()], 404);
            }

            $current = new User($existing);
            $current->id = $existing['id'];
            $current->exists = true;
            $current->role = $existing['role'];
            $current->is_suspended = $existing['is_suspended'];

            $validated = $this->validatedPayload($request, $current, skipUnique: true);
            $validated = $this->normalizeFirmForRole($validated, $existing['role'] ?? null);
            $validated = $this->normalizeAdminStaffPermission($validated, $current);

            if (array_key_exists('role', $validated) && $validated['role'] !== $existing['role']) {
                $this->assertCanChangeRoleOnHub($request, $hub, $existing, $validated['role']);
            }

            try {
                $updated = $this->whiteLabelUsers->update($hub, $user, $validated);
            } catch (InvalidArgumentException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            $mailUser = $this->mailableUser($updated['user']);
            if ($updated['password_changed']) {
                $this->functionalMail->passwordChangedByAdmin($mailUser, $hub);
            }
            if ($updated['newly_suspended']) {
                $this->functionalMail->accountSuspendedByAdmin($mailUser, $hub);
            }

            return response()->json([
                'message' => 'User updated on '.$hub->name.'.',
                'user' => $updated['user'],
                'roles' => $this->roleOptions(),
                'firms' => $this->firmOptions($hub),
                'acting_on_white_label' => true,
                'target_hub' => $this->targetHubPayload($hub),
            ]);
        }

        $model = User::query()->findOrFail($user);
        $validated = $this->validatedPayload($request, $model);
        $validated = $this->normalizeFirmForRole($validated, $model->role);
        $validated = $this->normalizeAdminStaffPermission($validated, $model);

        if (array_key_exists('role', $validated) && $validated['role'] !== $model->role) {
            $this->assertCanChangeRole($request, $model, $validated['role']);
        }

        $wasSuspended = (bool) $model->is_suspended;
        $payload = collect($validated)->except(['password'])->all();

        if (! empty($validated['password'])) {
            $payload['password'] = $validated['password'];
        }

        // Keep Excel-style advisor flags coherent when role is set to advisor.
        if (($payload['role'] ?? null) === User::ROLE_ADVISOR && ! array_key_exists('is_advisor', $payload)) {
            $payload['is_advisor'] = true;
        }

        if (array_key_exists('role', $payload) && $payload['role'] !== User::ROLE_ADMIN_STAFF) {
            $payload['acting_advisor_id'] = null;
        }

        $model->fill($payload);
        $model->save();

        if (array_key_exists('password', $payload)) {
            $model->tokens()->delete();
            $this->functionalMail->passwordChangedByAdmin($model);
        }

        if (array_key_exists('is_suspended', $payload)
            && (bool) $payload['is_suspended'] === true
            && ! $wasSuspended) {
            $this->functionalMail->accountSuspendedByAdmin($model);
        }

        return response()->json([
            'message' => 'User updated successfully.',
            'user' => $this->serialize($model->fresh('firm')),
            'roles' => $this->roleOptions(),
            'firms' => $this->firmOptions(),
            'acting_on_white_label' => false,
        ]);
    }

    public function destroy(Request $request, int $user): JsonResponse
    {
        if ($hub = $this->actingWhiteLabelHub($request)) {
            try {
                $this->whiteLabelUsers->delete($hub, $user);
            } catch (InvalidArgumentException $e) {
                $notFound = str_contains(strtolower($e->getMessage()), 'not found');

                return response()->json(['message' => $e->getMessage()], $notFound ? 404 : 422);
            }

            return response()->json([
                'message' => 'User deleted on '.$hub->name.'.',
                'acting_on_white_label' => true,
                'target_hub' => $this->targetHubPayload($hub),
            ]);
        }

        $model = User::query()->findOrFail($user);

        if ($request->user()->id === $model->id) {
            return response()->json([
                'message' => 'You cannot delete your own account.',
            ], 422);
        }

        if ($model->role === User::ROLE_POWER_ADMIN && $this->powerAdminCount() <= 1) {
            return response()->json([
                'message' => 'Cannot delete the last Power Admin account.',
            ], 422);
        }

        $model->tokens()->delete();
        $model->delete();

        return response()->json([
            'message' => 'User deleted successfully.',
            'acting_on_white_label' => false,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedPayload(Request $request, ?User $user, bool $skipUnique = false): array
    {
        $emailRules = [$user ? 'sometimes' : 'required', 'string', 'email', 'max:255'];
        if (! $skipUnique) {
            $emailUnique = Rule::unique('users', 'email');
            if ($user) {
                $emailUnique = $emailUnique->ignore($user->id);
            }
            $emailRules[] = $emailUnique;
        }

        $passwordRules = $user
            ? ['sometimes', 'nullable', 'string', Password::defaults()]
            : ['required', 'string', Password::defaults()];

        $firmRules = [$user ? 'sometimes' : 'nullable', 'nullable', 'integer'];
        if (! $skipUnique) {
            $firmRules[] = Rule::exists('firms', 'id');
        }

        return $request->validate([
            'name' => [$user ? 'sometimes' : 'required', 'string', 'max:255'],
            'email' => $emailRules,
            'password' => $passwordRules,
            'role' => [$user ? 'sometimes' : 'required', 'string', Rule::in(self::ASSIGNABLE_ROLES)],
            'credits' => ['sometimes', 'integer', 'min:0'],
            'is_advisor' => ['sometimes', 'boolean'],
            'allows_admin_staff_acting' => ['sometimes', 'boolean'],
            'has_unlimited_credits' => ['sometimes', 'boolean'],
            'is_suspended' => ['sometimes', 'boolean'],
            'firm_id' => $firmRules,
        ]);
    }

    /**
     * Permission flag only applies to advisors; clear it for everyone else.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function normalizeAdminStaffPermission(array $validated, ?User $existing = null): array
    {
        $role = $validated['role'] ?? $existing?->role;
        $isAdvisor = ($role === User::ROLE_ADVISOR)
            || (bool) ($validated['is_advisor'] ?? $existing?->is_advisor ?? false);

        if (! $isAdvisor) {
            $validated['allows_admin_staff_acting'] = false;

            return $validated;
        }

        if (array_key_exists('allows_admin_staff_acting', $validated)) {
            $validated['allows_admin_staff_acting'] = (bool) $validated['allows_admin_staff_acting'];
        } elseif ($existing === null) {
            $validated['allows_admin_staff_acting'] = false;
        }

        return $validated;
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function firmOptions(?Hub $hub = null): array
    {
        if ($hub && $hub->isWhiteLabel()) {
            try {
                return $this->whiteLabelFirms->options($hub);
            } catch (InvalidArgumentException) {
                return [];
            }
        }

        return Firm::query()
            ->orderByDesc('is_central')
            ->orderBy('name')
            ->get(['id', 'name', 'is_central'])
            ->map(fn (Firm $firm) => [
                'id' => $firm->id,
                'name' => $firm->name,
                'is_central' => $firm->isCentral(),
            ])
            ->values()
            ->all();
    }

    /**
     * Power Admin and FinProms Admin are outside the firm model.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function normalizeFirmForRole(array $validated, ?string $existingRole = null): array
    {
        $role = $validated['role'] ?? $existingRole;
        if (in_array($role, [User::ROLE_POWER_ADMIN, User::ROLE_FINPROMS_ADMIN], true)) {
            $validated['firm_id'] = null;
        }

        return $validated;
    }

    private function assertCanChangeRole(Request $request, User $user, string $newRole): void
    {
        if ($user->role !== User::ROLE_POWER_ADMIN) {
            return;
        }

        if ($newRole === User::ROLE_POWER_ADMIN) {
            return;
        }

        if ($this->powerAdminCount() <= 1) {
            throw ValidationException::withMessages([
                'role' => 'Cannot demote the last Power Admin account.',
            ]);
        }

        if ($request->user()->id === $user->id) {
            throw ValidationException::withMessages([
                'role' => 'You cannot demote your own Power Admin role.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $existing
     */
    private function assertCanChangeRoleOnHub(Request $request, Hub $hub, array $existing, string $newRole): void
    {
        if ($existing['role'] !== User::ROLE_POWER_ADMIN) {
            return;
        }

        if ($newRole === User::ROLE_POWER_ADMIN) {
            return;
        }

        if ($this->whiteLabelUsers->powerAdminCount($hub) <= 1) {
            throw ValidationException::withMessages([
                'role' => 'Cannot demote the last Power Admin account on this hub.',
            ]);
        }
    }

    private function powerAdminCount(): int
    {
        return User::query()->where('role', User::ROLE_POWER_ADMIN)->count();
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    private function roleOptions(?Hub $hub = null): array
    {
        $hub ??= $this->labelHub();
        $roles = [];
        foreach (self::ASSIGNABLE_ROLES as $role) {
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
     * @return array<string, mixed>
     */
    private function serialize(User $user, ?Hub $hub = null): array
    {
        return $this->whiteLabelUsers->serialize($user, $hub ?? $this->labelHub());
    }

    private function labelHub(): ?Hub
    {
        try {
            $user = auth()->user();
            if (! $user) {
                return null;
            }

            return $this->actingHubs->targetHub($user);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function mailableUser(array $payload): User
    {
        $user = new User;
        $user->id = $payload['id'] ?? 0;
        $user->name = $payload['name'] ?? '';
        $user->email = $payload['email'] ?? '';
        $user->role = $payload['role'] ?? User::ROLE_USER;
        $user->exists = true;

        return $user;
    }

    /**
     * @return array{id: int, name: string, slug: string}
     */
    private function targetHubPayload(Hub $hub): array
    {
        return ['id' => $hub->id, 'name' => $hub->name, 'slug' => $hub->slug];
    }

    private function actingWhiteLabelHub(Request $request): ?Hub
    {
        $user = $request->user();
        if (! $user || ! $this->actingHubs->isActingOnWhiteLabel($user)) {
            return null;
        }

        try {
            return $this->actingHubs->requireActingWhiteLabel($user);
        } catch (InvalidArgumentException $e) {
            throw new HttpException(422, $e->getMessage());
        }
    }
}
