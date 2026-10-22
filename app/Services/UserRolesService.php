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
 * users.role stays the primary role; user_roles holds the full set.
 * Logs user.role_assigned / user.role_removed (and primary changes).
 */
class UserRolesService
{
    /**
     * Highest privilege first — used when primary is omitted.
     *
     * @var list<string>
     */
    public const PRIVILEGE_ORDER = [
        User::ROLE_POWER_ADMIN,
        User::ROLE_FINPROMS_ADMIN,
        User::ROLE_CLIENT_ADMIN,
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
     * Ensure the primary users.role row exists in the pivot (silent — no activity log).
     */
    public function ensurePrimaryInPivot(User $user): void
    {
        if (! $this->pivotReady($user)) {
            return;
        }

        $primary = $this->normalizeRole((string) ($user->role ?? ''));
        if ($primary === '') {
            return;
        }

        UserRole::on($user->getConnectionName())
            ->firstOrCreate(
                ['user_id' => $user->id, 'role' => $primary],
                []
            );
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
            $primary = $this->normalizeRole((string) ($user->role ?? ''));

            return $primary !== '' ? [$primary] : [User::ROLE_USER];
        }

        return $this->hubRoles->orderRoles($keys);
    }

    /**
     * Replace the user's role set. At least one role required.
     *
     * @param  list<string>  $roles
     * @return array{
     *   roles: list<string>,
     *   primary: string,
     *   assigned: list<string>,
     *   removed: list<string>,
     *   primary_changed: bool
     * }
     */
    public function syncRoles(
        User $user,
        array $roles,
        ?string $primary = null,
        bool $log = true,
        ?User $actor = null,
        ?Request $request = null,
        ?Hub $hub = null,
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

        $primary = $this->normalizeRole((string) ($primary ?? ''));
        if ($primary === '' || ! in_array($primary, $normalized, true)) {
            $primary = $this->pickPrimary($normalized);
        }

        $before = $this->roleKeys($user);
        $beforePrimary = $this->normalizeRole((string) ($user->role ?? ''));

        $assigned = array_values(array_diff($normalized, $before));
        $removed = array_values(array_diff($before, $normalized));
        $primaryChanged = $beforePrimary !== $primary;

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

        if ($user->role !== $primary) {
            $user->forceFill(['role' => $primary])->save();
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

        if ($log && ($assigned !== [] || $removed !== [] || $primaryChanged)) {
            $this->logRoleChanges(
                $user,
                $assigned,
                $removed,
                $primary,
                $beforePrimary,
                $primaryChanged,
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

        return [
            'roles' => $normalized,
            'primary' => $primary,
            'assigned' => $assigned,
            'removed' => $removed,
            'primary_changed' => $primaryChanged,
        ];
    }

    /**
     * Parse API / import input: prefer roles[], else single role, else keep existing.
     *
     * @param  array<string, mixed>  $payload
     * @return array{roles: list<string>, primary: string}|null  null = do not change roles
     */
    public function rolesFromPayload(array $payload, ?User $existing = null): ?array
    {
        $hasRoles = array_key_exists('roles', $payload);
        $hasRole = array_key_exists('role', $payload);
        $hasPrimary = array_key_exists('primary_role', $payload);

        if (! $hasRoles && ! $hasRole && ! $hasPrimary) {
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

        $primary = $hasPrimary
            ? (string) $payload['primary_role']
            : ($hasRole && ! $hasRoles ? (string) $payload['role'] : null);

        if ($primary !== null && str_contains($primary, '|')) {
            $parts = $this->splitRoleString($primary);
            $primary = $parts[0] ?? null;
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

        $primaryNorm = $this->normalizeRole((string) ($primary ?? ''));
        if ($primaryNorm === '' || ! in_array($primaryNorm, $normalized, true)) {
            $primaryNorm = $this->pickPrimary($normalized);
        }

        return [
            'roles' => $this->hubRoles->orderRoles($normalized),
            'primary' => $primaryNorm,
        ];
    }

    /**
     * @param  list<string>  $roles
     */
    public function pickPrimary(array $roles): string
    {
        foreach (self::PRIVILEGE_ORDER as $role) {
            if (in_array($role, $roles, true)) {
                return $role;
            }
        }

        return $roles[0] ?? User::ROLE_USER;
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
        string $primary,
        string $beforePrimary,
        bool $primaryChanged,
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
                    'primary_role' => $primary,
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
                    'primary_role' => $primary,
                ],
            ]);
        }

        if ($primaryChanged && $assigned === [] && $removed === []) {
            $this->activityLogs->log([
                'action' => 'user.primary_role_changed',
                'description' => 'Primary role for '.$subject->email.' changed from "'.$beforePrimary.'" to "'.$primary.'"',
                'user' => $actor,
                'hub' => $hub,
                'request' => $request,
                'subject' => $subject,
                'status_code' => 200,
                'properties' => [
                    'target_user_id' => $subject->id,
                    'target_user_email' => $subject->email,
                    'primary_role_before' => $beforePrimary,
                    'primary_role' => $primary,
                    'roles_after' => $rolesAfter,
                ],
            ]);
        }
    }
}
