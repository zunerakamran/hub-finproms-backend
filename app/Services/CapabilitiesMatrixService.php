<?php

namespace App\Services;

use App\Models\Hub;
use App\Models\User;

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

        if ($group === Hub::GROUP_DASHBOARD || $group === Hub::GROUP_ADMIN_EMAILS) {
            // Auto-renew date stays limited to Power Admin + FinProms admin.
            if ($key === 'dashboard_manage_advisor_renewal') {
                return [
                    User::ROLE_POWER_ADMIN,
                    User::ROLE_FINPROMS_ADMIN,
                ];
            }

            // Shared-hub control plane only — who may use the hub switcher.
            if ($key === ActingHubService::CAPABILITY) {
                return [
                    User::ROLE_POWER_ADMIN,
                    User::ROLE_FINPROMS_ADMIN,
                ];
            }

            // Hub-admin dashboard / admin-email tools apply to every role column so
            // Power Admin can grant them to staff and remaining roles.
            return self::MATRIX_ROLES;
        }

        if ($group === Hub::GROUP_MEMBER
            || $group === Hub::GROUP_GENERAL
            || $group === Hub::GROUP_SOCIAL_MEDIA_COMPLIANCE
            || $group === Hub::GROUP_GENERAL_COMPLIANCE
            || $group === Hub::GROUP_WEBSITE_COMPLIANCE
        ) {
            // User-facing / compliance caps apply to every role column so Power Admin
            // can enable them for staff and users alike (no hard role lock-in).
            return self::MATRIX_ROLES;
        }

        // Behaviour / Modules / Functionalities are hub-level, not per-role.
        return [];
    }

    /**
     * Whether this role may open the member personal dashboard
     * (General options sections).
     */
    public function roleHasGeneralDashboardAccess(Hub $hub, string $role): bool
    {
        if ($role === 'admin') {
            $role = User::ROLE_CLIENT_ADMIN;
        }

        foreach (Hub::GENERAL_DASHBOARD_KEYS as $key) {
            if ($this->roleCan($hub, $role, $key)) {
                return true;
            }
        }

        return false;
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
        $shared = $this->sharedHub();
        $sharedRoleCaps = $hub->isWhiteLabel()
            ? $this->resolvedRoleCapabilities($shared)
            : $roleCaps;
        $privateMode = $hub->isPrivateInviteOnly();
        $publicMode = $hub->isPublicSubscribe();

        $roles = [];
        foreach (self::MATRIX_ROLES as $role) {
            $roles[] = [
                'key' => $role,
                'label' => $hub->roleLabel($role),
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

        $smcModuleOn = $hub->hasSocialMediaComplianceModule();
        $gcModuleOn = $hub->hasGeneralComplianceModule();
        $wcModuleOn = $hub->hasWebsiteComplianceModule();

        // Member + dashboard + compliance rows (per hub, per role)
        foreach (Hub::CHECKLIST_DEFINITIONS as $key => $meta) {
            $group = $meta['group'] ?? Hub::GROUP_BEHAVIOUR;
            if (! Hub::isCapabilityKey($key)) {
                continue;
            }

            $requiresPrivate = Hub::isPrivateCapability($key);
            $requiresPublic = Hub::isPublicCapability($key);
            $requiresSmcModule = Hub::isSocialMediaComplianceCapability($key);
            $requiresGcModule = Hub::isGeneralComplianceCapability($key);
            $requiresWcModule = Hub::isWebsiteComplianceCapability($key);
            $inactive = ($requiresPrivate && $publicMode)
                || ($requiresPublic && $privateMode)
                || ($requiresSmcModule && ! $smcModuleOn)
                || ($requiresGcModule && ! $gcModuleOn)
                || ($requiresWcModule && ! $wcModuleOn);

            $inactiveReason = null;
            if ($inactive) {
                if ($requiresSmcModule && ! $smcModuleOn) {
                    $inactiveReason = 'module_social_media_compliance_off';
                } elseif ($requiresGcModule && ! $gcModuleOn) {
                    $inactiveReason = 'module_general_compliance_off';
                } elseif ($requiresWcModule && ! $wcModuleOn) {
                    $inactiveReason = 'module_website_compliance_off';
                } elseif ($requiresPrivate) {
                    $inactiveReason = 'private_only';
                } else {
                    $inactiveReason = 'public_only';
                }
            }

            $applicableRoles = $this->rolesForCapability($key);
            $cells = [];
            foreach (self::MATRIX_ROLES as $role) {
                $applicable = in_array($role, $applicableRoles, true);
                $enabled = false;
                if ($applicable) {
                    // Hub switcher flag always reflects the shared hub matrix.
                    if ($key === ActingHubService::CAPABILITY) {
                        $enabled = (bool) ($sharedRoleCaps[$role][$key] ?? false);
                    } else {
                        $enabled = (bool) ($roleCaps[$role][$key] ?? false);
                    }
                }
                // Mode / module-locked caps are inactive (shown off / not editable).
                if ($inactive) {
                    $enabled = false;
                }
                $cells[$role] = [
                    'applicable' => $applicable,
                    'enabled' => $enabled,
                ];
            }

            $requiresModule = null;
            if ($requiresSmcModule) {
                $requiresModule = 'module_social_media_compliance';
            } elseif ($requiresGcModule) {
                $requiresModule = 'module_general_compliance';
            } elseif ($requiresWcModule) {
                $requiresModule = 'module_website_compliance';
            }

            $rows[] = [
                'key' => $key,
                'label' => $meta['label'],
                'description' => $meta['description'],
                'group' => $group,
                'group_label' => Hub::CHECKLIST_GROUPS[$group] ?? $group,
                'requires_private' => $requiresPrivate,
                'requires_public' => $requiresPublic,
                'requires_module' => $requiresModule,
                'inactive' => $inactive,
                'inactive_reason' => $inactiveReason,
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
                'module_social_media_compliance' => $smcModuleOn,
                'module_website_compliance' => $wcModuleOn,
                'module_general_compliance' => $gcModuleOn,
            ],
            'control_plane_roles' => ActingHubService::CONTROL_PLANE_ROLES,
            'control_plane_hub' => [
                'id' => $shared->id,
                'slug' => $shared->slug,
            ],
            'private_capability_keys' => Hub::PRIVATE_CAPABILITY_KEYS,
            'public_capability_keys' => Hub::PUBLIC_CAPABILITY_KEYS,
            'social_media_compliance_capability_keys' => Hub::SOCIAL_MEDIA_COMPLIANCE_CAPABILITY_KEYS,
            'general_compliance_capability_keys' => Hub::GENERAL_COMPLIANCE_CAPABILITY_KEYS,
            'website_compliance_capability_keys' => Hub::WEBSITE_COMPLIANCE_CAPABILITY_KEYS,
            'module_keys' => Hub::MODULE_KEYS,
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
    public function update(Hub $hub, array $payload, ?Hub $actingWhiteLabel = null): array
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

        $shared = $this->sharedHub();
        if ($hub->isWhiteLabel()) {
            $tenantHub = $hub;
        } elseif ($actingWhiteLabel) {
            $tenantHub = $actingWhiteLabel;
        } else {
            $tenantHub = $shared;
        }

        $matrixInput = isset($payload['matrix']) && is_array($payload['matrix'])
            ? $payload['matrix']
            : [];

        $controlPlaneInput = array_intersect_key(
            $matrixInput,
            array_flip(ActingHubService::CONTROL_PLANE_ROLES)
        );
        $tenantInput = array_diff_key(
            $matrixInput,
            array_flip(ActingHubService::CONTROL_PLANE_ROLES)
        );

        // Hub switcher capability always lives on the shared hub.
        $switcherInput = [];
        foreach (ActingHubService::CONTROL_PLANE_ROLES as $role) {
            if (! isset($controlPlaneInput[$role]) || ! is_array($controlPlaneInput[$role])) {
                continue;
            }
            if (! array_key_exists(ActingHubService::CAPABILITY, $controlPlaneInput[$role])) {
                continue;
            }
            $switcherInput[$role] = [
                ActingHubService::CAPABILITY => $controlPlaneInput[$role][ActingHubService::CAPABILITY],
            ];
            unset($controlPlaneInput[$role][ActingHubService::CAPABILITY]);
            if ($controlPlaneInput[$role] === []) {
                unset($controlPlaneInput[$role]);
            }
        }
        if ($switcherInput !== []) {
            $this->applyRoleMatrix($shared, $switcherInput, ActingHubService::CONTROL_PLANE_ROLES);
        }

        if ($tenantHub->is($shared)) {
            $this->applyRoleMatrix($shared, $controlPlaneInput, ActingHubService::CONTROL_PLANE_ROLES);
            $this->applyRoleMatrix($shared, $tenantInput, $this->tenantRoles());
        } else {
            // Power Admin / FinProms hub tools are per white-label hub so the
            // shared dashboard navbar follows that hub while it is selected.
            $this->applyRoleMatrix($tenantHub, $controlPlaneInput, ActingHubService::CONTROL_PLANE_ROLES);
            $this->applyRoleMatrix($tenantHub, $tenantInput, $this->tenantRoles());
            if ($tenantHub->hasRemoteDatabaseConfigured()) {
                app(WhiteLabelHubSyncService::class)->pushSettings($tenantHub->fresh());
            }
        }

        app(HubService::class)->forgetCurrentCache();

        return $this->matrix($tenantHub->fresh() ?? $hub->fresh());
    }

    /**
     * @return list<string>
     */
    private function tenantRoles(): array
    {
        return array_values(array_filter(
            self::MATRIX_ROLES,
            fn (string $role) => ! ActingHubService::isControlPlaneRole($role)
        ));
    }

    private function sharedHub(): Hub
    {
        $current = app(HubService::class)->current();
        if ($current->isShared()) {
            return $current;
        }

        return Hub::query()->where('type', Hub::TYPE_SHARED)->first() ?? $current;
    }

    /**
     * @param  array<string, array<string, mixed>>  $matrixInput
     * @param  list<string>  $roles
     */
    private function applyRoleMatrix(Hub $hub, array $matrixInput, array $roles, bool $stripControlPlane = false): void
    {
        if ($matrixInput === [] && ! $stripControlPlane) {
            return;
        }

        $roleCaps = $this->resolvedRoleCapabilities($hub);

        foreach ($roles as $role) {
            if (! isset($matrixInput[$role]) || ! is_array($matrixInput[$role])) {
                continue;
            }
            foreach ($matrixInput[$role] as $key => $value) {
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

        if ($stripControlPlane) {
            foreach (ActingHubService::CONTROL_PLANE_ROLES as $role) {
                unset($roleCaps[$role]);
            }
        }

        $hub->role_capabilities = $roleCaps;

        $orRoles = $stripControlPlane ? $this->tenantRoles() : self::MATRIX_ROLES;
        $checklist = $hub->resolvedChecklist();
        foreach (Hub::CHECKLIST_DEFINITIONS as $key => $meta) {
            if (! Hub::isCapabilityKey($key)) {
                continue;
            }
            $any = false;
            foreach ($this->rolesForCapability($key) as $role) {
                if (! in_array($role, $orRoles, true)) {
                    continue;
                }
                if (! empty($roleCaps[$role][$key])) {
                    $any = true;
                    break;
                }
            }
            $checklist[$key] = $any;
        }
        $hub->checklist = $checklist;
        $hub->save();
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
            if (str_starts_with($key, 'member_') && $key !== 'member_browse_catalog') {
                $matrix[User::ROLE_APPROVER][$key] = false;
            }
        }

        // Catalog browse is on for every role by default (staff + users).
        foreach (self::MATRIX_ROLES as $role) {
            if (isset($matrix[$role])) {
                $matrix[$role]['member_browse_catalog'] = true;
            }
        }

        // Content management tools enabled for Power Admin by default.
        foreach ([
            'dashboard_manage_posts',
            'dashboard_manage_bundles',
            'dashboard_manage_types',
            'dashboard_manage_categories',
            'dashboard_manage_firms',
            'dashboard_manage_tags',
            'dashboard_manage_subscriber_credits',
            'dashboard_manage_modules',
            ActingHubService::CAPABILITY,
        ] as $key) {
            if (isset($matrix[User::ROLE_POWER_ADMIN])) {
                $matrix[User::ROLE_POWER_ADMIN][$key] = true;
            }
        }

        // Control white-label hubs is a shared-hub tool; default on for FinProms admin there.
        if (isset($matrix[User::ROLE_FINPROMS_ADMIN])) {
            $matrix[User::ROLE_FINPROMS_ADMIN][ActingHubService::CAPABILITY] =
                $hubType === Hub::TYPE_SHARED;
        }

        // Subscriber credits also default on for FinProms admin on white-label hubs.
        if (isset($matrix[User::ROLE_FINPROMS_ADMIN])) {
            $matrix[User::ROLE_FINPROMS_ADMIN]['dashboard_manage_subscriber_credits'] =
                $hubType === Hub::TYPE_WHITE_LABEL;
        }

        // Remaining roles (approver / advisor / user): dashboard & admin-email tools off by default.
        // Power Admin enables them per hub in the Capabilities matrix.
        foreach ([User::ROLE_APPROVER, User::ROLE_ADVISOR, User::ROLE_USER] as $role) {
            foreach (array_keys($matrix[$role] ?? []) as $key) {
                $meta = Hub::CHECKLIST_DEFINITIONS[$key] ?? null;
                $group = $meta['group'] ?? null;
                if ($group === Hub::GROUP_DASHBOARD || $group === Hub::GROUP_ADMIN_EMAILS) {
                    $matrix[$role][$key] = false;
                }
            }
        }

        // Social Media Compliance caps default OFF for every role until Power Admin enables them.
        foreach (self::MATRIX_ROLES as $role) {
            foreach (Hub::SOCIAL_MEDIA_COMPLIANCE_CAPABILITY_KEYS as $key) {
                if (isset($matrix[$role])) {
                    $matrix[$role][$key] = false;
                }
            }
        }

        // General Compliance caps default OFF for every role until Power Admin enables them.
        foreach (self::MATRIX_ROLES as $role) {
            foreach (Hub::GENERAL_COMPLIANCE_CAPABILITY_KEYS as $key) {
                if (isset($matrix[$role])) {
                    $matrix[$role][$key] = false;
                }
            }
        }

        return $matrix;
    }

    /**
     * Whether this role may open the hub-admin dashboard shell.
     * Traditional hub-admin roles always can; remaining roles only when at least
     * one dashboard capability is enabled for them on this hub.
     */
    public function roleHasHubDashboardAccess(Hub $hub, string $role): bool
    {
        if ($role === 'admin') {
            $role = User::ROLE_CLIENT_ADMIN;
        }

        if (in_array($role, User::HUB_ADMIN_ROLES, true)) {
            return true;
        }

        foreach (Hub::CHECKLIST_DEFINITIONS as $key => $meta) {
            $group = $meta['group'] ?? null;
            if ($group !== Hub::GROUP_DASHBOARD
                && $group !== Hub::GROUP_SOCIAL_MEDIA_COMPLIANCE
                && $group !== Hub::GROUP_GENERAL_COMPLIANCE
                && $group !== Hub::GROUP_WEBSITE_COMPLIANCE
            ) {
                continue;
            }
            if ($this->roleCan($hub, $role, $key)) {
                return true;
            }
        }

        return false;
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

            // Remaining roles must not inherit hub-wide dashboard / admin-email OR flags.
            foreach ([User::ROLE_APPROVER, User::ROLE_ADVISOR, User::ROLE_USER] as $role) {
                foreach (Hub::CHECKLIST_DEFINITIONS as $key => $meta) {
                    $group = $meta['group'] ?? null;
                    if ($group === Hub::GROUP_DASHBOARD || $group === Hub::GROUP_ADMIN_EMAILS) {
                        $defaults[$role][$key] = false;
                    }
                }
            }

            // Social Media Compliance never inherits from hub OR checklist on bootstrap.
            foreach (self::MATRIX_ROLES as $role) {
                foreach (Hub::SOCIAL_MEDIA_COMPLIANCE_CAPABILITY_KEYS as $key) {
                    $defaults[$role][$key] = false;
                }
            }

            // General Compliance never inherits from hub OR checklist on bootstrap.
            foreach (self::MATRIX_ROLES as $role) {
                foreach (Hub::GENERAL_COMPLIANCE_CAPABILITY_KEYS as $key) {
                    $defaults[$role][$key] = false;
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

        // Social Media Compliance caps are inactive while the module is off.
        if (Hub::isSocialMediaComplianceCapability($flag) && ! $hub->hasSocialMediaComplianceModule()) {
            return false;
        }

        // General Compliance caps are inactive while the module is off.
        if (Hub::isGeneralComplianceCapability($flag) && ! $hub->hasGeneralComplianceModule()) {
            return false;
        }

        // Website Compliance caps are inactive while the module is off.
        if (Hub::isWebsiteComplianceCapability($flag) && ! $hub->hasWebsiteComplianceModule()) {
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
