<?php

namespace App\Services;

use App\Models\Hub;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Per-user capability overrides within the role UNION (§2.7.3).
 *
 * Storage: users.capability_overrides JSON denylist — { "flag": false }.
 * Missing key = inherit role UNION. Cannot grant a capability the roles do not have.
 * Activity: user.capability_enabled / user.capability_disabled.
 */
class UserCapabilitiesService
{
    public function __construct(
        private readonly CapabilitiesMatrixService $matrix,
        private readonly ActivityLogService $activityLogs,
        private readonly PowerAdminCapabilitiesService $powerCapabilities,
    ) {}

    public function columnReady(?User $user = null): bool
    {
        try {
            $connection = $user?->getConnectionName();

            return $connection
                ? Schema::connection($connection)->hasColumn('users', 'capability_overrides')
                : Schema::hasColumn('users', 'capability_overrides');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Raw denylist map (flag => false). Ignores invalid / non-false entries.
     *
     * @return array<string, false>
     */
    public function overrides(User $user): array
    {
        if (! $this->columnReady($user)) {
            return [];
        }

        $raw = $user->capability_overrides;
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $key => $value) {
            $key = (string) $key;
            if ($key === '' || (! Hub::isCapabilityKey($key) && ! str_starts_with($key, 'pa_'))) {
                continue;
            }
            if (filter_var($value, FILTER_VALIDATE_BOOLEAN) === false) {
                $out[$key] = false;
            }
        }

        return $out;
    }

    /**
     * Whether the user override still allows this flag (role UNION already passed).
     */
    public function userAllows(User $user, string $flag): bool
    {
        $overrides = $this->overrides($user);

        return ! array_key_exists($flag, $overrides) || $overrides[$flag] !== false;
    }

    /**
     * Roles used when evaluating which capabilities this user may receive.
     *
     * @return list<string>
     */
    public function evaluationRoles(User $user): array
    {
        $acting = app(ActingAdvisorService::class);
        if ($acting->canActOnBehalf($user) && $acting->actingAdvisor($user)) {
            return [User::ROLE_ADVISOR];
        }

        return $user->roleKeys();
    }

    /**
     * Capability keys granted by ANY of the user's assigned roles on this hub
     * (role matrix only — before per-user denylist / module gates).
     *
     * @return list<string>
     */
    public function roleGrantedKeys(Hub $hub, User $user): array
    {
        $byRole = $this->keysGrantedByRole($hub, $user);
        $keys = [];
        foreach ($byRole as $roleKeys) {
            foreach ($roleKeys as $key) {
                $keys[] = $key;
            }
        }
        sort($keys);

        return array_values(array_unique($keys));
    }

    /**
     * Map of role → capability keys that role grants for this user on this hub.
     *
     * @return array<string, list<string>>
     */
    public function keysGrantedByRole(Hub $hub, User $user): array
    {
        $roles = $this->evaluationRoles($user);
        $byRole = [];
        foreach ($roles as $role) {
            $byRole[$role] = [];
        }

        foreach (Hub::CHECKLIST_DEFINITIONS as $key => $meta) {
            if (! Hub::isCapabilityKey($key)) {
                continue;
            }
            foreach ($roles as $role) {
                if ($this->matrix->roleCan($hub, $role, $key)) {
                    $byRole[$role][] = $key;
                }
            }
        }

        if (in_array(User::ROLE_POWER_ADMIN, $roles, true)) {
            foreach (array_keys(PowerAdminCapabilitiesService::DEFINITIONS) as $paKey) {
                if ($this->powerCapabilities->can($paKey)) {
                    $byRole[User::ROLE_POWER_ADMIN][] = $paKey;
                }
            }
        }

        foreach ($byRole as $role => $keys) {
            $unique = array_values(array_unique($keys));
            sort($unique);
            $byRole[$role] = $unique;
        }

        return $byRole;
    }

    /**
     * UI payload: role-separated capability sections + flat rows for save.
     *
     * @return array{
     *   user: array<string, mixed>,
     *   hub: array{id: int, name: string, slug: string, type: string},
     *   roles: list<string>,
     *   role_labels: list<string>,
     *   rows: list<array{key: string, label: string, description: string, group: string, group_label: string, enabled: bool, inherited: bool, granted_by: list<string>, granted_by_labels: list<string>}>,
     *   role_sections: list<array{role: string, label: string, count: int, rows: list<array{key: string, label: string, description: string, group: string, group_label: string, enabled: bool, inherited: bool, granted_by: list<string>, granted_by_labels: list<string>, shared: bool}>}>,
     *   group_order: list<string>,
     *   overrides: array<string, false>,
     *   summary: array{total: int, enabled: int, overridden: int, roles: int}
     * }
     */
    public function matrixForUser(Hub $hub, User $user): array
    {
        $byRole = $this->keysGrantedByRole($hub, $user);
        $overrides = $this->overrides($user);
        $roles = array_keys($byRole);
        $legacyRole = (string) ($user->role ?: ($roles[0] ?? ''));
        $roleLabels = [];
        foreach ($roles as $role) {
            $roleLabels[$role] = $hub->roleLabel($role);
        }

        // Reverse index: capability → roles that grant it.
        $grantedBy = [];
        foreach ($byRole as $role => $keys) {
            foreach ($keys as $key) {
                $grantedBy[$key][] = $role;
            }
        }

        $rowsByKey = [];
        foreach ($grantedBy as $key => $grantingRoles) {
            $rowsByKey[$key] = $this->buildCapabilityRow(
                (string) $key,
                $grantingRoles,
                $roleLabels,
                $overrides
            );
        }

        $rows = array_values($rowsByKey);
        usort($rows, [$this, 'sortCapabilityRows']);

        $roleSections = [];
        foreach ($roles as $role) {
            $sectionRows = [];
            foreach ($byRole[$role] as $key) {
                $row = $rowsByKey[$key] ?? null;
                if (! $row) {
                    continue;
                }
                $sectionRows[] = [
                    ...$row,
                    'shared' => count($row['granted_by']) > 1,
                ];
            }
            usort($sectionRows, [$this, 'sortCapabilityRows']);
            $roleSections[] = [
                'role' => $role,
                'label' => $roleLabels[$role] ?? $role,
                'count' => count($sectionRows),
                'rows' => $sectionRows,
            ];
        }

        $enabledCount = 0;
        $overriddenCount = 0;
        foreach ($rows as $row) {
            if ($row['enabled']) {
                $enabledCount++;
            }
            if (! $row['inherited']) {
                $overriddenCount++;
            }
        }

        $groupOrder = array_values(array_unique(array_merge(
            ['power_admin'],
            Hub::MATRIX_GROUP_ORDER
        )));

        return [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $legacyRole,
                'roles' => $roles,
                'role_labels' => array_values($roleLabels),
            ],
            'hub' => [
                'id' => (int) $hub->id,
                'name' => $hub->name,
                'slug' => $hub->slug,
                'type' => $hub->type,
            ],
            'roles' => $roles,
            'role_labels' => array_values($roleLabels),
            'rows' => $rows,
            'role_sections' => $roleSections,
            'group_order' => $groupOrder,
            'overrides' => $overrides,
            'summary' => [
                'total' => count($rows),
                'enabled' => $enabledCount,
                'overridden' => $overriddenCount,
                'roles' => count($roles),
            ],
        ];
    }

    /**
     * @param  list<string>  $grantingRoles
     * @param  array<string, string>  $roleLabels
     * @param  array<string, false>  $overrides
     * @return array{key: string, label: string, description: string, group: string, group_label: string, enabled: bool, inherited: bool, granted_by: list<string>, granted_by_labels: list<string>}
     */
    private function buildCapabilityRow(string $key, array $grantingRoles, array $roleLabels, array $overrides): array
    {
        if (str_starts_with($key, 'pa_')) {
            $meta = PowerAdminCapabilitiesService::DEFINITIONS[$key] ?? null;
            $group = 'power_admin';
            $groupLabel = 'Power Admin (platform)';
            $label = $meta['label'] ?? $key;
            $description = $meta['description'] ?? '';
        } else {
            $meta = Hub::CHECKLIST_DEFINITIONS[$key] ?? null;
            $group = (string) ($meta['group'] ?? Hub::GROUP_DASHBOARD_HUB);
            $groupLabel = Hub::CHECKLIST_GROUPS[$group] ?? $group;
            $label = $meta['label'] ?? $key;
            $description = $meta['description'] ?? '';
        }

        $inherited = true;
        $enabled = true;
        if (array_key_exists($key, $overrides) && $overrides[$key] === false) {
            $enabled = false;
            $inherited = false;
        }

        $grantLabels = [];
        foreach ($grantingRoles as $role) {
            $grantLabels[] = $roleLabels[$role] ?? $role;
        }

        return [
            'key' => $key,
            'label' => $label,
            'description' => $description,
            'group' => $group,
            'group_label' => $groupLabel,
            'enabled' => $enabled,
            'inherited' => $inherited,
            'granted_by' => array_values($grantingRoles),
            'granted_by_labels' => $grantLabels,
        ];
    }

    /**
     * @param  array{group: string, label: string}  $a
     * @param  array{group: string, label: string}  $b
     */
    private function sortCapabilityRows(array $a, array $b): int
    {
        $order = Hub::MATRIX_GROUP_ORDER;
        $ga = array_search($a['group'], $order, true);
        $gb = array_search($b['group'], $order, true);
        if ($a['group'] === 'power_admin') {
            $ga = -1;
        }
        if ($b['group'] === 'power_admin') {
            $gb = -1;
        }
        $ga = $ga === false ? 999 : $ga;
        $gb = $gb === false ? 999 : $gb;
        if ($ga !== $gb) {
            return $ga <=> $gb;
        }

        return strcmp($a['label'], $b['label']);
    }

    /**
     * Replace per-user denylist from a map of role-granted flags → enabled bool.
     * Keys outside the role UNION are rejected. Only false values are stored.
     *
     * @param  array<string, mixed>  $capabilities
     * @return array{
     *   user: array<string, mixed>,
     *   hub: array{id: int, name: string, slug: string, type: string},
     *   roles: list<string>,
     *   role_labels: list<string>,
     *   rows: list<array{key: string, label: string, description: string, group: string, group_label: string, enabled: bool, inherited: bool}>,
     *   group_order: list<string>,
     *   overrides: array<string, false>,
     *   enabled: list<string>,
     *   disabled: list<string>
     * }
     */
    public function update(
        Hub $hub,
        User $user,
        array $capabilities,
        ?User $actor = null,
        ?Request $request = null,
        bool $log = true,
    ): array {
        if (! $this->columnReady($user)) {
            throw ValidationException::withMessages([
                'capabilities' => 'Per-user capability overrides are not available on this database yet.',
            ]);
        }

        $granted = $this->roleGrantedKeys($hub, $user);
        $grantedSet = array_fill_keys($granted, true);

        $unknown = [];
        $desiredDisabled = [];
        foreach ($capabilities as $key => $value) {
            $key = (string) $key;
            if (! isset($grantedSet[$key])) {
                $unknown[] = $key;
                continue;
            }
            if (filter_var($value, FILTER_VALIDATE_BOOLEAN) === false) {
                $desiredDisabled[$key] = false;
            }
        }

        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'capabilities' => 'These capabilities are not granted by the user’s roles: '.implode(', ', $unknown),
            ]);
        }

        // If the client sent a partial map, keep existing disabled keys for
        // role-granted flags that were not included in the payload.
        $before = $this->overrides($user);
        $partial = ! $this->payloadCoversAllGranted($capabilities, $granted);
        if ($partial) {
            foreach ($before as $key => $_) {
                if (isset($grantedSet[$key]) && ! array_key_exists($key, $capabilities)) {
                    $desiredDisabled[$key] = false;
                }
            }
        }

        ksort($desiredDisabled);

        $enabled = [];
        $disabled = [];
        foreach ($granted as $key) {
            $wasOff = array_key_exists($key, $before);
            $nowOff = array_key_exists($key, $desiredDisabled);
            if ($wasOff && ! $nowOff) {
                $enabled[] = $key;
            } elseif (! $wasOff && $nowOff) {
                $disabled[] = $key;
            }
        }

        $user->forceFill([
            'capability_overrides' => $desiredDisabled === [] ? null : $desiredDisabled,
        ])->save();

        if ($log && ($enabled !== [] || $disabled !== [])) {
            $this->logChanges($hub, $user, $enabled, $disabled, $desiredDisabled, $actor, $request);
        }

        $payload = $this->matrixForUser($hub, $user->fresh() ?? $user);
        $payload['enabled'] = $enabled;
        $payload['disabled'] = $disabled;

        return $payload;
    }

    /**
     * Drop override keys that are no longer in the role UNION (e.g. after role revoke).
     */
    public function pruneToRoleUnion(Hub $hub, User $user, bool $log = false, ?User $actor = null, ?Request $request = null): void
    {
        if (! $this->columnReady($user)) {
            return;
        }

        $before = $this->overrides($user);
        if ($before === []) {
            return;
        }

        $granted = array_fill_keys($this->roleGrantedKeys($hub, $user), true);
        $kept = [];
        $removed = [];
        foreach ($before as $key => $value) {
            if (isset($granted[$key])) {
                $kept[$key] = false;
            } else {
                $removed[] = $key;
            }
        }

        if ($removed === []) {
            return;
        }

        $user->forceFill([
            'capability_overrides' => $kept === [] ? null : $kept,
        ])->save();

        if ($log && $removed !== []) {
            $this->activityLogs->log([
                'action' => 'user.capability_overrides_pruned',
                'description' => 'Removed per-user capability overrides that no longer match assigned roles for '.$user->email.'.',
                'user' => $actor,
                'hub' => $hub,
                'subject' => $user,
                'request' => $request,
                'properties' => [
                    'pruned' => $removed,
                    'remaining_overrides' => array_keys($kept),
                ],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $capabilities
     * @param  list<string>  $granted
     */
    private function payloadCoversAllGranted(array $capabilities, array $granted): bool
    {
        if ($granted === []) {
            return true;
        }

        foreach ($granted as $key) {
            if (! array_key_exists($key, $capabilities)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $enabled
     * @param  list<string>  $disabled
     * @param  array<string, false>  $overrides
     */
    private function logChanges(
        Hub $hub,
        User $user,
        array $enabled,
        array $disabled,
        array $overrides,
        ?User $actor,
        ?Request $request,
    ): void {
        if ($enabled !== []) {
            $this->activityLogs->log([
                'action' => 'user.capability_enabled',
                'description' => 'Restored role-inherited capabilities for '.$user->email.': '.implode(', ', $enabled),
                'user' => $actor,
                'hub' => $hub,
                'subject' => $user,
                'request' => $request,
                'properties' => [
                    'enabled' => $enabled,
                    'overrides' => $overrides,
                ],
            ]);
        }

        if ($disabled !== []) {
            $this->activityLogs->log([
                'action' => 'user.capability_disabled',
                'description' => 'Disabled role-inherited capabilities for '.$user->email.': '.implode(', ', $disabled),
                'user' => $actor,
                'hub' => $hub,
                'subject' => $user,
                'request' => $request,
                'properties' => [
                    'disabled' => $disabled,
                    'overrides' => $overrides,
                ],
            ]);
        }
    }
}
