<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Firm;
use App\Models\Hub;
use App\Models\User;
use App\Services\ActingHubService;
use App\Services\AdminNewUserRegistrationMailService;
use App\Services\FirmHeadRoleSyncService;
use App\Services\FunctionalMailService;
use App\Services\HubRolesService;
use App\Services\UserRolesService;
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
     * Roles Power Admin may assign from the dashboard on the shared hub.
     *
     * @var list<string>
     */
    public const ASSIGNABLE_ROLES = [
        User::ROLE_POWER_ADMIN,
        User::ROLE_FINPROMS_ADMIN,
        User::ROLE_CLIENT_ADMIN,
        User::ROLE_NETWORK_ADMIN,
        User::ROLE_FIRM_ADMIN,
        User::ROLE_MANAGER,
        User::ROLE_APPROVER,
        User::ROLE_ADVISOR,
        User::ROLE_ADMIN_STAFF,
        User::ROLE_USER,
    ];

    /**
     * Roles assignable on white-labelled hubs (control-plane roles excluded).
     *
     * @var list<string>
     */
    public const WHITE_LABEL_ASSIGNABLE_ROLES = [
        User::ROLE_CLIENT_ADMIN,
        User::ROLE_NETWORK_ADMIN,
        User::ROLE_FIRM_ADMIN,
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
        private readonly WhiteLabelFirmService $whiteLabelFirms,
        private readonly HubRolesService $hubRoles,
        private readonly UserRolesService $userRoles,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:255'],
            'role' => ['sometimes', 'nullable', 'string', Rule::in($this->assignableRoles($this->actingWhiteLabelHub($request) ?? $this->labelHub()))],
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
            $role = (string) $validated['role'];
            $query->where(function ($q) use ($role) {
                $q->where('role', $role)
                    ->orWhereHas('roleAssignments', fn ($r) => $r->where('role', $role));
            });
        }

        $paginator = $query->with(['firm:id,name', 'roleAssignments'])->paginate($perPage);
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

        $roleSet = $this->userRoles->rolesFromPayload($validated)
            ?? ['roles' => [User::ROLE_USER]];
        $legacyRole = $this->userRoles->canonicalLegacyRole($roleSet['roles']);

        if ($hub = $this->actingWhiteLabelHub($request)) {
            $this->assertAssignableRoleSet($roleSet['roles'], $hub);
            $validated['roles'] = $roleSet['roles'];
            $validated['role'] = $legacyRole;
            $validated = $this->normalizeFirmForRole($validated, $legacyRole);
            $validated = $this->normalizeAdminStaffPermission($validated, null, $roleSet['roles']);

            try {
                $user = $this->whiteLabelUsers->create(
                    $hub,
                    $validated,
                    actor: $request->user(),
                    request: $request,
                );
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
                'roles' => $this->roleOptions($hub),
                'firms' => $this->firmOptions($hub),
                'acting_on_white_label' => true,
                'target_hub' => $this->targetHubPayload($hub),
            ], 201);
        }

        $this->assertAssignableRoleSet($roleSet['roles'], $this->labelHub());
        $validated['roles'] = $roleSet['roles'];
        $validated['role'] = $legacyRole;
        $validated = $this->normalizeFirmForRole($validated, $legacyRole);
        $validated = $this->normalizeAdminStaffPermission($validated, null, $roleSet['roles']);

        $user = User::query()->create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'role' => $legacyRole,
            'credits' => $validated['credits'] ?? 0,
            'is_advisor' => $validated['is_advisor'] ?? in_array(User::ROLE_ADVISOR, $roleSet['roles'], true),
            'allows_admin_staff_acting' => (bool) ($validated['allows_admin_staff_acting'] ?? false),
            'has_unlimited_credits' => $validated['has_unlimited_credits'] ?? false,
            'is_suspended' => $validated['is_suspended'] ?? false,
            'firm_id' => $validated['firm_id'] ?? null,
            'email_verified_at' => now(),
        ]);

        // Boot may have inserted the legacy role silently — reset so every role is logged as assigned.
        $this->userRoles->resetPivotForLoggedSync($user);
        $this->userRoles->syncRoles(
            $user,
            $roleSet['roles'],
            log: true,
            actor: $request->user(),
            request: $request,
            hub: $this->labelHub(),
        );

        $this->adminNewUserMail->send($user);
        $this->functionalMail->accountCreatedByAdmin($user);

        return response()->json([
            'message' => 'User created successfully.',
            'user' => $this->serialize($user->fresh(['firm', 'roleAssignments'])),
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
                'roles' => $this->roleOptions($hub),
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
            if (! empty($existing['roles']) && is_array($existing['roles'])) {
                $current->setRelation(
                    'roleAssignments',
                    collect($existing['roles'])->map(fn (string $role) => new \App\Models\UserRole([
                        'user_id' => $current->id,
                        'role' => $role,
                    ]))
                );
            }

            $validated = $this->validatedPayload($request, $current, skipUnique: true);
            $roleSet = $this->userRoles->rolesFromPayload($validated, $current);
            $legacyForNormalize = isset($roleSet['roles'])
                ? $this->userRoles->canonicalLegacyRole($roleSet['roles'])
                : ($existing['role'] ?? null);
            $validated = $this->normalizeFirmForRole($validated, $legacyForNormalize);
            $validated = $this->normalizeAdminStaffPermission($validated, $current, $roleSet['roles'] ?? null);

            if ($roleSet !== null) {
                $this->assertAssignableRoleSet($roleSet['roles'], $hub);
                $this->assertCanChangeRoleOnHub($request, $hub, $existing, $roleSet['roles']);
                $validated['roles'] = $roleSet['roles'];
                $validated['role'] = $this->userRoles->canonicalLegacyRole($roleSet['roles']);
            }

            try {
                $updated = $this->whiteLabelUsers->update(
                    $hub,
                    $user,
                    $validated,
                    actor: $request->user(),
                    request: $request,
                );
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
                'roles' => $this->roleOptions($hub),
                'firms' => $this->firmOptions($hub),
                'acting_on_white_label' => true,
                'target_hub' => $this->targetHubPayload($hub),
            ]);
        }

        $model = User::query()->with('roleAssignments')->findOrFail($user);
        $validated = $this->validatedPayload($request, $model);
        $roleSet = $this->userRoles->rolesFromPayload($validated, $model);
        $legacyForNormalize = isset($roleSet['roles'])
            ? $this->userRoles->canonicalLegacyRole($roleSet['roles'])
            : $model->role;
        $validated = $this->normalizeFirmForRole($validated, $legacyForNormalize);
        $validated = $this->normalizeAdminStaffPermission($validated, $model, $roleSet['roles'] ?? null);

        if ($roleSet !== null) {
            $this->assertAssignableRoleSet($roleSet['roles'], $this->labelHub());
            $this->assertCanChangeRole($request, $model, $roleSet['roles']);
        }

        $wasSuspended = (bool) $model->is_suspended;
        $payload = collect($validated)->except(['password', 'roles', 'primary_role'])->all();

        if (! empty($validated['password'])) {
            $payload['password'] = $validated['password'];
        }

        if ($roleSet !== null) {
            $payload['role'] = $this->userRoles->canonicalLegacyRole($roleSet['roles']);
            if (in_array(User::ROLE_ADVISOR, $roleSet['roles'], true) && ! array_key_exists('is_advisor', $payload)) {
                $payload['is_advisor'] = true;
            }
            if (! in_array(User::ROLE_ADMIN_STAFF, $roleSet['roles'], true)) {
                $payload['acting_advisor_id'] = null;
            }
        }

        if (array_key_exists('firm_id', $payload)) {
            $newFirmId = $payload['firm_id'] !== null ? (int) $payload['firm_id'] : null;
            if ($newFirmId !== ($model->firm_id ? (int) $model->firm_id : null)) {
                // If head roles are being revoked in this same request, allow the firm move.
                $rolesAfter = $roleSet['roles'] ?? $model->roleKeys();
                $keepingHead = in_array(User::ROLE_NETWORK_ADMIN, $rolesAfter, true)
                    || in_array(User::ROLE_FIRM_ADMIN, $rolesAfter, true);
                if ($keepingHead) {
                    app(FirmHeadRoleSyncService::class)->assertFirmMembershipAllowed($model, $newFirmId);
                }
            }
        }

        $model->fill($payload);
        $model->save();

        if ($roleSet !== null) {
            $this->userRoles->syncRoles(
                $model,
                $roleSet['roles'],
                log: true,
                actor: $request->user(),
                request: $request,
                hub: $this->labelHub(),
            );
        }

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
            'user' => $this->serialize($model->fresh(['firm', 'roleAssignments'])),
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

        if ($model->hasRole(User::ROLE_POWER_ADMIN) && $this->powerAdminCount() <= 1) {
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

        $roleHub = $this->actingWhiteLabelHub($request) ?? $this->labelHub();

        $assignable = $this->assignableRoles($roleHub);

        return $request->validate([
            'name' => [$user ? 'sometimes' : 'required', 'string', 'max:255'],
            'email' => $emailRules,
            'password' => $passwordRules,
            // Legacy single role OR multi-role: roles[] (all roles equal — no primary).
            'role' => [
                $user ? 'sometimes' : 'required_without:roles',
                'nullable',
                'string',
                function (string $attribute, mixed $value, \Closure $fail) use ($assignable): void {
                    if ($value === null || $value === '') {
                        return;
                    }
                    foreach ($this->userRoles->splitRoleString((string) $value) as $role) {
                        $role = $this->userRoles->normalizeRole($role);
                        if (! in_array($role, $assignable, true)) {
                            $fail('The selected role is invalid.');
                        }
                    }
                },
            ],
            'roles' => [$user ? 'sometimes' : 'required_without:role', 'nullable', 'array', 'min:1'],
            'roles.*' => ['string', Rule::in($assignable)],
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
    /**
     * @param  list<string>|null  $roles
     */
    private function normalizeAdminStaffPermission(array $validated, ?User $existing = null, ?array $roles = null): array
    {
        $roles ??= isset($validated['roles']) && is_array($validated['roles'])
            ? $validated['roles']
            : null;
        $primary = $validated['role'] ?? $existing?->role;
        $isAdvisor = ($roles !== null && in_array(User::ROLE_ADVISOR, $roles, true))
            || $primary === User::ROLE_ADVISOR
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
        if ($hub && $hub->isContentHub()) {
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
        $roles = isset($validated['roles']) && is_array($validated['roles'])
            ? $validated['roles']
            : [];
        $role = $validated['role'] ?? $existingRole;
        $isControlPlane = in_array($role, [User::ROLE_POWER_ADMIN, User::ROLE_FINPROMS_ADMIN], true)
            || in_array(User::ROLE_POWER_ADMIN, $roles, true)
            || in_array(User::ROLE_FINPROMS_ADMIN, $roles, true);
        if ($isControlPlane) {
            $validated['firm_id'] = null;
        }

        return $validated;
    }

    /**
     * @param  list<string>  $newRoles
     */
    private function assertCanChangeRole(Request $request, User $user, array $newRoles): void
    {
        if (! $user->hasRole(User::ROLE_POWER_ADMIN)) {
            return;
        }

        if (in_array(User::ROLE_POWER_ADMIN, $newRoles, true)) {
            return;
        }

        if ($this->powerAdminCount() <= 1) {
            throw ValidationException::withMessages([
                'roles' => 'Cannot demote the last Power Admin account.',
            ]);
        }

        if ($request->user()->id === $user->id) {
            throw ValidationException::withMessages([
                'roles' => 'You cannot demote your own Power Admin role.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $existing
     * @param  list<string>  $newRoles
     */
    private function assertCanChangeRoleOnHub(Request $request, Hub $hub, array $existing, array $newRoles): void
    {
        $heldPowerAdmin = ($existing['role'] ?? null) === User::ROLE_POWER_ADMIN
            || (is_array($existing['roles'] ?? null) && in_array(User::ROLE_POWER_ADMIN, $existing['roles'], true));

        if (! $heldPowerAdmin) {
            return;
        }

        if (in_array(User::ROLE_POWER_ADMIN, $newRoles, true)) {
            return;
        }

        if ($this->whiteLabelUsers->powerAdminCount($hub) <= 1) {
            throw ValidationException::withMessages([
                'roles' => 'Cannot demote the last Power Admin account on this hub.',
            ]);
        }
    }

    /**
     * @param  list<string>  $roles
     */
    private function assertAssignableRoleSet(array $roles, ?Hub $hub): void
    {
        $assignable = $this->assignableRoles($hub);
        foreach ($roles as $role) {
            if (! in_array($role, $assignable, true)) {
                throw ValidationException::withMessages([
                    'roles' => 'The selected role is invalid: '.$role,
                ]);
            }
        }
    }

    private function powerAdminCount(): int
    {
        return User::query()
            ->where(function ($q) {
                $q->where('role', User::ROLE_POWER_ADMIN)
                    ->orWhereHas('roleAssignments', fn ($r) => $r->where('role', User::ROLE_POWER_ADMIN));
            })
            ->count();
    }

    /**
     * @return list<string>
     */
    private function assignableRoles(?Hub $hub = null): array
    {
        return $this->hubRoles->assignableRoles($hub);
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    private function roleOptions(?Hub $hub = null): array
    {
        $hub ??= $this->labelHub();
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
        if (! empty($payload['roles']) && is_array($payload['roles'])) {
            $user->setRelation(
                'roleAssignments',
                collect($payload['roles'])->map(fn (string $role) => new \App\Models\UserRole([
                    'user_id' => $user->id,
                    'role' => $role,
                ]))
            );
        }
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
        if (! $user || ! $this->actingHubs->isActingRemotely($user)) {
            return null;
        }

        try {
            return $this->actingHubs->requireActingContentHub($user);
        } catch (InvalidArgumentException $e) {
            throw new HttpException(422, $e->getMessage());
        }
    }
}
