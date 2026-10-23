<?php

namespace App\Services;

use App\Models\Hub;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Assign / revoke multiple roles per user (§2.7.1).
 * All assigned roles are equal — no primary / secondary.
 * users.role is a legacy single-string mirror (privilege-ordered) for
 * audit / old call sites; user_roles is the source of truth.
 * Logs user.role_assigned / user.role_removed.
 */
class UserRolesService
{
    /**
     * Highest privilege first — used only to fill legacy users.role when
     * a single string is required (activity logs, old APIs).
     *
     * @var list<string>
     */
    public const PRIVILEGE_ORDER = [
        User::ROLE_POWER_ADMIN,
        User::ROLE_FINPROMS_ADMIN,
        User::ROLE_CLIENT_ADMIN,
        User::ROLE_NETWORK_ADMIN,
        User::ROLE_FIRM_ADMIN,
        User::ROLE_MANAGER,
        User::ROLE_APPROVER,
        User::ROLE_ADMIN_STAFF,
        User::ROLE_ADVISOR,
        User::ROLE_USER,
    ];

    public function __construct(
        private readonly ActivityLogService $activityLogs,
        private readonly HubRolesService $hubRoles,
    ) {}

    public function pivotReady(?User $user = null): bool
    {
        try {
            $connection = $user?->getConnectionName();

            return $connection
                ? Schema::connection($connection)->hasTable('user_roles')
                : Schema::hasTable('user_roles');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Ensure the legacy users.role row exists in the pivot (silent — no activity log).
     */
    public function ensureRoleInPivot(User $user): void
    {
        if (! $this->pivotReady($user)) {
            return;
        }

        $role = $this->normalizeRole((string) ($user->role ?? ''));
        if ($role === '') {
            return;
        }

        UserRole::on($user->getConnectionName())
            ->firstOrCreate(
                ['user_id' => $user->id, 'role' => $role],
                []
            );
    }

    /**
     * @deprecated Use ensureRoleInPivot()
     */
    public function ensurePrimaryInPivot(User $user): void
    {
        $this->ensureRoleInPivot($user);
    }

    /**
     * Clear pivot rows so the next syncRoles treats every role as newly assigned (logged).
     */
    public function resetPivotForLoggedSync(User $user): void
    {
        if (! $this->pivotReady($user)) {
            return;
        }

        UserRole::on($user->getConnectionName())->where('user_id', $user->id)->delete();
        $user->unsetRelation('roleAssignments');
    }

    /**
     * @return list<string>
     */
    public function roleKeys(User $user): array
    {
        if (! $this->pivotReady($user)) {
            $role = $this->normalizeRole((string) ($user->role ?? ''));

            return $role !== '' ? [$role] : [User::ROLE_USER];
        }

        if ($user->relationLoaded('roleAssignments')) {
            $keys = $user->roleAssignments
                ->pluck('role')
                ->map(fn ($role) => $this->normalizeRole((string) $role))
                ->filter()
                ->unique()
                ->values()
                ->all();
        } else {
            $keys = UserRole::on($user->getConnectionName())
                ->where('user_id', $user->id)
                ->pluck('role')
                ->map(fn ($role) => $this->normalizeRole((string) $role))
                ->filter()
                ->unique()
                ->values()
                ->all();
        }

        if ($keys === []) {
            $fallback = $this->normalizeRole((string) ($user->role ?? ''));

            return $fallback !== '' ? [$fallback] : [User::ROLE_USER];
        }

        return $this->hubRoles->orderRoles($keys);
    }

    /**
     * Replace the user's role set. At least one role required.
     * All roles are equal; users.role is updated to a privilege-ordered
     * legacy mirror for single-string call sites only.
     *
     * @param  list<string>  $roles
     * @return array{
     *   roles: list<string>,
     *   assigned: list<string>,
     *   removed: list<string>
     * }
     */
    public function syncRoles(
        User $user,
        array $roles,
        ?string $legacyRole = null,
        bool $log = true,
        ?User $actor = null,
        ?Request $request = null,
        ?Hub $hub = null,
        bool $syncFirmHead = true,
    ): array {
        $normalized = [];
        foreach ($roles as $role) {
            $role = $this->normalizeRole((string) $role);
            if ($role === '' || in_array($role, $normalized, true)) {
                continue;
            }
            $normalized[] = $role;
        }

        if ($normalized === []) {
            throw ValidationException::withMessages([
                'roles' => 'A user must have at least one role.',
            ]);
        }

        $normalized = $this->hubRoles->orderRoles($normalized);

        if ($syncFirmHead) {
            app(FirmHeadRoleSyncService::class)->assertRolesAllowed($user, $normalized);
        }

        // Legacy column only — not a "primary" role the client can choose.
        $legacy = $this->normalizeRole((string) ($legacyRole ?? ''));
        if ($legacy === '' || ! in_array($legacy, $normalized, true)) {
            $legacy = $this->canonicalLegacyRole($normalized);
        }

        $before = $this->roleKeys($user);

        $assigned = array_values(array_diff($normalized, $before));
        $removed = array_values(array_diff($before, $normalized));

        if ($this->pivotReady($user)) {
            $connection = $user->getConnectionName();
            UserRole::on($connection)->where('user_id', $user->id)->whereNotIn('role', $normalized)->delete();

            $now = now();
            foreach ($normalized as $role) {
                UserRole::on($connection)->firstOrCreate(
                    ['user_id' => $user->id, 'role' => $role],
                    ['created_at' => $now, 'updated_at' => $now]
                );
            }
        }

        if ($user->role !== $legacy) {
            $user->forceFill(['role' => $legacy])->save();
        } else {
            $user->refresh();
        }

        $user->unsetRelation('roleAssignments');
        $user->setRelation(
            'roleAssignments',
            collect($normalized)->map(fn (string $role) => new UserRole([
                'user_id' => $user->id,
                'role' => $role,
            ]))
        );

        if ($log && ($assigned !== [] || $removed !== [])) {
            $this->logRoleChanges(
                $user,
                $assigned,
                $removed,
                $normalized,
                $actor,
                $request,
                $hub
            );
        }

        // Drop per-user capability overrides that no longer sit in the role UNION.
        if ($assigned !== [] || $removed !== []) {
            try {
                $pruneHub = $hub;
                if (! $pruneHub) {
                    $pruneHub = app(HubService::class)->current();
                }
                if ($pruneHub) {
                    app(UserCapabilitiesService::class)->pruneToRoleUnion(
                        $pruneHub,
                        $user,
                        log: $log,
                        actor: $actor,
                        request: $request,
                    );
                }
            } catch (\Throwable) {
                // Overrides prune must not block role sync.
            }
        }

        $this->hubRoles->forgetUserCountsCache($hub ? (int) $hub->id : null);

        if ($syncFirmHead && ($assigned !== [] || $removed !== [])) {
            try {
                app(FirmHeadRoleSyncService::class)->afterRolesSynced(
                    $user,
                    $before,
                    $normalized,
                    $actor,
                    $request,
                    $hub,
                );
            } catch (ValidationException $e) {
                throw $e;
            } catch (\Throwable) {
                // Head sync must not leave roles half-applied silently for validation;
                // other failures are non-fatal (e.g. missing firms.head_user_id).
            }
        }

        return [
            'roles' => $normalized,
            'assigned' => $assigned,
            'removed' => $removed,
        ];
    }

    /**
     * Parse API / import input: prefer roles[], else single role, else keep existing.
     * Ignores primary_role if present (legacy clients).
     *
     * @param  array<string, mixed>  $payload
     * @return array{roles: list<string>}|null  null = do not change roles
     */
    public function rolesFromPayload(array $payload, ?User $existing = null): ?array
    {
        $hasRoles = array_key_exists('roles', $payload);
        $hasRole = array_key_exists('role', $payload);

        if (! $hasRoles && ! $hasRole) {
            return null;
        }

        $roles = [];
        if ($hasRoles) {
            $raw = $payload['roles'];
            if (is_string($raw)) {
                $roles = $this->splitRoleString($raw);
            } elseif (is_array($raw)) {
                foreach ($raw as $item) {
                    if (is_string($item) || is_numeric($item)) {
                        $roles[] = (string) $item;
                    }
                }
            }
        } elseif ($hasRole) {
            $roles = $this->splitRoleString((string) $payload['role']);
        } elseif ($existing) {
            $roles = $this->roleKeys($existing);
        }

        $normalized = [];
        foreach ($roles as $role) {
            $role = $this->normalizeRole($role);
            if ($role !== '' && ! in_array($role, $normalized, true)) {
                $normalized[] = $role;
            }
        }

        if ($normalized === [] && $existing) {
            $normalized = $this->roleKeys($existing);
        }

        if ($normalized === []) {
            throw ValidationException::withMessages([
                'roles' => 'A user must have at least one role.',
            ]);
        }

        return [
            'roles' => $this->hubRoles->orderRoles($normalized),
        ];
    }

    /**
     * Privilege-ordered role for legacy users.role / single-string contexts.
     *
     * @param  list<string>  $roles
     */
    public function canonicalLegacyRole(array $roles): string
    {
        foreach (self::PRIVILEGE_ORDER as $role) {
            if (in_array($role, $roles, true)) {
                return $role;
            }
        }

        return $roles[0] ?? User::ROLE_USER;
    }

    /**
     * @deprecated Use canonicalLegacyRole()
     *
     * @param  list<string>  $roles
     */
    public function pickPrimary(array $roles): string
    {
        return $this->canonicalLegacyRole($roles);
    }

    public function normalizeRole(string $role): string
    {
        $role = $this->hubRoles->normalizeRoleKey($role);
        if ($role === 'admin') {
            return User::ROLE_CLIENT_ADMIN;
        }

        return $role;
    }

    /**
     * @return list<string>
     */
    public function splitRoleString(string $value): array
    {
        $value = trim($value);
        if ($value === '') {
            return [];
        }

        $parts = preg_split('/[|,;]+/', $value) ?: [];

        return array_values(array_filter(array_map('trim', $parts), fn (string $part) => $part !== ''));
    }

    /**
     * @param  list<string>  $assigned
     * @param  list<string>  $removed
     * @param  list<string>  $rolesAfter
     */
    private function logRoleChanges(
        User $subject,
        array $assigned,
        array $removed,
        array $rolesAfter,
        ?User $actor,
        ?Request $request,
        ?Hub $hub,
    ): void {
        $actor ??= ($request?->user() instanceof User ? $request->user() : null);

        foreach ($assigned as $role) {
            $this->activityLogs->log([
                'action' => 'user.role_assigned',
                'description' => 'Role "'.$role.'" assigned to '.$subject->email,
                'user' => $actor,
                'hub' => $hub,
                'request' => $request,
                'subject' => $subject,
                'status_code' => 200,
                'properties' => [
                    'target_user_id' => $subject->id,
                    'target_user_email' => $subject->email,
                    'role' => $role,
                    'roles_after' => $rolesAfter,
                ],
            ]);
        }

        foreach ($removed as $role) {
            $this->activityLogs->log([
                'action' => 'user.role_removed',
                'description' => 'Role "'.$role.'" removed from '.$subject->email,
                'user' => $actor,
                'hub' => $hub,
                'request' => $request,
                'subject' => $subject,
                'status_code' => 200,
                'properties' => [
                    'target_user_id' => $subject->id,
                    'target_user_email' => $subject->email,
                    'role' => $role,
                    'roles_after' => $rolesAfter,
                ],
            ]);
        }
    }
}
