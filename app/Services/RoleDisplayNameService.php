<?php

namespace App\Services;

use App\Models\Hub;
use App\Models\User;
use InvalidArgumentException;

class RoleDisplayNameService
{
    public function __construct(
        private readonly WhiteLabelHubSyncService $whiteLabelSync,
        private readonly HubService $hubs
    ) {}

    /**
     * @return array<string, string>
     */
    public function labels(Hub $hub): array
    {
        return $hub->resolvedRoleLabels();
    }

    public function label(Hub $hub, string $role): string
    {
        return $hub->roleLabel($role);
    }

    /**
     * @return list<array{key: string, label: string, default_label: string}>
     */
    public function editableRoles(Hub $hub): array
    {
        $labels = $this->labels($hub);
        $roles = [];
        foreach (CapabilitiesMatrixService::MATRIX_ROLES as $role) {
            if ($hub->isWhiteLabel() && ActingHubService::isControlPlaneRole($role)) {
                continue;
            }
            $roles[] = [
                'key' => $role,
                'label' => $labels[$role] ?? $role,
                'default_label' => User::ROLE_LABELS[$role] ?? $role,
            ];
        }

        return $roles;
    }

    /**
     * Replace hub role display-name overrides.
     * Empty / default values clear the override for that role.
     *
     * @param  array<string, mixed>  $names
     * @return array{roles: list<array{key: string, label: string, default_label: string}>, role_labels: array<string, string>}
     */
    public function update(Hub $hub, array $names): array
    {
        $cleaned = [];
        foreach (CapabilitiesMatrixService::MATRIX_ROLES as $role) {
            if ($hub->isWhiteLabel() && ActingHubService::isControlPlaneRole($role)) {
                continue;
            }
            if (! array_key_exists($role, $names)) {
                continue;
            }
            $value = trim((string) $names[$role]);
            if ($value === '') {
                continue;
            }
            if (mb_strlen($value) > 100) {
                throw new InvalidArgumentException("Display name for \"{$role}\" must be at most 100 characters.");
            }
            $default = User::ROLE_LABELS[$role] ?? $role;
            if ($value === $default) {
                continue;
            }
            $cleaned[$role] = $value;
        }

        // Roles omitted from the request keep existing overrides.
        $existing = is_array($hub->role_display_names) ? $hub->role_display_names : [];
        foreach (CapabilitiesMatrixService::MATRIX_ROLES as $role) {
            if ($hub->isWhiteLabel() && ActingHubService::isControlPlaneRole($role)) {
                unset($existing[$role]);
                continue;
            }
            if (array_key_exists($role, $names)) {
                if (isset($cleaned[$role])) {
                    $existing[$role] = $cleaned[$role];
                } else {
                    unset($existing[$role]);
                }
            }
        }

        $hub->role_display_names = $existing === [] ? null : $existing;
        $hub->save();
        $this->hubs->forgetCurrentCache();

        if ($hub->isWhiteLabel() && $hub->hasRemoteDatabaseConfigured()) {
            $this->whiteLabelSync->pushSettings($hub->fresh());
        }

        $hub = $hub->fresh();

        return [
            'roles' => $this->editableRoles($hub),
            'role_labels' => $this->labels($hub),
        ];
    }
}
