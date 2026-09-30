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
 * added across every hub.
 *
 * Visibility = roles that currently have users on that hub
 *            ∪ roles Power Admin explicitly added for all hubs
 *            ∪ (Power Admin on Central, so platform pa_* cells stay editable).
 *
 * Custom roles (beyond the built-in catalog) are stored platform-wide and
 * seeded onto every hub’s role_capabilities blob.
 */
class HubRolesService
{
    public const SETTING_ADDED_ROLES = 'hub_roles_added_to_all';

    public const SETTING_CUSTOM_ROLES = 'hub_roles_custom';

    /**
     * Built-in role keys (catalog). New custom roles are additions, not replacements.
     *
     * @var list<string>
     */
    public const CATALOG_ROLES = CapabilitiesMatrixService::MATRIX_ROLES;

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
     * Roles Power Admin has explicitly enabled on every hub (even with no users yet).
     *
     * @return list<string>
     */
    public function rolesAddedToAllHubs(): array
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
        $known = $this->allKnownRoles();
        $found = [];

        try {
            $current = $this->hubs->current();
            if ((int) $hub->id === (int) $current->id || $hub->isControlPlane()) {
                $found = User::query()
                    ->whereNotNull('role')
                    ->where('role', '!=', '')
                    ->distinct()
                    ->pluck('role')
                    ->all();
            } elseif ($hub->isContentHub() && $hub->hasRemoteDatabaseConfigured()) {
                $connection = $this->remoteDb->connect($hub);
                if (Schema::connection($connection)->hasTable('users')) {
                    $found = DB::connection($connection)
                        ->table('users')
                        ->whereNotNull('role')
                        ->where('role', '!=', '')
                        ->distinct()
                        ->pluck('role')
                        ->all();
                }
            }
        } catch (Throwable) {
            $found = [];
        }

        $out = [];
        foreach ($found as $role) {
            $role = $this->normalizeRoleKey((string) $role);
            if ($role === 'admin') {
                $role = User::ROLE_CLIENT_ADMIN;
            }
            if ($role !== '' && in_array($role, $known, true) && ! in_array($role, $out, true)) {
                $out[] = $role;
            }
        }

        return $out;
    }

    /**
     * Role columns shown in the Capabilities matrix for this hub.
     *
     * @return list<string>
     */
    public function visibleMatrixRoles(Hub $hub): array
    {
        $visible = array_values(array_unique(array_merge(
            $this->rolesPresentOnHub($hub),
            $this->rolesAddedToAllHubs()
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

        return $this->orderRoles($visible);
    }

    /**
     * Roles that can still be added to all hubs (catalog + not yet added globally).
     *
     * @return list<array{key: string, label: string, is_custom: bool}>
     */
    public function rolesAvailableToAdd(): array
    {
        $added = $this->rolesAddedToAllHubs();
        $out = [];
        foreach (self::CATALOG_ROLES as $role) {
            if (in_array($role, $added, true)) {
                continue;
            }
            $out[] = [
                'key' => $role,
                'label' => $this->defaultLabel($role),
                'is_custom' => false,
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
        $present = $this->rolesPresentOnHub($hub);
        $added = $this->rolesAddedToAllHubs();

        $roles = [];
        foreach ($visible as $role) {
            $roles[] = [
                'key' => $role,
                'label' => $hub->roleLabel($role),
                'default_label' => $this->defaultLabel($role),
                'is_custom' => ! in_array($role, self::CATALOG_ROLES, true),
                'present_on_hub' => in_array($role, $present, true),
                'added_to_all_hubs' => in_array($role, $added, true),
            ];
        }

        return [
            'roles' => $roles,
            'available_to_add' => $this->rolesAvailableToAdd(),
            'custom_roles' => $this->customRoles(),
            'added_to_all_hubs' => $added,
        ];
    }

    /**
     * Enable an existing catalog role on every hub, or create a new custom role.
     *
     * @return array<string, mixed>
     */
    public function addRoleToAllHubs(?string $key = null, ?string $label = null): array
    {
        $key = $this->normalizeRoleKey((string) $key);
        $label = trim((string) $label);

        if ($key === '') {
            // Creating a brand-new custom role — label required; key derived from label.
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

        $added = $this->rolesAddedToAllHubs();
        if (! in_array($key, $added, true)) {
            $added[] = $key;
            Setting::setValue(self::SETTING_ADDED_ROLES, json_encode(array_values($added)));
        }

        $this->seedRoleCapabilitiesOnAllHubs($key);

        return [
            'role' => [
                'key' => $key,
                'label' => $label !== '' ? $label : $this->defaultLabel($key),
                'default_label' => $this->defaultLabel($key),
                'is_custom' => ! $isCatalog,
                'added_to_all_hubs' => true,
            ],
            'added_to_all_hubs' => $this->rolesAddedToAllHubs(),
            'available_to_add' => $this->rolesAvailableToAdd(),
            'custom_roles' => $this->customRoles(),
        ];
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
     * Seed default capability cells for a role on every hub registry row.
     */
    public function seedRoleCapabilitiesOnAllHubs(string $role): void
    {
        $role = $this->normalizeRoleKey($role);
        if ($role === '') {
            return;
        }

        $matrix = app(CapabilitiesMatrixService::class);

        foreach (Hub::query()->orderBy('id')->get() as $hub) {
            if ($hub->isWhiteLabel() && ActingHubService::isControlPlaneRole($role)) {
                continue;
            }

            $caps = $matrix->resolvedRoleCapabilities($hub);
            if (isset($caps[$role]) && is_array($caps[$role]) && $caps[$role] !== []) {
                // Keep existing cells; ensure key exists in stored blob.
                $stored = is_array($hub->role_capabilities) ? $hub->role_capabilities : [];
                if (! isset($stored[$role])) {
                    $stored[$role] = $caps[$role];
                    $hub->forceFill(['role_capabilities' => $stored])->save();
                }

                continue;
            }

            $defaults = $matrix->defaultRoleCapabilities($hub->type);
            $seed = $defaults[User::ROLE_USER] ?? [];
            if (isset($defaults[$role]) && is_array($defaults[$role])) {
                $seed = $defaults[$role];
            }

            // Custom / newly added roles: conservative defaults (member browse on content hubs).
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
        }

        $this->hubs->forgetCurrentCache();
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
