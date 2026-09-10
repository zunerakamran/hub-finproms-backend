<?php

namespace App\Services;

use App\Models\Hub;
use App\Models\User;
use App\Services\PowerAdminCapabilitiesService;

/**
 * Role × capability matrix for Power Admin.
 * Power Admin column is platform-global; other roles are per-hub.
 */
class CapabilitiesMatrixService
{
    /**
     * Roles shown as matrix columns (left → right).
     *
     * @var list<string>
     */
    public const MATRIX_ROLES = [
        User::ROLE_POWER_ADMIN,
        User::ROLE_FINPROMS_ADMIN,
        User::ROLE_CLIENT_ADMIN,
        User::ROLE_MANAGER,
        User::ROLE_APPROVER,
        User::ROLE_ADVISOR,
        User::ROLE_USER,
    ];

    public function __construct(
        private readonly PowerAdminCapabilitiesService $powerCapabilities
    ) {}

    /**
     * Which roles a capability row applies to.
     *
     * @return list<string>
     */
    public function rolesForCapability(string $key): array
    {
        if (str_starts_with($key, 'pa_')) {
            return [User::ROLE_POWER_ADMIN];
        }

        $meta = Hub::CHECKLIST_DEFINITIONS[$key] ?? null;
        $group = $meta['group'] ?? null;

        if ($group === Hub::GROUP_DASHBOARD) {
            $roles = [
                User::ROLE_FINPROMS_ADMIN,
                User::ROLE_CLIENT_ADMIN,
                User::ROLE_MANAGER,
            ];

            // Power Admin may also manage plans / import / discontinue advisors / invoices /
            // activity logs / pricing / renew day.
            if (in_array($key, [
                'dashboard_manage_plans',
                'advisor_excel_import',
                'advisor_discontinue',
                'dashboard_view_advisor_invoices',
                'dashboard_view_activity_logs',
                'dashboard_manage_advisor_pricing',
                'dashboard_manage_advisor_renewal',
            ], true)) {
                $roles[] = User::ROLE_POWER_ADMIN;
            }

            // Auto-renew date is primarily Power Admin + FinProms admin.
            if ($key === 'dashboard_manage_advisor_renewal') {
                return [
                    User::ROLE_POWER_ADMIN,
                    User::ROLE_FINPROMS_ADMIN,
                ];
            }

            return $roles;
        }

        if ($group === Hub::GROUP_MEMBER) {
            return [
                User::ROLE_APPROVER,
                User::ROLE_ADVISOR,
                User::ROLE_USER,
            ];
        }

        // Behaviour / Functionalities are hub-level, not per-role.
        return [];
    }

    /**
     * @return array{
     *   hub: array<string, mixed>,
     *   roles: list<array{key: string, label: string}>,
     *   behaviour: list<array{key: string, label: string, description: string, enabled: bool, exclusive_with: ?string}>,
     *   rows: list<array{key: string, label: string, description: string, group: string, group_label: string, cells: array<string, array{applicable: bool, enabled: bool}}>}
     * }
     */
    public function matrix(Hub $hub): array
    {
        $power = $this->powerCapabilities->resolved();
        $roleCaps = $this->resolvedRoleCapabilities($hub);
        $privateMode = $hub->isPrivateInviteOnly();
        $publicMode = $hub->isPublicSubscribe();

        $roles = [];
        foreach (self::MATRIX_ROLES as $role) {
            $roles[] = [
                'key' => $role,
                'label' => User::ROLE_LABELS[$role] ?? $role,
            ];
        }

        // Functionalities live on the Hub checklist screen — not repeated here.
        $behaviour = [];

        $rows = [];

        // Power Admin platform rows
        foreach (PowerAdminCapabilitiesService::DEFINITIONS as $key => $meta) {
            $cells = [];
            foreach (self::MATRIX_ROLES as $role) {
                $applicable = $role === User::ROLE_POWER_ADMIN;
                $cells[$role] = [
                    'applicable' => $applicable,
                    'enabled' => $applicable ? (bool) ($power[$key] ?? false) : false,
                ];
            }
            $rows[] = [
                'key' => $key,
                'label' => $meta['label'],
                'description' => $meta['description'],
                'group' => 'power_admin',
                'group_label' => 'Power Admin (platform)',
                'requires_private' => false,
                'inactive' => false,
                'cells' => $cells,
            ];
        }

        // Member + dashboard rows (per hub, per role) — user capabilities only
        foreach (Hub::CHECKLIST_DEFINITIONS as $key => $meta) {
            $group = $meta['group'] ?? Hub::GROUP_BEHAVIOUR;
            if (! Hub::isCapabilityKey($key)) {
                continue;
            }

            $requiresPrivate = Hub::isPrivateCapability($key);
            $requiresPublic = Hub::isPublicCapability($key);
            $inactive = ($requiresPrivate && $publicMode) || ($requiresPublic && $privateMode);

            $applicableRoles = $this->rolesForCapability($key);
            $cells = [];
            foreach (self::MATRIX_ROLES as $role) {
                $applicable = in_array($role, $applicableRoles, true);
                $enabled = false;
                if ($applicable) {
                    $enabled = (bool) ($roleCaps[$role][$key] ?? false);
                }
                // Mode-locked caps are inactive (shown off / not editable).
                if ($inactive) {
                    $enabled = false;
                }
                $cells[$role] = [
                    'applicable' => $applicable,
                    'enabled' => $enabled,
                ];
            }

            $rows[] = [
                'key' => $key,
                'label' => $meta['label'],
                'description' => $meta['description'],
                'group' => $group,
                'group_label' => Hub::CHECKLIST_GROUPS[$group] ?? $group,
                'requires_private' => $requiresPrivate,
                'requires_public' => $requiresPublic,
                'inactive' => $inactive,
                'inactive_reason' => $inactive
                    ? ($requiresPrivate
                        ? 'private_only'
                        : 'public_only')
                    : null,
                'cells' => $cells,
            ];
        }

        return [
            'hub' => [
                'id' => $hub->id,
                'name' => $hub->name,
                'slug' => $hub->slug,
                'type' => $hub->type,
                'private_invite_only' => $privateMode,
                'public_subscribe' => $publicMode,
            ],
            'private_capability_keys' => Hub::PRIVATE_CAPABILITY_KEYS,
            'public_capability_keys' => Hub::PUBLIC_CAPABILITY_KEYS,
            'roles' => $roles,
            'behaviour' => $behaviour,
            'rows' => $rows,
        ];
    }

    /**
     * @param  array{
     *   behaviour?: array<string, mixed>,
     *   matrix?: array<string, array<string, mixed>>,
     *   power_admin?: array<string, mixed>
     * }  $payload
     * @return array<string, mixed>
     */
    public function update(Hub $hub, array $payload): array
    {
        // Functionalities (formerly hub behaviour) are edited on the Hub checklist
        // screen only — ignore any legacy behaviour payload here.

        // Power Admin column (platform)
        if (isset($payload['power_admin']) && is_array($payload['power_admin'])) {
            $this->powerCapabilities->update($payload['power_admin']);
        } elseif (isset($payload['matrix'][User::ROLE_POWER_ADMIN]) && is_array($payload['matrix'][User::ROLE_POWER_ADMIN])) {
            $paPartial = [];
            foreach (array_keys(PowerAdminCapabilitiesService::DEFINITIONS) as $key) {
                if (array_key_exists($key, $payload['matrix'][User::ROLE_POWER_ADMIN])) {
                    $paPartial[$key] = $payload['matrix'][User::ROLE_POWER_ADMIN][$key];
                }
            }
            if ($paPartial !== []) {
                $this->powerCapabilities->update($paPartial);
            }
        }

        // Per-role hub matrix (incl. hub caps enabled for power_admin, e.g. advisor import)
        $roleCaps = $this->resolvedRoleCapabilities($hub);
        if (isset($payload['matrix']) && is_array($payload['matrix'])) {
            foreach (self::MATRIX_ROLES as $role) {
                if (! isset($payload['matrix'][$role]) || ! is_array($payload['matrix'][$role])) {
                    continue;
                }
                foreach ($payload['matrix'][$role] as $key => $value) {
                    $key = (string) $key;
                    if (str_starts_with($key, 'pa_')) {
                        continue;
                    }
                    if (! Hub::isCapabilityKey($key)) {
                        continue;
                    }
                    $applicable = $this->rolesForCapability($key);
                    if (! in_array($role, $applicable, true)) {
                        continue;
                    }
                    $roleCaps[$role][$key] = filter_var($value, FILTER_VALIDATE_BOOLEAN);
                }
            }
        }

        $hub->role_capabilities = $roleCaps;

        // Keep legacy hub checklist member/dashboard flags in sync:
        // enabled if ANY applicable role has the capability.
        $checklist = $hub->resolvedChecklist();
        foreach (Hub::CHECKLIST_DEFINITIONS as $key => $meta) {
            if (! Hub::isCapabilityKey($key)) {
                continue;
            }
            $any = false;
            foreach ($this->rolesForCapability($key) as $role) {
                if (! empty($roleCaps[$role][$key])) {
                    $any = true;
                    break;
                }
            }
            $checklist[$key] = $any;
        }
        $hub->checklist = $checklist;
        $hub->save();

        app(HubService::class)->forgetCurrentCache();

        return $this->matrix($hub->fresh());
    }

    /**
     * Default role capabilities for a hub type, seeded from checklist defaults.
     *
     * @return array<string, array<string, bool>>
     */
    public function defaultRoleCapabilities(string $hubType): array
    {
        $checklistDefaults = Hub::defaultChecklist($hubType);
        $matrix = [];

        foreach (self::MATRIX_ROLES as $role) {
            $matrix[$role] = [];
        }

        foreach (Hub::CHECKLIST_DEFINITIONS as $key => $meta) {
            if (($meta['group'] ?? null) === Hub::GROUP_BEHAVIOUR) {
                continue;
            }
            $default = (bool) ($checklistDefaults[$key] ?? false);
            foreach ($this->rolesForCapability($key) as $role) {
                $matrix[$role][$key] = $default;
            }
        }

        // Approver: browse-focused by default
        foreach (array_keys($matrix[User::ROLE_APPROVER] ?? []) as $key) {
            if (str_starts_with($key, 'member_') && $key !== 'member_browse_catalog' && $key !== 'member_view_purchases') {
                $matrix[User::ROLE_APPROVER][$key] = false;
            }
        }

        return $matrix;
    }

    /**
     * @return array<string, array<string, bool>>
     */
    public function resolvedRoleCapabilities(Hub $hub): array
    {
        $defaults = $this->defaultRoleCapabilities($hub->type);
        $stored = is_array($hub->role_capabilities) ? $hub->role_capabilities : [];

        // Bootstrap from legacy checklist if role_capabilities empty
        if ($stored === []) {
            $checklist = $hub->resolvedChecklist();
            foreach (Hub::CHECKLIST_DEFINITIONS as $key => $meta) {
                if (($meta['group'] ?? null) === Hub::GROUP_BEHAVIOUR) {
                    continue;
                }
                $enabled = (bool) ($checklist[$key] ?? false);
                foreach ($this->rolesForCapability($key) as $role) {
                    $defaults[$role][$key] = $enabled;
                }
            }

            return $defaults;
        }

        foreach ($defaults as $role => $caps) {
            foreach ($caps as $key => $default) {
                if (isset($stored[$role]) && is_array($stored[$role]) && array_key_exists($key, $stored[$role])) {
                    $defaults[$role][$key] = filter_var($stored[$role][$key], FILTER_VALIDATE_BOOLEAN);
                }
            }
        }

        return $defaults;
    }

    /**
     * Whether a user role has a capability on this hub (member/dashboard).
     */
    public function roleCan(Hub $hub, string $role, string $flag): bool
    {
        // Platform-only Power Admin capabilities.
        if (str_starts_with($flag, 'pa_')) {
            return $this->powerCapabilities->can($flag);
        }

        // Private-hub tools are inactive while the hub is public.
        if (Hub::isPrivateCapability($flag) && ! $hub->isPrivateInviteOnly()) {
            return false;
        }

        // Public-hub tools are inactive while the hub is private invite-only.
        if (Hub::isPublicCapability($flag) && $hub->isPrivateInviteOnly()) {
            return false;
        }

        // Legacy admin → client_admin
        if ($role === 'admin') {
            $role = User::ROLE_CLIENT_ADMIN;
        }

        $applicable = $this->rolesForCapability($flag);

        // Hub caps that apply to power_admin (e.g. advisor import) use the per-hub matrix.
        if ($role === User::ROLE_POWER_ADMIN) {
            if (in_array(User::ROLE_POWER_ADMIN, $applicable, true)) {
                $roleCaps = $this->resolvedRoleCapabilities($hub);

                return (bool) ($roleCaps[User::ROLE_POWER_ADMIN][$flag] ?? false);
            }

            // Functionalities are hub-level for everyone (including power_admin).
            if ($applicable === [] && Hub::isFunctionalityKey($flag)) {
                return $hub->can($flag);
            }

            return false;
        }

        // Functionalities / unknown non-role keys → hub checklist.
        if ($applicable === []) {
            return $hub->can($flag);
        }

        // Capability applies to other roles only — do NOT fall back to the
        // hub-wide OR checklist (that leaked unchecked client_admin cells).
        if (! in_array($role, $applicable, true)) {
            return false;
        }

        $roleCaps = $this->resolvedRoleCapabilities($hub);

        return (bool) ($roleCaps[$role][$flag] ?? false);
    }
}
