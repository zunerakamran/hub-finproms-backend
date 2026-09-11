<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AdminNewUserRegistrationMailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

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
        User::ROLE_USER,
    ];

    public function __construct(
        private readonly AdminNewUserRegistrationMailService $adminNewUserMail,
        private readonly FunctionalMailService $functionalMail
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:255'],
            'role' => ['sometimes', 'nullable', 'string', Rule::in(self::ASSIGNABLE_ROLES)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = User::query()->orderBy('name')->orderBy('id');

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

        $perPage = (int) ($validated['per_page'] ?? 50);
        $paginator = $query->paginate($perPage);

        return response()->json([
            'users' => $paginator->getCollection()->map(fn (User $user) => $this->serialize($user))->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'roles' => $this->roleOptions(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatedPayload($request, null);

        $user = User::query()->create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'role' => $validated['role'],
            'credits' => $validated['credits'] ?? 0,
            'is_advisor' => $validated['is_advisor'] ?? ($validated['role'] === User::ROLE_ADVISOR),
            'has_unlimited_credits' => $validated['has_unlimited_credits'] ?? false,
            'is_suspended' => $validated['is_suspended'] ?? false,
        ]);

        $this->adminNewUserMail->send($user);
        $this->functionalMail->accountCreatedByAdmin($user);

        return response()->json([
            'message' => 'User created successfully.',
            'user' => $this->serialize($user->fresh()),
            'roles' => $this->roleOptions(),
        ], 201);
    }

    public function show(User $user): JsonResponse
    {
        return response()->json([
            'user' => $this->serialize($user),
            'roles' => $this->roleOptions(),
        ]);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $validated = $this->validatedPayload($request, $user);

        if (array_key_exists('role', $validated) && $validated['role'] !== $user->role) {
            $this->assertCanChangeRole($request, $user, $validated['role']);
        }

        $wasSuspended = (bool) $user->is_suspended;
        $payload = collect($validated)->except(['password'])->all();

        if (! empty($validated['password'])) {
            $payload['password'] = $validated['password'];
        }

        // Keep Excel-style advisor flags coherent when role is set to advisor.
        if (($payload['role'] ?? null) === User::ROLE_ADVISOR && ! array_key_exists('is_advisor', $payload)) {
            $payload['is_advisor'] = true;
        }

        $user->fill($payload);
        $user->save();

        if (array_key_exists('password', $payload)) {
            $user->tokens()->delete();
            $this->functionalMail->passwordChangedByAdmin($user);
        }

        if (array_key_exists('is_suspended', $payload)
            && (bool) $payload['is_suspended'] === true
            && ! $wasSuspended) {
            $this->functionalMail->accountSuspendedByAdmin($user);
        }

        return response()->json([
            'message' => 'User updated successfully.',
            'user' => $this->serialize($user->fresh()),
            'roles' => $this->roleOptions(),
        ]);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        if ($request->user()->id === $user->id) {
            return response()->json([
                'message' => 'You cannot delete your own account.',
            ], 422);
        }

        if ($user->role === User::ROLE_POWER_ADMIN && $this->powerAdminCount() <= 1) {
            return response()->json([
                'message' => 'Cannot delete the last Power Admin account.',
            ], 422);
        }

        $user->tokens()->delete();
        $user->delete();

        return response()->json([
            'message' => 'User deleted successfully.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedPayload(Request $request, ?User $user): array
    {
        $emailUnique = Rule::unique('users', 'email');
        if ($user) {
            $emailUnique = $emailUnique->ignore($user->id);
        }

        $passwordRules = $user
            ? ['sometimes', 'nullable', 'string', Password::defaults()]
            : ['required', 'string', Password::defaults()];

        return $request->validate([
            'name' => [$user ? 'sometimes' : 'required', 'string', 'max:255'],
            'email' => [$user ? 'sometimes' : 'required', 'string', 'email', 'max:255', $emailUnique],
            'password' => $passwordRules,
            'role' => [$user ? 'sometimes' : 'required', 'string', Rule::in(self::ASSIGNABLE_ROLES)],
            'credits' => ['sometimes', 'integer', 'min:0'],
            'is_advisor' => ['sometimes', 'boolean'],
            'has_unlimited_credits' => ['sometimes', 'boolean'],
            'is_suspended' => ['sometimes', 'boolean'],
        ]);
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
            throw \Illuminate\Validation\ValidationException::withMessages([
                'role' => 'Cannot demote the last Power Admin account.',
            ]);
        }

        if ($request->user()->id === $user->id) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'role' => 'You cannot demote your own Power Admin role.',
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
    private function roleOptions(): array
    {
        $roles = [];
        foreach (self::ASSIGNABLE_ROLES as $role) {
            $roles[] = [
                'key' => $role,
                'label' => User::ROLE_LABELS[$role] ?? $role,
            ];
        }

        return $roles;
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'role_label' => $user->role_label,
            'credits' => (int) $user->credits,
            'is_advisor' => (bool) $user->is_advisor,
            'has_unlimited_credits' => (bool) $user->has_unlimited_credits,
            'is_suspended' => (bool) $user->is_suspended,
            'is_discontinued' => (bool) $user->is_discontinued,
            'created_at' => $user->created_at?->toIso8601String(),
            'updated_at' => $user->updated_at?->toIso8601String(),
        ];
    }
}
