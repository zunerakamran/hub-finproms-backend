<?php

namespace App\Services;

use App\Models\Hub;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Throwable;

/**
 * Which role columns appear in the Capabilities matrix, and how new roles are
 * added for the hub currently being edited.
 *
 * Visibility = roles that currently have users on that hub
 *            ∪ roles Power Admin explicitly added for that hub
 *            ∪ (Power Admin on Central, so platform pa_* cells stay editable).
 *
 * Custom role definitions (key + default label) are stored platform-wide so
 * rename / assign work; enabling a role on the matrix is always per hub.
 */
class HubRolesService
{
    /** @deprecated Legacy global list — still read for backward compatibility. */
    public const SETTING_ADDED_ROLES = 'hub_roles_added_to_all';

    /** Per-hub map: { "hubId": ["role_key", ...] } */
    public const SETTING_ADDED_BY_HUB = 'hub_roles_added_by_hub';

    public const SETTING_CUSTOM_ROLES = 'hub_roles_custom';

    /**
     * Built-in role keys (catalog). New custom roles are additions, not replacements.
     *
     * @var list<string>
     */
    public const CATALOG_ROLES = CapabilitiesMatrixService::MATRIX_ROLES;

    /** @var array<int, array<string, int>> */
    private static array $userCountsCache = [];

    /** @var array<int, list<string>> */
    private static array $visibleMatrixRolesCache = [];

    public function __construct(
        private readonly HubService $hubs,
        private readonly WhiteLabelDatabaseService $remoteDb
    ) {}

    /**
     * All known role keys (catalog + custom), stable catalog-first order.
     *
     * @return list<string>
     */
    public function allKnownRoles(): array
    {
        $roles = self::CATALOG_ROLES;
        foreach ($this->customRoles() as $custom) {
            $key = (string) ($custom['key'] ?? '');
            if ($key !== '' && ! in_array($key, $roles, true)) {
                $roles[] = $key;
            }
        }

        return $roles;
    }

    /**
     * @return list<array{key: string, label: string, is_custom: bool}>
     */
    public function customRoles(): array
    {
        $raw = Setting::getValue(self::SETTING_CUSTOM_ROLES, null);
        if (! is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach ($decoded as $row) {
            if (! is_array($row)) {
                continue;
            }
            $key = $this->normalizeRoleKey((string) ($row['key'] ?? ''));
            if ($key === '' || in_array($key, self::CATALOG_ROLES, true)) {
                continue;
            }
            $label = trim((string) ($row['label'] ?? ''));
            $out[] = [
                'key' => $key,
                'label' => $label !== '' ? $label : $this->humanizeKey($key),
                'is_custom' => true,
            ];
        }

        return $out;
    }

    /**
     * Roles Power Admin has explicitly enabled on this hub (even with no users yet).
     *
     * @return list<string>
     */
    public function rolesAddedForHub(Hub $hub): array
    {
        $known = $this->allKnownRoles();
        $out = [];

        $map = $this->addedRolesByHubMap();
        $hubKey = (string) $hub->id;
        $list = $map[$hubKey] ?? $map[(string) (int) $hub->id] ?? [];
        if (is_array($list)) {
            foreach ($list as $role) {
                $role = $this->normalizeRoleKey((string) $role);
                if ($role !== '' && in_array($role, $known, true) && ! in_array($role, $out, true)) {
                    $out[] = $role;
                }
            }
        }

        // Legacy “added to all hubs” list still counts for every hub.
        foreach ($this->legacyRolesAddedToAllHubs() as $role) {
            if (! in_array($role, $out, true)) {
                $out[] = $role;
            }
        }

        return $out;
    }

    /**
     * @deprecated Use rolesAddedForHub()
     *
     * @return list<string>
     */
    public function rolesAddedToAllHubs(): array
    {
        return $this->legacyRolesAddedToAllHubs();
    }

    /**
     * @return list<string>
     */
    private function legacyRolesAddedToAllHubs(): array
    {
        $raw = Setting::getValue(self::SETTING_ADDED_ROLES, null);
        if (! is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return [];
        }

        $known = $this->allKnownRoles();
        $out = [];
        foreach ($decoded as $role) {
            $role = $this->normalizeRoleKey((string) $role);
            if ($role !== '' && in_array($role, $known, true) && ! in_array($role, $out, true)) {
                $out[] = $role;
            }
        }

        return $out;
    }

    /**
     * @return array<string, list<string>>
     */
    private function addedRolesByHubMap(): array
    {
        $raw = Setting::getValue(self::SETTING_ADDED_BY_HUB, null);
        if (! is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  list<string>  $roles
     */
    private function saveAddedRolesForHub(Hub $hub, array $roles): void
    {
        $map = $this->addedRolesByHubMap();
        $map[(string) $hub->id] = array_values($roles);
        Setting::setValue(self::SETTING_ADDED_BY_HUB, json_encode($map));
    }

    /**
     * Default display label for a role key (before per-hub rename overrides).
     */
    public function defaultLabel(string $role): string
    {
        if (isset(User::ROLE_LABELS[$role])) {
            return User::ROLE_LABELS[$role];
        }

        foreach ($this->customRoles() as $custom) {
            if ($custom['key'] === $role) {
                return $custom['label'];
            }
        }

        return $this->humanizeKey($role);
    }

    /**
     * Distinct role values currently used by users on this hub.
     *
     * @return list<string>
     */
    public function rolesPresentOnHub(Hub $hub): array
    {
        return array_keys(array_filter(
            $this->userCountsByRole($hub),
            fn (int $count) => $count > 0
        ));
    }

    /**
     * How many users currently hold each known role on this hub.
     *
     * @return array<string, int>
     */
    public function userCountsByRole(Hub $hub): array
    {
        $hubId = (int) $hub->id;
        if (isset(self::$userCountsCache[$hubId])) {
            return self::$userCountsCache[$hubId];
        }

        $known = $this->allKnownRoles();
        $counts = array_fill_keys($known, 0);

        try {
            $rows = collect();
            $current = $this->hubs->current();
            if ((int) $hub->id === (int) $current->id || $hub->isControlPlane()) {
                $rows = User::query()
                    ->whereNotNull('role')
                    ->where('role', '!=', '')
                    ->select('role', DB::raw('COUNT(*) as user_count'))
                    ->groupBy('role')
                    ->get();
            } elseif ($hub->isContentHub() && $hub->hasRemoteDatabaseConfigured()) {
                $connection = $this->remoteDb->connect($hub);
                if (Schema::connection($connection)->hasTable('users')) {
                    $rows = DB::connection($connection)
                        ->table('users')
                        ->whereNotNull('role')
                        ->where('role', '!=', '')
                        ->select('role', DB::raw('COUNT(*) as user_count'))
                        ->groupBy('role')
                        ->get();
                }
            }

            foreach ($rows as $row) {
                $role = $this->normalizeRoleKey((string) ($row->role ?? ''));
                if ($role === 'admin') {
                    $role = User::ROLE_CLIENT_ADMIN;
                }
                if ($role === '' || ! in_array($role, $known, true)) {
                    continue;
                }
                $counts[$role] = ($counts[$role] ?? 0) + (int) ($row->user_count ?? 0);
            }
        } catch (Throwable) {
            // Leave zeros on failure so Manage roles still loads.
        }

        return self::$userCountsCache[$hubId] = $counts;
    }

    /**
     * Role columns shown in the Capabilities matrix for this hub.
     *
     * @return list<string>
     */
    public function visibleMatrixRoles(Hub $hub): array
    {
        $hubId = (int) $hub->id;
        if (isset(self::$visibleMatrixRolesCache[$hubId])) {
            return self::$visibleMatrixRolesCache[$hubId];
        }

        $visible = array_values(array_unique(array_merge(
            $this->rolesPresentOnHub($hub),
            $this->rolesAddedForHub($hub)
        )));

        // Central always keeps the Power Admin column (platform pa_* checklist).
        if ($hub->isControlPlane() && ! in_array(User::ROLE_POWER_ADMIN, $visible, true)) {
            $visible[] = User::ROLE_POWER_ADMIN;
        }

        // White-labelled hubs never show control-plane-only role columns.
        if ($hub->isWhiteLabel()) {
            $visible = array_values(array_filter(
                $visible,
                fn (string $role) => ! ActingHubService::isControlPlaneRole($role)
            ));
        }

        return self::$visibleMatrixRolesCache[$hubId] = $this->orderRoles($visible);
    }

    /**
     * Catalog roles not yet visible on this hub (can still be added here).
     *
     * @return list<array{key: string, label: string, is_custom: bool}>
     */
    public function rolesAvailableToAdd(Hub $hub): array
    {
        $visible = $this->visibleMatrixRoles($hub);
        $out = [];
        foreach (self::CATALOG_ROLES as $role) {
            if ($hub->isWhiteLabel() && ActingHubService::isControlPlaneRole($role)) {
                continue;
            }
            if (in_array($role, $visible, true)) {
                continue;
            }
            $out[] = [
                'key' => $role,
                'label' => $this->defaultLabel($role),
                'is_custom' => false,
            ];
        }

        // Existing custom definitions not yet on this hub.
        foreach ($this->customRoles() as $custom) {
            $key = $custom['key'];
            if (in_array($key, $visible, true)) {
                continue;
            }
            if ($hub->isWhiteLabel() && ActingHubService::isControlPlaneRole($key)) {
                continue;
            }
            $out[] = [
                'key' => $key,
                'label' => $custom['label'],
                'is_custom' => true,
            ];
        }

        return $out;
    }

    /**
     * Payload for the Capabilities page (visible + addable + custom).
     *
     * @return array<string, mixed>
     */
    public function matrixRolesPayload(Hub $hub): array
    {
        $visible = $this->visibleMatrixRoles($hub);
        $counts = $this->userCountsByRole($hub);
        $added = $this->rolesAddedForHub($hub);

        $roles = [];
        foreach ($visible as $role) {
            $userCount = (int) ($counts[$role] ?? 0);
            $roles[] = [
                'key' => $role,
                'label' => $hub->roleLabel($role),
                'default_label' => $this->defaultLabel($role),
                'is_custom' => ! in_array($role, self::CATALOG_ROLES, true),
                'present_on_hub' => $userCount > 0,
                'added_to_hub' => in_array($role, $added, true),
                // Legacy alias for older frontends.
                'added_to_all_hubs' => in_array($role, $added, true),
                'user_count' => $userCount,
                // Role is already visible here — only block when users remain or Central PA.
                'can_delete' => $userCount === 0
                    && ! ($hub->isControlPlane() && $role === User::ROLE_POWER_ADMIN),
            ];
        }

        return [
            'roles' => $roles,
            'available_to_add' => $this->rolesAvailableToAdd($hub),
            'custom_roles' => $this->customRoles(),
            'added_to_hub' => $added,
            'added_to_all_hubs' => $added,
        ];
    }

    /**
     * Enable an existing catalog/custom role on this hub, or create a new custom role here.
     *
     * @return array<string, mixed>
     */
    public function addRoleToHub(Hub $hub, ?string $key = null, ?string $label = null): array
    {
        if ($hub->isWhiteLabel() && $key !== null && $key !== '') {
            $normalized = $this->normalizeRoleKey($key);
            if (ActingHubService::isControlPlaneRole($normalized)) {
                throw new InvalidArgumentException('Control-plane roles cannot be added on a white-labelled hub.');
            }
        }

        $key = $this->normalizeRoleKey((string) $key);
        $label = trim((string) $label);

        if ($key === '') {
            if ($label === '') {
                throw new InvalidArgumentException('Provide a role key or a display name for the new role.');
            }
            $key = $this->normalizeRoleKey($label);
            if ($key === '') {
                throw new InvalidArgumentException('Could not derive a role key from that display name.');
            }
        }

        if (! preg_match('/^[a-z][a-z0-9_]{1,40}$/', $key)) {
            throw new InvalidArgumentException(
                'Role key must be 2–41 characters: lowercase letters, numbers, underscores; start with a letter.'
            );
        }

        $reserved = ['admin', 'guest', 'super_admin', 'root'];
        if (in_array($key, $reserved, true)) {
            throw new InvalidArgumentException("Role key \"{$key}\" is reserved.");
        }

        if ($hub->isWhiteLabel() && ActingHubService::isControlPlaneRole($key)) {
            throw new InvalidArgumentException('Control-plane roles cannot be added on a white-labelled hub.');
        }

        $isCatalog = in_array($key, self::CATALOG_ROLES, true);
        $existingCustom = collect($this->customRoles())->firstWhere('key', $key);

        if (! $isCatalog && ! $existingCustom) {
            if ($label === '') {
                $label = $this->humanizeKey($key);
            }
            if (mb_strlen($label) > 100) {
                throw new InvalidArgumentException('Role display name must be at most 100 characters.');
            }
            $customs = $this->customRoles();
            $customs[] = [
                'key' => $key,
                'label' => $label,
                'is_custom' => true,
            ];
            Setting::setValue(self::SETTING_CUSTOM_ROLES, json_encode(array_values($customs)));
        }

        $added = $this->rolesAddedForHub($hub);
        if (! in_array($key, $added, true)) {
            $storedForHub = $this->addedRolesByHubMap()[(string) $hub->id] ?? [];
            if (! is_array($storedForHub)) {
                $storedForHub = [];
            }
            $storedForHub[] = $key;
            $this->saveAddedRolesForHub($hub, array_values(array_unique(array_map(
                fn ($role) => $this->normalizeRoleKey((string) $role),
                $storedForHub
            ))));
        }

        $this->seedRoleCapabilitiesOnHub($hub, $key);

        unset(self::$visibleMatrixRolesCache[(int) $hub->id]);

        $hub = $hub->fresh() ?? $hub;

        return [
            'role' => [
                'key' => $key,
                'label' => $label !== '' ? $label : $this->defaultLabel($key),
                'default_label' => $this->defaultLabel($key),
                'is_custom' => ! $isCatalog,
                'added_to_hub' => true,
            ],
            'added_to_hub' => $this->rolesAddedForHub($hub),
            'available_to_add' => $this->rolesAvailableToAdd($hub),
            'custom_roles' => $this->customRoles(),
        ];
    }

    /**
     * @deprecated Use addRoleToHub()
     *
     * @return array<string, mixed>
     */
    public function addRoleToAllHubs(?string $key = null, ?string $label = null): array
    {
        $hub = $this->hubs->current();

        return $this->addRoleToHub($hub, $key, $label);
    }

    /**
     * Whether a role may be removed from this hub’s matrix (Manage roles).
     */
    public function canRemoveRoleFromHub(Hub $hub, string $role, ?int $userCount = null): bool
    {
        $role = $this->normalizeRoleKey($role);
        if ($role === '') {
            return false;
        }

        if ($hub->isControlPlane() && $role === User::ROLE_POWER_ADMIN) {
            return false;
        }

        if ($userCount === null) {
            $userCount = (int) ($this->userCountsByRole($hub)[$role] ?? 0);
        }

        if ($userCount > 0) {
            return false;
        }

        $visible = $this->visibleMatrixRoles($hub);

        return in_array($role, $visible, true);
    }

    /**
     * Remove a role column from this hub’s Capabilities matrix (Manage roles).
     * Refuses when users still hold the role, or when the role is required on Central.
     *
     * @return array<string, mixed>
     */
    public function removeRoleFromHub(Hub $hub, string $key): array
    {
        $key = $this->normalizeRoleKey($key);
        if ($key === '') {
            throw new InvalidArgumentException('Role key is required.');
        }

        $visible = $this->visibleMatrixRoles($hub);
        if (! in_array($key, $visible, true)) {
            throw new InvalidArgumentException("Role \"{$key}\" is not on this hub.");
        }

        if ($hub->isControlPlane() && $key === User::ROLE_POWER_ADMIN) {
            throw new InvalidArgumentException('Power Admin cannot be removed from the Central hub.');
        }

        $userCount = (int) ($this->userCountsByRole($hub)[$key] ?? 0);
        if ($userCount > 0) {
            $label = $hub->roleLabel($key);
            throw new InvalidArgumentException(
                "Cannot delete \"{$label}\" while {$userCount} user".($userCount === 1 ? '' : 's').' still have that role. Reassign them first.'
            );
        }

        // Drop from this hub’s explicit add-list.
        $storedForHub = $this->addedRolesByHubMap()[(string) $hub->id] ?? [];
        if (! is_array($storedForHub)) {
            $storedForHub = [];
        }
        $storedForHub = array_values(array_filter(
            array_map(fn ($role) => $this->normalizeRoleKey((string) $role), $storedForHub),
            fn (string $role) => $role !== '' && $role !== $key
        ));
        $this->saveAddedRolesForHub($hub, $storedForHub);

        // Legacy “added to all hubs” — migrate other hubs to per-hub lists, then drop this role globally.
        $legacy = $this->legacyRolesAddedToAllHubs();
        if (in_array($key, $legacy, true)) {
            $this->migrateLegacyAddedRoleOffHub($hub, $key, $legacy);
        }

        $caps = is_array($hub->role_capabilities) ? $hub->role_capabilities : [];
        unset($caps[$key]);
        $hub->role_capabilities = $caps === [] ? null : $caps;

        $names = is_array($hub->role_display_names) ? $hub->role_display_names : [];
        unset($names[$key]);
        $hub->role_display_names = $names === [] ? null : $names;

        $hub->save();
        $this->hubs->forgetCurrentCache();
        unset(
            self::$visibleMatrixRolesCache[(int) $hub->id],
            self::$userCountsCache[(int) $hub->id]
        );

        if ($hub->isContentHub() && $hub->hasRemoteDatabaseConfigured()) {
            app(WhiteLabelHubSyncService::class)->pushSettings($hub->fresh() ?? $hub);
        }

        $hub = $hub->fresh() ?? $hub;

        return [
            'removed' => $key,
            'available_to_add' => $this->rolesAvailableToAdd($hub),
            'custom_roles' => $this->customRoles(),
            'added_to_hub' => $this->rolesAddedForHub($hub),
        ];
    }

    /**
     * When a role was enabled via the legacy global list, keep it on every other hub
     * via the per-hub map, then remove it from the legacy list.
     *
     * @param  list<string>  $legacy
     */
    private function migrateLegacyAddedRoleOffHub(Hub $hub, string $key, array $legacy): void
    {
        $map = $this->addedRolesByHubMap();
        foreach (Hub::query()->orderBy('id')->get(['id']) as $other) {
            if ((int) $other->id === (int) $hub->id) {
                continue;
            }
            $hubKey = (string) $other->id;
            $list = $map[$hubKey] ?? [];
            if (! is_array($list)) {
                $list = [];
            }
            $normalized = array_values(array_unique(array_filter(array_map(
                fn ($role) => $this->normalizeRoleKey((string) $role),
                $list
            ))));
            if (! in_array($key, $normalized, true)) {
                $normalized[] = $key;
            }
            $map[$hubKey] = $normalized;
        }
        Setting::setValue(self::SETTING_ADDED_BY_HUB, json_encode($map));

        $remaining = array_values(array_filter(
            $legacy,
            fn (string $role) => $role !== $key
        ));
        Setting::setValue(self::SETTING_ADDED_ROLES, json_encode($remaining));
    }

    /**
     * Roles Power Admin may assign to users on this hub (same set as matrix columns).
     *
     * @return list<string>
     */
    public function assignableRoles(?Hub $hub = null): array
    {
        if (! $hub) {
            return $this->orderRoles($this->allKnownRoles());
        }

        return $this->visibleMatrixRoles($hub);
    }

    /**
     * Seed default capability cells for a role on one hub registry row.
     */
    public function seedRoleCapabilitiesOnHub(Hub $hub, string $role): void
    {
        $role = $this->normalizeRoleKey($role);
        if ($role === '') {
            return;
        }

        if ($hub->isWhiteLabel() && ActingHubService::isControlPlaneRole($role)) {
            return;
        }

        $matrix = app(CapabilitiesMatrixService::class);
        $caps = $matrix->resolvedRoleCapabilities($hub);
        if (isset($caps[$role]) && is_array($caps[$role]) && $caps[$role] !== []) {
            $stored = is_array($hub->role_capabilities) ? $hub->role_capabilities : [];
            if (! isset($stored[$role])) {
                $stored[$role] = $caps[$role];
                $hub->forceFill(['role_capabilities' => $stored])->save();
            }
            $this->hubs->forgetCurrentCache();

            return;
        }

        $defaults = $matrix->defaultRoleCapabilities($hub->type);
        $seed = $defaults[User::ROLE_USER] ?? [];
        if (isset($defaults[$role]) && is_array($defaults[$role])) {
            $seed = $defaults[$role];
        }

        if (! isset($defaults[$role])) {
            foreach (array_keys($seed) as $cap) {
                $meta = Hub::CHECKLIST_DEFINITIONS[$cap] ?? null;
                $group = $meta['group'] ?? null;
                if (Hub::isDashboardCapabilityGroup($group)
                    || $group === Hub::GROUP_ADMIN_EMAILS
                    || $group === Hub::GROUP_MODULE_PRICING
                    || $group === Hub::GROUP_SOCIAL_MEDIA_COMPLIANCE
                    || $group === Hub::GROUP_GENERAL_COMPLIANCE
                    || $group === Hub::GROUP_WEBSITE_COMPLIANCE
                    || $group === Hub::GROUP_WEBSITE_TEMPLATE_LIBRARY
                ) {
                    $seed[$cap] = false;
                }
            }
            if ($hub->isCentral()) {
                $seed['member_view_site_pages'] = false;
                $seed['member_browse_catalog'] = false;
                $seed['member_view_plans'] = false;
                $seed['member_purchase_content'] = false;
                $seed['member_download_content'] = false;
                $seed['member_in_app_edit'] = false;
            }
        }

        $stored = is_array($hub->role_capabilities) ? $hub->role_capabilities : [];
        $stored[$role] = $seed;
        $hub->forceFill(['role_capabilities' => $stored])->save();
        $this->hubs->forgetCurrentCache();
    }

    /**
     * @deprecated Use seedRoleCapabilitiesOnHub()
     */
    public function seedRoleCapabilitiesOnAllHubs(string $role): void
    {
        foreach (Hub::query()->orderBy('id')->get() as $hub) {
            $this->seedRoleCapabilitiesOnHub($hub, $role);
        }
    }

    /**
     * @param  list<string>  $roles
     * @return list<string>
     */
    public function orderRoles(array $roles): array
    {
        $order = $this->allKnownRoles();
        $ranked = [];
        foreach ($order as $role) {
            if (in_array($role, $roles, true)) {
                $ranked[] = $role;
            }
        }
        foreach ($roles as $role) {
            if (! in_array($role, $ranked, true)) {
                $ranked[] = $role;
            }
        }

        return $ranked;
    }

    public function normalizeRoleKey(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? '';
        $value = trim($value, '_');

        return $value;
    }

    private function humanizeKey(string $key): string
    {
        $key = str_replace('_', ' ', $key);

        return $key === '' ? $key : mb_convert_case($key, MB_CASE_TITLE, 'UTF-8');
    }
}
