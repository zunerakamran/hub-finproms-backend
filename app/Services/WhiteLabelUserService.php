<?php

namespace App\Services;

use App\Models\Hub;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Read/write users on a white-labelled hub's own database while the shared
 * dashboard hub switcher is acting on that hub.
 */
class WhiteLabelUserService
{
    public function __construct(
        private readonly WhiteLabelDatabaseService $remoteDb,
        private readonly UserRolesService $userRoles,
    ) {}

    public function assertTarget(Hub $hub): void
    {
        if ($hub->isControlPlane() || ! $hub->isContentHub()) {
            throw new InvalidArgumentException('Select a Shared or White-labelled hub, not Central Hub.');
        }
        if (! $hub->is_active) {
            throw new InvalidArgumentException('That hub is inactive.');
        }
        $this->remoteDb->assertConfigured($hub);
    }

    /**
     * @return array{users: list<array<string, mixed>>, meta: array<string, int>}
     */
    public function paginate(Hub $hub, ?string $search, ?string $role, int $perPage, int $page = 1): array
    {
        $this->assertTarget($hub);

        return $this->remoteDb->run($hub, function (string $connection) use ($hub, $search, $role, $perPage, $page) {
            $query = User::on($connection)->with(['firm:id,name', 'roleAssignments'])->orderBy('name')->orderBy('id');

            if ($search) {
                $term = '%'.$search.'%';
                $query->where(function ($q) use ($term) {
                    $q->where('name', 'like', $term)->orWhere('email', 'like', $term);
                });
            }

            if ($role) {
                $query->where(function ($q) use ($role) {
                    $q->where('role', $role)
                        ->orWhereHas('roleAssignments', fn ($r) => $r->where('role', $role));
                });
            }

            $paginator = $query->paginate($perPage, ['*'], 'page', $page);

            return [
                'users' => $paginator->getCollection()
                    ->map(fn (User $user) => $this->serialize($user, $hub))
                    ->values()
                    ->all(),
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                ],
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function create(Hub $hub, array $payload, ?User $actor = null, ?\Illuminate\Http\Request $request = null): array
    {
        $this->assertTarget($hub);

        return $this->remoteDb->run($hub, function (string $connection) use ($hub, $payload, $actor, $request) {
            $this->assertEmailAvailable($connection, (string) $payload['email']);

            $roleSet = $this->userRoles->rolesFromPayload($payload)
                ?? ['roles' => [(string) ($payload['role'] ?? User::ROLE_USER)]];
            $legacyRole = $this->userRoles->canonicalLegacyRole($roleSet['roles']);

            $user = User::on($connection)->create([
                'name' => $payload['name'],
                'email' => $payload['email'],
                'password' => $payload['password'],
                'role' => $legacyRole,
                'credits' => $payload['credits'] ?? 0,
                'is_advisor' => $payload['is_advisor'] ?? in_array(User::ROLE_ADVISOR, $roleSet['roles'], true),
                'allows_admin_staff_acting' => (bool) ($payload['allows_admin_staff_acting'] ?? false),
                'has_unlimited_credits' => $payload['has_unlimited_credits'] ?? false,
                'is_suspended' => $payload['is_suspended'] ?? false,
                'firm_id' => $this->resolveFirmId($connection, $payload['firm_id'] ?? null),
                'email_verified_at' => now(),
            ]);

            $this->userRoles->resetPivotForLoggedSync($user);
            $this->userRoles->syncRoles(
                $user,
                $roleSet['roles'],
                log: true,
                actor: $actor,
                request: $request,
                hub: $hub,
            );

            return $this->serialize($user->fresh(['firm', 'roleAssignments']), $hub);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function find(Hub $hub, int $userId): array
    {
        $this->assertTarget($hub);

        return $this->remoteDb->run($hub, function (string $connection) use ($hub, $userId) {
            $user = $this->findOrFail($connection, $userId);
            $user->loadMissing('roleAssignments');

            return $this->serialize($user, $hub);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{user: array<string, mixed>, password_changed: bool, newly_suspended: bool}
     */
    public function update(
        Hub $hub,
        int $userId,
        array $payload,
        ?User $actor = null,
        ?\Illuminate\Http\Request $request = null,
    ): array {
        $this->assertTarget($hub);

        return $this->remoteDb->run($hub, function (string $connection) use ($hub, $userId, $payload, $actor, $request) {
            $user = $this->findOrFail($connection, $userId);
            $user->loadMissing('roleAssignments');

            if (array_key_exists('email', $payload)) {
                $this->assertEmailAvailable($connection, (string) $payload['email'], $user->id);
            }

            $wasSuspended = (bool) $user->is_suspended;
            $roleSet = $this->userRoles->rolesFromPayload($payload, $user);
            $fill = collect($payload)->except(['password', 'roles', 'primary_role'])->all();

            if (! empty($payload['password'])) {
                $fill['password'] = $payload['password'];
            }

            if ($roleSet !== null) {
                $fill['role'] = $this->userRoles->canonicalLegacyRole($roleSet['roles']);
                if (in_array(User::ROLE_ADVISOR, $roleSet['roles'], true) && ! array_key_exists('is_advisor', $fill)) {
                    $fill['is_advisor'] = true;
                }
                if (! in_array(User::ROLE_ADMIN_STAFF, $roleSet['roles'], true)) {
                    $fill['acting_advisor_id'] = null;
                }
            }

            $rolesForAdvisor = $roleSet['roles'] ?? $user->roleKeys();
            $isAdvisor = in_array(User::ROLE_ADVISOR, $rolesForAdvisor, true)
                || (bool) ($fill['is_advisor'] ?? $user->is_advisor);
            if (! $isAdvisor) {
                $fill['allows_admin_staff_acting'] = false;
            } elseif (array_key_exists('allows_admin_staff_acting', $fill)) {
                $fill['allows_admin_staff_acting'] = (bool) $fill['allows_admin_staff_acting'];
            }

            if (array_key_exists('firm_id', $fill)) {
                $fill['firm_id'] = $this->resolveFirmId($connection, $fill['firm_id']);
                $newFirmId = $fill['firm_id'] !== null ? (int) $fill['firm_id'] : null;
                if ($newFirmId !== ($user->firm_id ? (int) $user->firm_id : null)) {
                    $rolesAfter = $roleSet['roles'] ?? $user->roleKeys();
                    $keepingHead = in_array(User::ROLE_NETWORK_ADMIN, $rolesAfter, true)
                        || in_array(User::ROLE_FIRM_ADMIN, $rolesAfter, true);
                    if ($keepingHead) {
                        app(FirmHeadRoleSyncService::class)->assertFirmMembershipAllowed($user, $newFirmId);
                    }
                }
            }

            $user->fill($fill);
            $user->save();

            if ($roleSet !== null) {
                $this->userRoles->syncRoles(
                    $user,
                    $roleSet['roles'],
                    log: true,
                    actor: $actor,
                    request: $request,
                    hub: $hub,
                );
            }

            $passwordChanged = array_key_exists('password', $fill);
            if ($passwordChanged) {
                $this->deleteTokens($connection, $user->id);
            }

            $newlySuspended = array_key_exists('is_suspended', $fill)
                && (bool) $fill['is_suspended'] === true
                && ! $wasSuspended;

            if ($newlySuspended) {
                $this->deleteTokens($connection, $user->id);
            }

            return [
                'user' => $this->serialize($user->fresh(['firm', 'roleAssignments']), $hub),
                'password_changed' => $passwordChanged,
                'newly_suspended' => $newlySuspended,
            ];
        });
    }

    public function delete(Hub $hub, int $userId): void
    {
        $this->assertTarget($hub);

        $this->remoteDb->run($hub, function (string $connection) use ($userId): void {
            $user = $this->findOrFail($connection, $userId);

            if ($user->hasRole(User::ROLE_POWER_ADMIN) && $this->powerAdminCountOnConnection($connection) <= 1) {
                throw ValidationException::withMessages([
                    'user' => 'Cannot delete the last Power Admin account on this hub.',
                ]);
            }

            $this->deleteTokens($connection, $user->id);
            $user->delete();
        });
    }

    public function powerAdminCount(Hub $hub): int
    {
        $this->assertTarget($hub);

        return (int) $this->remoteDb->run($hub, function (string $connection) {
            return $this->powerAdminCountOnConnection($connection);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function serialize(User $user, ?Hub $hub = null): array
    {
        $hubAllowsUnlimited = $hub ? $hub->givesUnlimitedSubscriberCredits() : false;
        $unlimited = $user->hasUnlimitedCredits($hubAllowsUnlimited);
        $roles = $user->roleKeys();
        $legacyRole = (string) ($user->role ?: ($roles[0] ?? User::ROLE_USER));
        $roleLabel = $hub
            ? $hub->roleLabel($legacyRole)
            : (User::ROLE_LABELS[$legacyRole] ?? $legacyRole);
        $roleLabels = array_map(
            fn (string $role) => $hub ? $hub->roleLabel($role) : (User::ROLE_LABELS[$role] ?? $role),
            $roles
        );

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $legacyRole,
            'role_label' => $roleLabel,
            'roles' => $roles,
            'role_labels' => $roleLabels,
            'credits' => $unlimited ? 0 : (int) $user->credits,
            'is_advisor' => (bool) $user->is_advisor,
            'allows_admin_staff_acting' => (bool) $user->allows_admin_staff_acting,
            'has_unlimited_credits' => $unlimited,
            'is_suspended' => (bool) $user->is_suspended,
            'is_discontinued' => (bool) $user->is_discontinued,
            'gdpr_erased_at' => $user->gdpr_erased_at?->toIso8601String(),
            'firm_id' => $user->firm_id ? (int) $user->firm_id : null,
            'firm' => $this->serializeFirm($user),
            'modules' => $this->serializeModules($user, $hub),
            'created_at' => $user->created_at?->toIso8601String(),
            'updated_at' => $user->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array{keys: list<string>|null, labels: list<string>, unrestricted: bool}
     */
    private function serializeModules(User $user, ?Hub $hub): array
    {
        $unrestricted = $user->modules === null;
        if (! $hub) {
            $keys = is_array($user->modules) ? array_values($user->modules) : null;

            return [
                'keys' => $keys,
                'labels' => [],
                'unrestricted' => $unrestricted,
            ];
        }

        $keys = $user->resolvedModules($hub);
        $labels = array_map(fn (string $key) => $hub->moduleLabel($key), $keys);

        return [
            'keys' => $unrestricted ? null : (is_array($user->modules) ? array_values($user->modules) : []),
            'labels' => $labels,
            'unrestricted' => $unrestricted,
            'effective_keys' => $keys,
        ];
    }

    /**
     * @return array{id: int, name: string, is_central: bool, kind: string}|null
     */
    private function serializeFirm(User $user): ?array
    {
        if (! $user->firm_id) {
            return null;
        }

        $firm = $user->relationLoaded('firm') ? $user->firm : $user->firm()->first();
        if (! $firm) {
            return null;
        }

        $isCentral = (bool) ($firm->is_central ?? false);

        return [
            'id' => (int) $firm->id,
            'name' => (string) $firm->name,
            'is_central' => $isCentral,
            // Central firm = Network; otherwise member firm.
            'kind' => $isCentral ? 'network' : 'firm',
        ];
    }

    private function resolveFirmId(string $connection, mixed $firmId): ?int
    {
        if ($firmId === null || $firmId === '') {
            return null;
        }

        $id = (int) $firmId;
        $exists = DB::connection($connection)->table('firms')->where('id', $id)->exists();
        if (! $exists) {
            throw ValidationException::withMessages([
                'firm_id' => 'The selected firm is invalid on this hub.',
            ]);
        }

        return $id;
    }

    private function findOrFail(string $connection, int $userId): User
    {
        $user = User::on($connection)->find($userId);
        if (! $user) {
            throw new InvalidArgumentException('User not found on this white-labelled hub.');
        }

        return $user;
    }

    private function assertEmailAvailable(string $connection, string $email, ?int $ignoreId = null): void
    {
        $query = User::on($connection)->where('email', $email);
        if ($ignoreId) {
            $query->where('id', '!=', $ignoreId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'email' => 'The email has already been taken on this hub.',
            ]);
        }
    }

    private function deleteTokens(string $connection, int $userId): void
    {
        if (! DB::connection($connection)->getSchemaBuilder()->hasTable('personal_access_tokens')) {
            return;
        }

        DB::connection($connection)->table('personal_access_tokens')
            ->where('tokenable_type', User::class)
            ->where('tokenable_id', $userId)
            ->delete();
    }

    private function powerAdminCountOnConnection(string $connection): int
    {
        return User::on($connection)
            ->where(function ($q) {
                $q->where('role', User::ROLE_POWER_ADMIN)
                    ->orWhereHas('roleAssignments', fn ($r) => $r->where('role', User::ROLE_POWER_ADMIN));
            })
            ->count();
    }
}
