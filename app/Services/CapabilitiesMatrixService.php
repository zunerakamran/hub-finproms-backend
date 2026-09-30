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
        User::ROLE_ADMIN_STAFF,
        User::ROLE_USER,
    ];

    public function __construct(
        private readonly PowerAdminCapabilitiesService $powerCapabilities
    ) {}

    /**
     * Catalog + custom role keys (lazy to avoid ctor cycles with HubRolesService).
     *
     * @return list<string>
     */
    public function allMatrixRoles(): array
    {
        try {
            return app(HubRolesService::class)->allKnownRoles();
        } catch (\Throwable) {
            return self::MATRIX_ROLES;
        }
    }

    /**
     * Role columns for the matrix UI on this hub (present users ∪ added-to-all).
     *
     * @return list<string>
     */
    public function visibleRoles(Hub $hub): array
    {
        try {
            return app(HubRolesService::class)->visibleMatrixRoles($hub);
        } catch (\Throwable) {
            return self::MATRIX_ROLES;
        }
    }

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

        if (Hub::isDashboardCapabilityGroup($group) || $group === Hub::GROUP_ADMIN_EMAILS) {
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
            return $this->allMatrixRoles();
        }

        if ($group === Hub::GROUP_MEMBER
            || $group === Hub::GROUP_GENERAL
            || $group === Hub::GROUP_SOCIAL_MEDIA_COMPLIANCE
            || $group === Hub::GROUP_GENERAL_COMPLIANCE
            || $group === Hub::GROUP_WEBSITE_TEMPLATE_LIBRARY
            || $group === Hub::GROUP_WEBSITE_COMPLIANCE
            || $group === Hub::GROUP_MODULE_PRICING
        ) {
            // User-facing / compliance caps apply to every role column so Power Admin
            // can enable them for staff and users alike (no hard role lock-in).
            return $this->allMatrixRoles();
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
        $controlPlane = $this->controlPlaneHub();
        $controlPlaneRoleCaps = $this->resolvedRoleCapabilities($controlPlane);
        $privateMode = $hub->isPrivateInviteOnly();
        $publicMode = $hub->isPublicSubscribe();

        $visibleRoles = $this->visibleRoles($hub);
        $hubRoles = app(HubRolesService::class);
        $rolesMeta = $hubRoles->matrixRolesPayload($hub);
        $roles = $rolesMeta['roles'];

        // Functionalities live on the Hub checklist screen — not repeated here.
        $behaviour = [];

        $rows = [];
        $paIndex = 0;

        // Power Admin platform rows
        foreach (PowerAdminCapabilitiesService::DEFINITIONS as $key => $meta) {
            $cells = [];
            foreach ($visibleRoles as $role) {
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
                'group_sort' => -1,
                'row_sort' => $paIndex++,
                'requires_private' => false,
                'inactive' => false,
                'cells' => $cells,
            ];
        }

        $smcModuleOn = $hub->hasSocialMediaComplianceModule();
        $gcModuleOn = $hub->hasGeneralComplianceModule();
        $wcModuleOn = $hub->hasWebsiteComplianceModule();
        $wtlModuleOn = $hub->hasWebsiteTemplateLibraryModule();
        $smtlModuleOn = $hub->hasSocialMediaTemplateLibraryModule();
        $modulePricingOn = $hub->can('charge_amount_per_module');
        // Central has no product modules — do not grey-out hub-ops tools that are
        // used for remote control / registry (modules page, firms, switcher, etc.).
        $isCentralHub = $hub->isCentral();

        $groupSort = [];
        foreach (Hub::MATRIX_GROUP_ORDER as $index => $groupKey) {
            $groupSort[$groupKey] = $index;
        }
        // Legacy bucket, if any rows still use it, sits after hub ops / before admin emails.
        $groupSort[Hub::GROUP_DASHBOARD] = count(Hub::MATRIX_GROUP_ORDER);

        $rowIndex = 0;
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
            $requiresWtlModule = Hub::isWebsiteTemplateLibraryCapability($key);
            $requiresSmtlModule = Hub::isSocialMediaTemplateLibraryCapability($key);
            $requiresModulePricing = Hub::isModulePricingCapability($key);

            // Central: product-module sections (SMC/GC/WC/WTL) stay inactive while
            // those modules are off. SMTL-gated member + dashboard caps stay
            // editable — Central has no SMTL product module, but Power Admin may
            // still enable a public site shell and content tools via the matrix
            // (enforced in roleCan the same way Shared/WL honour checked cells).
            $inactive = false;
            if ($isCentralHub) {
                if ($requiresSmcModule
                    || $requiresGcModule
                    || $requiresWcModule
                    || $requiresWtlModule
                    || ($requiresModulePricing && ! $modulePricingOn)
                ) {
                    $inactive = true;
                }
            } else {
                $inactive = ($requiresPrivate && $publicMode)
                    || ($requiresPublic && $privateMode)
                    || ($requiresSmcModule && ! $smcModuleOn)
                    || ($requiresGcModule && ! $gcModuleOn)
                    || ($requiresWcModule && ! $wcModuleOn)
                    || ($requiresWtlModule && ! $wtlModuleOn)
                    || ($requiresSmtlModule && ! $smtlModuleOn)
                    || ($requiresModulePricing && ! $modulePricingOn);
            }

            $inactiveReason = null;
            if ($inactive) {
                if ($requiresSmcModule && ! $smcModuleOn) {
                    $inactiveReason = 'module_social_media_compliance_off';
                } elseif ($requiresGcModule && ! $gcModuleOn) {
                    $inactiveReason = 'module_general_compliance_off';
                } elseif ($requiresWtlModule && ! $wtlModuleOn) {
                    $inactiveReason = 'module_website_template_library_off';
                } elseif ($requiresWcModule && ! $wcModuleOn) {
                    $inactiveReason = 'module_website_compliance_off';
                } elseif ($requiresModulePricing && ! $modulePricingOn) {
                    $inactiveReason = 'charge_amount_per_module_off';
                } elseif ($requiresSmtlModule && ! $smtlModuleOn) {
                    $inactiveReason = 'module_social_media_template_library_off';
                } elseif ($requiresPrivate) {
                    $inactiveReason = 'private_only';
                } else {
                    $inactiveReason = 'public_only';
                }
            }

            $applicableRoles = $this->rolesForCapability($key);
            $cells = [];
            foreach ($visibleRoles as $role) {
                $applicable = in_array($role, $applicableRoles, true);
                $enabled = false;
                if ($applicable) {
                    // Hub switcher flag always reflects the control-plane (Central) matrix.
                    if ($key === ActingHubService::CAPABILITY) {
                        $enabled = (bool) ($controlPlaneRoleCaps[$role][$key] ?? false);
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
            if ($requiresSmtlModule) {
                $requiresModule = 'module_social_media_template_library';
            } elseif ($requiresSmcModule) {
                $requiresModule = 'module_social_media_compliance';
            } elseif ($requiresGcModule) {
                $requiresModule = 'module_general_compliance';
            } elseif ($requiresWtlModule) {
                $requiresModule = 'module_website_template_library';
            } elseif ($requiresWcModule) {
                $requiresModule = 'module_website_compliance';
            } elseif ($requiresModulePricing) {
                $requiresModule = 'charge_amount_per_module';
            }

            $rows[] = [
                'key' => $key,
                'label' => $meta['label'],
                'description' => $meta['description'],
                'group' => $group,
                'group_label' => Hub::CHECKLIST_GROUPS[$group] ?? $group,
                'group_sort' => $groupSort[$group] ?? 999,
                'row_sort' => $rowIndex++,
                'requires_private' => $requiresPrivate,
                'requires_public' => $requiresPublic,
                'requires_module' => $requiresModule,
                'inactive' => $inactive,
                'inactive_reason' => $inactiveReason,
                'cells' => $cells,
            ];
        }

        usort($rows, function (array $a, array $b): int {
            $sortA = (int) ($a['group_sort'] ?? 999);
            $sortB = (int) ($b['group_sort'] ?? 999);
            if ($sortA !== $sortB) {
                return $sortA <=> $sortB;
            }

            return ((int) ($a['row_sort'] ?? 0)) <=> ((int) ($b['row_sort'] ?? 0));
        });

        foreach ($rows as &$row) {
            unset($row['row_sort']);
        }
        unset($row);

        $groupOrder = array_values(array_unique(array_map(
            fn (array $row) => (string) $row['group'],
            $rows
        )));

        return [
            'hub' => [
                'id' => $hub->id,
                'name' => $hub->name,
                'slug' => $hub->slug,
                'type' => $hub->type,
                'is_central' => $hub->isCentral(),
                'is_control_plane' => $hub->isControlPlane(),
                'private_invite_only' => $privateMode,
                'public_subscribe' => $publicMode,
                'module_central_hub' => $hub->isCentral(),
                'module_white_label_hub' => $hub->hasWhiteLabelHubModule(),
                'module_shared_hub' => $hub->hasSharedHubModule(),
                'module_social_media_template_library' => $smtlModuleOn,
                'module_social_media_compliance' => $smcModuleOn,
                'module_website_template_library' => $wtlModuleOn,
                'module_website_compliance' => $wcModuleOn,
                'module_general_compliance' => $gcModuleOn,
                'charge_amount_per_module' => $modulePricingOn,
            ],
            'control_plane_roles' => ActingHubService::CONTROL_PLANE_ROLES,
            'control_plane_hub' => [
                'id' => $controlPlane->id,
                'slug' => $controlPlane->slug,
                'type' => $controlPlane->type,
            ],
            'private_capability_keys' => Hub::PRIVATE_CAPABILITY_KEYS,
            'public_capability_keys' => Hub::PUBLIC_CAPABILITY_KEYS,
            'group_order' => $groupOrder,
            'social_media_template_library_capability_keys' => Hub::SOCIAL_MEDIA_TEMPLATE_LIBRARY_CAPABILITY_KEYS,
            'social_media_compliance_capability_keys' => Hub::SOCIAL_MEDIA_COMPLIANCE_CAPABILITY_KEYS,
            'general_compliance_capability_keys' => Hub::GENERAL_COMPLIANCE_CAPABILITY_KEYS,
            'website_template_library_capability_keys' => Hub::WEBSITE_TEMPLATE_LIBRARY_CAPABILITY_KEYS,
            'website_compliance_capability_keys' => Hub::WEBSITE_COMPLIANCE_CAPABILITY_KEYS,
            'module_pricing_capability_keys' => Hub::MODULE_PRICING_CAPABILITY_KEYS,
            'module_keys' => Hub::MODULE_KEYS,
            'roles' => $roles,
            'available_to_add' => $rolesMeta['available_to_add'],
            'custom_roles' => $rolesMeta['custom_roles'],
            'added_to_all_hubs' => $rolesMeta['added_to_all_hubs'],
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
        // $actingWhiteLabel retained for call-site compatibility; matrix always
        // saves onto $hub (the selected registry row).
        unset($actingWhiteLabel);

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

        $controlPlane = $this->controlPlaneHub();
        // Always persist the matrix onto the hub being edited (Central, Shared, or WL).
        // Previously Shared edits were redirected onto the control-plane hub after
        // Central was introduced, so enabled cells never appeared on the right hub.
        $tenantHub = $hub;

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

        // Hub switcher capability always lives on the Central / control-plane hub.
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
            $this->applyRoleMatrix($controlPlane, $switcherInput, ActingHubService::CONTROL_PLANE_ROLES);
        }

        if ($tenantHub->isControlPlane() || (int) $tenantHub->id === (int) $controlPlane->id) {
            $this->applyRoleMatrix($controlPlane, $controlPlaneInput, ActingHubService::CONTROL_PLANE_ROLES);
            $this->applyRoleMatrix($controlPlane, $tenantInput, $this->tenantRoles());
        } else {
            // Power Admin / FinProms hub tools are per content hub so the
            // dashboard navbar follows that hub while it is selected.
            $this->applyRoleMatrix($tenantHub, $controlPlaneInput, ActingHubService::CONTROL_PLANE_ROLES);
            $this->applyRoleMatrix($tenantHub, $tenantInput, $this->tenantRoles());
            if ($tenantHub->isContentHub() && $tenantHub->hasRemoteDatabaseConfigured()) {
                try {
                    app(WhiteLabelHubSyncService::class)->pushSettings($tenantHub->fresh());
                } catch (\Throwable $e) {
                    report($e);
                }
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
            $this->allMatrixRoles(),
            fn (string $role) => ! ActingHubService::isControlPlaneRole($role)
        ));
    }

    private function controlPlaneHub(): Hub
    {
        $current = app(HubService::class)->current();
        if ($current->isControlPlane()) {
            return $current;
        }

        return Hub::query()->where('type', Hub::TYPE_CENTRAL)->first()
            ?? Hub::query()->where('type', Hub::TYPE_SHARED)->where('slug', 'shared')->first()
            ?? Hub::query()->where('type', Hub::TYPE_SHARED)->first()
            ?? $current;
    }

    /**
     * @deprecated Use controlPlaneHub() — kept for any lingering call sites.
     */
    private function sharedHub(): Hub
    {
        return $this->controlPlaneHub();
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

        // Central: turning on “View website pages” also unlocks catalog/plans nav
        // for that role (unless this same save explicitly turned those off).
        if ($hub->isCentral()) {
            foreach ($roles as $role) {
                if (empty($roleCaps[$role]['member_view_site_pages'])) {
                    continue;
                }
                $inputForRole = (isset($matrixInput[$role]) && is_array($matrixInput[$role]))
                    ? $matrixInput[$role]
                    : [];
                foreach (['member_browse_catalog', 'member_view_plans'] as $companion) {
                    if (array_key_exists($companion, $inputForRole)
                        && ! filter_var($inputForRole[$companion], FILTER_VALIDATE_BOOLEAN)
                    ) {
                        continue;
                    }
                    $roleCaps[$role][$companion] = true;
                }
            }
        }

        if ($stripControlPlane) {
            foreach (ActingHubService::CONTROL_PLANE_ROLES as $role) {
                unset($roleCaps[$role]);
            }
        }

        $hub->role_capabilities = $roleCaps;

        $orRoles = $stripControlPlane ? $this->tenantRoles() : $this->allMatrixRoles();
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

        foreach ($this->allMatrixRoles() as $role) {
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
            if (
                str_starts_with($key, 'member_')
                && $key !== 'member_browse_catalog'
                && $key !== 'member_view_site_pages'
            ) {
                $matrix[User::ROLE_APPROVER][$key] = false;
            }
        }

        // Catalog browse is on for every role by default (staff + users) on content hubs.
        foreach ($this->allMatrixRoles() as $role) {
            if (isset($matrix[$role])) {
                $matrix[$role]['member_browse_catalog'] = true;
                $matrix[$role]['member_view_site_pages'] = true;
            }
        }

        // Central Hub Controller has no member website — keep site-page access off for every role.
        if ($hubType === Hub::TYPE_CENTRAL) {
            foreach ($this->allMatrixRoles() as $role) {
                if (! isset($matrix[$role])) {
                    continue;
                }
                $matrix[$role]['member_view_site_pages'] = false;
                $matrix[$role]['member_browse_catalog'] = false;
                $matrix[$role]['member_view_plans'] = false;
                $matrix[$role]['member_purchase_content'] = false;
                $matrix[$role]['member_download_content'] = false;
                $matrix[$role]['member_in_app_edit'] = false;
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
            'dashboard_manage_module_pricing',
            'dashboard_view_module_invoices',
            'dashboard_mark_module_invoices_paid',
            ActingHubService::CAPABILITY,
        ] as $key) {
            if (isset($matrix[User::ROLE_POWER_ADMIN])) {
                $matrix[User::ROLE_POWER_ADMIN][$key] = true;
            }
        }

        // Control hubs remotely is a Central Hub tool only.
        if (isset($matrix[User::ROLE_FINPROMS_ADMIN])) {
            $matrix[User::ROLE_FINPROMS_ADMIN][ActingHubService::CAPABILITY] =
                $hubType === Hub::TYPE_CENTRAL;
        }

        // Subscriber credits also default on for FinProms admin on white-labelled hubs.
        if (isset($matrix[User::ROLE_FINPROMS_ADMIN])) {
            $matrix[User::ROLE_FINPROMS_ADMIN]['dashboard_manage_subscriber_credits'] =
                $hubType === Hub::TYPE_WHITE_LABEL;
        }

        // Remaining roles (approver / advisor / admin-staff / user): dashboard & admin-email tools off by default.
        // Power Admin enables them per hub in the Capabilities matrix.
        foreach ([User::ROLE_APPROVER, User::ROLE_ADVISOR, User::ROLE_ADMIN_STAFF, User::ROLE_USER] as $role) {
            foreach (array_keys($matrix[$role] ?? []) as $key) {
                $meta = Hub::CHECKLIST_DEFINITIONS[$key] ?? null;
                $group = $meta['group'] ?? null;
                if (Hub::isDashboardCapabilityGroup($group)
                    || $group === Hub::GROUP_ADMIN_EMAILS
                    || $group === Hub::GROUP_MODULE_PRICING
                ) {
                    $matrix[$role][$key] = false;
                }
            }
        }

        // Social Media Compliance caps default OFF for every role until Power Admin enables them.
        foreach ($this->allMatrixRoles() as $role) {
            foreach (Hub::SOCIAL_MEDIA_COMPLIANCE_CAPABILITY_KEYS as $key) {
                if (isset($matrix[$role])) {
                    $matrix[$role][$key] = false;
                }
            }
        }

        // General Compliance caps default OFF for every role until Power Admin enables them.
        foreach ($this->allMatrixRoles() as $role) {
            foreach (Hub::GENERAL_COMPLIANCE_CAPABILITY_KEYS as $key) {
                if (isset($matrix[$role])) {
                    $matrix[$role][$key] = false;
                }
            }
        }

        // Change-status + review defaults ON for managers (firm-scoped in services).
        // Managers get the same review actions as Approvers, plus hub-wide queue tools.
        foreach ([
            'smc_change_request_status',
            'gc_change_request_status',
            'wc_change_request_status',
            'wc_review_change_requests',
            'wc_assign_change_requests',
            'wc_view_all_change_requests',
        ] as $key) {
            if (isset($matrix[User::ROLE_MANAGER][$key])) {
                $matrix[User::ROLE_MANAGER][$key] = true;
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
            if (! Hub::isDashboardCapabilityGroup($group)
                && $group !== Hub::GROUP_SOCIAL_MEDIA_COMPLIANCE
                && $group !== Hub::GROUP_GENERAL_COMPLIANCE
                && $group !== Hub::GROUP_WEBSITE_COMPLIANCE
                && $key !== 'dashboard_manage_email_templates'
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

        // Empty role_capabilities: use typed defaults (do NOT overwrite from the
        // hub checklist OR-flags — that wiped Power Admin defaults on Central).
        if ($stored === []) {
            return $defaults;
        }

        foreach ($defaults as $role => $caps) {
            foreach ($caps as $key => $default) {
                if (isset($stored[$role]) && is_array($stored[$role]) && array_key_exists($key, $stored[$role])) {
                    $defaults[$role][$key] = filter_var($stored[$role][$key], FILTER_VALIDATE_BOOLEAN);
                }
            }
        }

        // Ensure Central always keeps control-plane switcher defaults when missing
        // from a partially-seeded role_capabilities blob (e.g. member-caps migrations).
        if ($hub->isCentral() || $hub->isControlPlane()) {
            $fresh = $this->defaultRoleCapabilities(Hub::TYPE_CENTRAL);
            foreach (ActingHubService::CONTROL_PLANE_ROLES as $role) {
                if (! isset($defaults[$role][ActingHubService::CAPABILITY])
                    || (
                        ! isset($stored[$role])
                        || ! is_array($stored[$role])
                        || ! array_key_exists(ActingHubService::CAPABILITY, $stored[$role])
                    )
                ) {
                    $defaults[$role][ActingHubService::CAPABILITY] =
                        (bool) ($fresh[$role][ActingHubService::CAPABILITY] ?? ($role === User::ROLE_POWER_ADMIN));
                }
            }
        }

        return $defaults;
    }

    /**
     * Matrix role for this user, honouring Admin-staff "act as advisor".
     */
    public function effectiveRoleFor(User $user): string
    {
        return app(ActingAdvisorService::class)->effectiveCapabilityRole($user);
    }

    /**
     * Whether this user currently has a capability (Admin-staff may mirror advisor).
     * Also enforces per-user module allow-lists when a flag requires a module.
     */
    public function userCan(Hub $hub, User $user, string $flag): bool
    {
        if (! $this->roleCan($hub, $this->effectiveRoleFor($user), $flag)) {
            return false;
        }

        return $this->userPassesModuleGate($hub, $user, $flag);
    }

    /**
     * Control-plane roles are never module-restricted per user.
     * Module flags and module-scoped caps/functionalities require user allow-list access.
     * When Admin-staff acts as an advisor, the advisor's module allow-list applies.
     */
    private function userPassesModuleGate(Hub $hub, User $user, string $flag): bool
    {
        if (ActingHubService::isControlPlaneRole((string) $user->role)) {
            return true;
        }

        $required = $this->requiredModuleForFlag($flag);
        if ($required === null) {
            return true;
        }

        $subject = app(ActingAdvisorService::class)->requireSubject($user);

        return $subject->hasModuleAccess($hub, $required);
    }

    /**
     * Module key a capability / functionality / module flag depends on, if any.
     */
    private function requiredModuleForFlag(string $flag): ?string
    {
        if (Hub::isModuleKey($flag)) {
            return $flag;
        }

        if (Hub::isSocialMediaTemplateLibraryCapability($flag)
            || Hub::isSocialMediaTemplateLibraryFunctionality($flag)
        ) {
            return 'module_social_media_template_library';
        }

        if (Hub::isSocialMediaComplianceCapability($flag)) {
            return 'module_social_media_compliance';
        }

        if (Hub::isGeneralComplianceCapability($flag)) {
            return 'module_general_compliance';
        }

        if (Hub::isWebsiteTemplateLibraryCapability($flag)) {
            return 'module_website_template_library';
        }

        if (Hub::isWebsiteComplianceCapability($flag)) {
            return 'module_website_compliance';
        }

        return null;
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

        // White-labelled hub tools are inactive while the hub is shared.
        if (Hub::isPrivateCapability($flag) && ! $hub->isPrivateInviteOnly()) {
            return false;
        }

        // Shared-hub tools are inactive while the hub is white-labelled invite-only.
        if (Hub::isPublicCapability($flag) && $hub->isPrivateInviteOnly()) {
            return false;
        }

        // Social Media Template Library caps are inactive while the module is off.
        // Exception: Central has no SMTL product module (control plane), but the
        // Capabilities matrix still lets Power Admin enable these cells — member
        // site shell (browse/plans/…) and dashboard content tools (posts/types/…).
        // Honor checked cells the same way Shared / White-label do when SMTL is on.
        if (Hub::isSocialMediaTemplateLibraryCapability($flag) && ! $hub->hasSocialMediaTemplateLibraryModule()) {
            if (! $hub->isCentral()) {
                return false;
            }
        }

        // Social Media Pre Approval caps are inactive while the module is off.
        if (Hub::isSocialMediaComplianceCapability($flag) && ! $hub->hasSocialMediaComplianceModule()) {
            return false;
        }

        // Generic Content Pre Approval caps are inactive while the module is off.
        if (Hub::isGeneralComplianceCapability($flag) && ! $hub->hasGeneralComplianceModule()) {
            return false;
        }

        // Website Template Library caps are inactive while the module is off.
        if (Hub::isWebsiteTemplateLibraryCapability($flag) && ! $hub->hasWebsiteTemplateLibraryModule()) {
            return false;
        }

        // Website Content Pre Approval caps are inactive while the module is off.
        if (Hub::isWebsiteComplianceCapability($flag) && ! $hub->hasWebsiteComplianceModule()) {
            return false;
        }

        // Modules pricing caps are inactive while the functionality is off.
        if (Hub::isModulePricingCapability($flag) && ! $hub->can('charge_amount_per_module')) {
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
