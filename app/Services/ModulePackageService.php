<?php

namespace App\Services;

use App\Models\Hub;
use App\Models\ModulePackage;
use App\Models\ModulePackageItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ModulePackageService
{
    /**
     * Expand selected product modules so every dependency (except the hub base)
     * is included. Rejects locked / base / unknown keys.
     *
     * @param  list<string>  $moduleKeys
     * @return list<string>
     */
    public function normalizePackageModuleKeys(array $moduleKeys, ?string $hubType = null): array
    {
        $hubType = $hubType ?: Hub::TYPE_SHARED;
        $requested = [];
        foreach ($moduleKeys as $key) {
            $key = (string) $key;
            if ($key === '' || isset($requested[$key])) {
                continue;
            }
            if (! in_array($key, Hub::PRODUCT_MODULE_KEYS, true)) {
                throw new InvalidArgumentException(
                    "Module packages may only include product modules. Invalid key: {$key}"
                );
            }
            $requested[$key] = true;
        }

        if ($requested === []) {
            throw new InvalidArgumentException('A module package must include at least one product module.');
        }

        $resolved = $requested;
        $base = $hubType === Hub::TYPE_WHITE_LABEL
            ? 'module_white_label_hub'
            : 'module_shared_hub';

        foreach (array_keys($requested) as $key) {
            $deps = Hub::MODULE_DEPENDENCIES[$key] ?? [];
            foreach ($deps as $dep) {
                $resolvedDep = $dep === '__base__' ? $base : $dep;
                if ($resolvedDep === $base || Hub::isLockedModuleKey($resolvedDep)) {
                    continue;
                }
                if (! in_array($resolvedDep, Hub::PRODUCT_MODULE_KEYS, true)) {
                    continue;
                }
                $resolved[$resolvedDep] = true;
            }
        }

        $ordered = [];
        foreach (Hub::PRODUCT_MODULE_KEYS as $key) {
            if (isset($resolved[$key])) {
                $ordered[] = $key;
            }
        }

        return $ordered;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listPackages(bool $activeOnly = false): array
    {
        $query = ModulePackage::query()
            ->with('items')
            ->orderBy('sort_order')
            ->orderBy('name');

        if ($activeOnly) {
            $query->where('is_active', true);
        }

        return $query->get()->map(fn (ModulePackage $pkg) => $this->serialize($pkg))->all();
    }

    /**
     * @param  array{
     *   name: string,
     *   slug?: string|null,
     *   description?: string|null,
     *   amount?: mixed,
     *   billing_unit?: string,
     *   currency?: string,
     *   is_active?: mixed,
     *   sort_order?: mixed,
     *   module_keys: list<string>
     * }  $data
     */
    public function create(array $data): ModulePackage
    {
        $moduleKeys = $this->normalizePackageModuleKeys($data['module_keys'] ?? []);
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('Package name is required.');
        }

        $slug = $this->uniqueSlug((string) ($data['slug'] ?? ''), $name);

        return DB::transaction(function () use ($data, $moduleKeys, $name, $slug) {
            $package = ModulePackage::query()->create([
                'name' => $name,
                'slug' => $slug,
                'description' => isset($data['description']) ? (string) $data['description'] : null,
                'amount' => round((float) ($data['amount'] ?? 0), 2),
                'recurring_amount' => round((float) ($data['recurring_amount'] ?? 0), 2),
                'recurring_billing_unit' => $this->normalizeRecurringUnit($data['recurring_billing_unit'] ?? null),
                'recurring_tier_slot' => $this->normalizeRecurringSlot($data['recurring_tier_slot'] ?? null),
                'billing_unit' => ModulePricingService::BILLING_UNIT_ONE_TIME,
                'currency' => strtolower((string) ($data['currency'] ?? 'gbp')),
                'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true,
                'sort_order' => (int) ($data['sort_order'] ?? 0),
            ]);

            $this->syncItems($package, $moduleKeys);

            return $package->fresh('items');
        });
    }

    /**
     * @param  array{
     *   name?: string,
     *   slug?: string|null,
     *   description?: string|null,
     *   amount?: mixed,
     *   currency?: string,
     *   is_active?: mixed,
     *   sort_order?: mixed,
     *   module_keys?: list<string>
     * }  $data
     */
    public function update(ModulePackage $package, array $data): ModulePackage
    {
        return DB::transaction(function () use ($package, $data) {
            if (array_key_exists('name', $data)) {
                $name = trim((string) $data['name']);
                if ($name === '') {
                    throw new InvalidArgumentException('Package name is required.');
                }
                $package->name = $name;
            }
            if (array_key_exists('slug', $data) && $data['slug'] !== null && trim((string) $data['slug']) !== '') {
                $package->slug = $this->uniqueSlug((string) $data['slug'], $package->name, $package->id);
            }
            if (array_key_exists('description', $data)) {
                $package->description = $data['description'] !== null ? (string) $data['description'] : null;
            }
            if (array_key_exists('amount', $data)) {
                $package->amount = round((float) $data['amount'], 2);
            }
            if (array_key_exists('recurring_amount', $data)) {
                $package->recurring_amount = round((float) $data['recurring_amount'], 2);
            }
            if (array_key_exists('recurring_billing_unit', $data)) {
                $package->recurring_billing_unit = $this->normalizeRecurringUnit($data['recurring_billing_unit']);
            }
            if (array_key_exists('recurring_tier_slot', $data)) {
                $package->recurring_tier_slot = $this->normalizeRecurringSlot($data['recurring_tier_slot']);
            }
            if (array_key_exists('currency', $data)) {
                $package->currency = strtolower((string) $data['currency']);
            }
            if (array_key_exists('is_active', $data)) {
                $package->is_active = (bool) $data['is_active'];
            }
            if (array_key_exists('sort_order', $data)) {
                $package->sort_order = (int) $data['sort_order'];
            }
            $package->save();

            if (array_key_exists('module_keys', $data)) {
                $moduleKeys = $this->normalizePackageModuleKeys($data['module_keys'] ?? []);
                $this->syncItems($package, $moduleKeys);

                // If an active hub has this package enabled, ensure checklist still covers items.
                $this->reapplyPackageModulesOnHubs($package->fresh('items'));
            }

            return $package->fresh('items');
        });
    }

    public function delete(ModulePackage $package): void
    {
        DB::transaction(function () use ($package) {
            $packageId = (int) $package->id;

            Hub::query()
                ->whereNotNull('enabled_module_package_ids')
                ->orderBy('id')
                ->each(function (Hub $hub) use ($packageId) {
                    $ids = $hub->enabledModulePackageIds();
                    if (! in_array($packageId, $ids, true)) {
                        return;
                    }
                    $hub->enabled_module_package_ids = array_values(array_filter(
                        $ids,
                        fn (int $id) => $id !== $packageId
                    ));
                    $hub->save();
                });

            $package->delete();
        });
    }

    /**
     * @param  list<string>  $moduleKeys
     */
    private function syncItems(ModulePackage $package, array $moduleKeys): void
    {
        ModulePackageItem::query()->where('module_package_id', $package->id)->delete();
        foreach ($moduleKeys as $key) {
            ModulePackageItem::query()->create([
                'module_package_id' => $package->id,
                'module_key' => $key,
            ]);
        }
    }

    private function reapplyPackageModulesOnHubs(ModulePackage $package): void
    {
        $packageId = (int) $package->id;
        $keys = $package->moduleKeys();

        Hub::query()
            ->whereNotNull('enabled_module_package_ids')
            ->orderBy('id')
            ->each(function (Hub $hub) use ($packageId, $keys) {
                if (! in_array($packageId, $hub->enabledModulePackageIds(), true)) {
                    return;
                }
                $checklist = $hub->resolvedChecklist();
                foreach ($keys as $key) {
                    $checklist[$key] = true;
                }
                $hub->checklist = $hub->applyModuleDependencies($checklist);
                $hub->save();
            });
    }

    private function uniqueSlug(string $slug, string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($slug !== '' ? $slug : $name);
        if ($base === '') {
            $base = 'module-package';
        }

        $candidate = $base;
        $i = 2;
        while (
            ModulePackage::query()
                ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
                ->where('slug', $candidate)
                ->exists()
        ) {
            $candidate = $base.'-'.$i;
            $i++;
        }

        return $candidate;
    }

    /**
     * Resolve enabled package IDs from Modules-page payload + enforce exclusivity.
     *
     * @param  array<int|string, mixed>  $packagesInput
     * @param  list<int>  $currentIds
     * @return list<int>
     */
    public function resolveEnabledPackageIds(array $packagesInput, array $currentIds): array
    {
        $desired = array_fill_keys($currentIds, true);
        $map = $this->normalizePackagesPayload($packagesInput);

        foreach ($map as $id => $enabled) {
            if ($enabled) {
                $desired[$id] = true;
            } else {
                unset($desired[$id]);
            }
        }

        $ids = array_map('intval', array_keys($desired));
        $ids = array_values(array_unique(array_filter($ids, fn (int $id) => $id > 0)));

        if ($ids === []) {
            return [];
        }

        $packages = ModulePackage::query()
            ->with('items')
            ->whereIn('id', $ids)
            ->where('is_active', true)
            ->get()
            ->keyBy('id');

        $missing = array_diff($ids, $packages->keys()->all());
        if ($missing !== []) {
            throw new InvalidArgumentException(
                'Unknown or inactive module package ids: '.implode(', ', $missing)
            );
        }

        // Newly requested packages must not share modules with each other or with
        // packages that remain enabled.
        $this->assertNoOverlappingPackages($packages->all());

        return array_values(array_map('intval', $packages->keys()->all()));
    }

    /**
     * @param  list<ModulePackage>  $packages
     */
    public function assertNoOverlappingPackages(array $packages): void
    {
        $owner = [];
        foreach ($packages as $package) {
            foreach ($package->moduleKeys() as $key) {
                if (isset($owner[$key])) {
                    throw new InvalidArgumentException(
                        sprintf(
                            'Module packages cannot overlap: “%s” and “%s” both include %s. Disable one package or remove the shared module.',
                            $owner[$key]->name,
                            $package->name,
                            Hub::CHECKLIST_DEFINITIONS[$key]['label'] ?? $key
                        )
                    );
                }
                $owner[$key] = $package;
            }
        }
    }

    /**
     * Apply enabled packages onto a checklist: force covered modules ON;
     * when a package is removed, turn its modules OFF unless another enabled
     * package still covers them.
     *
     * @param  array<string, bool>  $checklist
     * @param  list<int>  $beforePackageIds
     * @param  list<int>  $afterPackageIds
     * @return array<string, bool>
     */
    public function applyPackagesToChecklist(
        Hub $hub,
        array $checklist,
        array $beforePackageIds,
        array $afterPackageIds
    ): array {
        $afterSet = array_fill_keys($afterPackageIds, true);
        $removedIds = array_values(array_filter(
            $beforePackageIds,
            fn (int $id) => ! isset($afterSet[$id])
        ));

        $afterPackages = $this->packagesByIds($afterPackageIds);
        $coveredAfter = $this->moduleKeysCoveredByPackages($afterPackages);

        // Modules that leave because their package was disabled.
        if ($removedIds !== []) {
            $removedPackages = $this->packagesByIds($removedIds);
            foreach (array_keys($this->moduleKeysCoveredByPackages($removedPackages)) as $key) {
                if (! isset($coveredAfter[$key])) {
                    $checklist[$key] = false;
                }
            }
        }

        // Force every module covered by an enabled package ON.
        foreach (array_keys($coveredAfter) as $key) {
            $checklist[$key] = true;
        }

        return $hub->applyModuleDependencies($checklist);
    }

    /**
     * Reject individual toggles that conflict with enabled packages.
     *
     * @param  array<string, mixed>  $moduleInput  key => enabled
     * @param  list<int>  $enabledPackageIds
     * @return array<string, mixed>
     */
    public function filterIndividualModuleToggles(array $moduleInput, array $enabledPackageIds): array
    {
        if ($enabledPackageIds === [] || $moduleInput === []) {
            return $moduleInput;
        }

        $covered = $this->moduleKeysCoveredByPackages($this->packagesByIds($enabledPackageIds));
        $filtered = [];
        foreach ($moduleInput as $key => $enabled) {
            $key = (string) $key;
            if (isset($covered[$key])) {
                // Package owns this module — ignore separate selection.
                continue;
            }
            $filtered[$key] = $enabled;
        }

        return $filtered;
    }

    /**
     * @param  list<int>  $packageIds
     * @return list<ModulePackage>
     */
    public function packagesByIds(array $packageIds): array
    {
        if ($packageIds === []) {
            return [];
        }

        return ModulePackage::query()
            ->with('items')
            ->whereIn('id', $packageIds)
            ->get()
            ->all();
    }

    /**
     * @param  list<ModulePackage>  $packages
     * @return array<string, true>
     */
    public function moduleKeysCoveredByPackages(array $packages): array
    {
        $covered = [];
        foreach ($packages as $package) {
            foreach ($package->moduleKeys() as $key) {
                $covered[$key] = true;
            }
        }

        return $covered;
    }

    /**
     * Map module_key → owning enabled package for a hub.
     *
     * @return array<string, ModulePackage>
     */
    public function packageOwnershipMap(Hub $hub): array
    {
        $map = [];
        foreach ($this->packagesByIds($hub->enabledModulePackageIds()) as $package) {
            foreach ($package->moduleKeys() as $key) {
                $map[$key] = $package;
            }
        }

        return $map;
    }

    /**
     * @param  list<int>  $before
     * @param  list<int>  $after
     * @return list<int>
     */
    public function newlyEnabledPackageIds(array $before, array $after): array
    {
        $beforeSet = array_fill_keys($before, true);

        return array_values(array_filter(
            $after,
            fn (int $id) => ! isset($beforeSet[$id])
        ));
    }

    /**
     * @param  array<int|string, mixed>  $packages
     * @return array<int, bool>
     */
    public function normalizePackagesPayload(array $packages): array
    {
        if (array_is_list($packages)) {
            $map = [];
            foreach ($packages as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $id = (int) ($row['id'] ?? $row['package_id'] ?? 0);
                if ($id <= 0) {
                    continue;
                }
                $enabled = $row['enabled'] ?? $row['value'] ?? null;
                if ($enabled === null) {
                    continue;
                }
                $map[$id] = (bool) $enabled;
            }

            return $map;
        }

        $map = [];
        foreach ($packages as $id => $enabled) {
            $id = (int) $id;
            if ($id <= 0) {
                continue;
            }
            $map[$id] = (bool) $enabled;
        }

        return $map;
    }

    /**
     * @return array<string, mixed>
     */
    public function serialize(ModulePackage $package): array
    {
        $package->loadMissing('items');
        $keys = $package->moduleKeys();

        return [
            'id' => $package->id,
            'name' => $package->name,
            'slug' => $package->slug,
            'description' => $package->description,
            'amount' => (float) $package->amount,
            'billing_unit' => (string) ($package->billing_unit ?: ModulePricingService::BILLING_UNIT_ONE_TIME),
            'recurring_amount' => (float) ($package->recurring_amount ?? 0),
            'recurring_billing_unit' => (string) ($package->recurring_billing_unit ?: ModuleRecurringBillingService::UNIT_NONE),
            'recurring_tier_slot' => $package->recurring_tier_slot !== null
                ? (int) $package->recurring_tier_slot
                : null,
            'currency' => $package->currency,
            'is_active' => (bool) $package->is_active,
            'sort_order' => (int) $package->sort_order,
            'module_keys' => $keys,
            'modules' => array_map(fn (string $key) => [
                'key' => $key,
                'label' => (string) (Hub::CHECKLIST_DEFINITIONS[$key]['label'] ?? $key),
            ], $keys),
            'individual_module_amount_sum' => $this->sumIndividualCatalogueAmounts($keys),
        ];
    }

    private function normalizeRecurringUnit(mixed $unit): string
    {
        $unit = (string) ($unit ?: ModuleRecurringBillingService::UNIT_NONE);
        if (! in_array($unit, ModulePricingService::RECURRING_UNITS, true)) {
            throw new InvalidArgumentException('Invalid recurring_billing_unit.');
        }

        return $unit;
    }

    private function normalizeRecurringSlot(mixed $slot): ?int
    {
        if ($slot === null || $slot === '') {
            return null;
        }

        return max(1, min(3, (int) $slot));
    }

    /**
     * Sum of individual one-time catalogue amounts (for UI comparison).
     * WTL uses unit amount only (not × template count).
     *
     * @param  list<string>  $moduleKeys
     */
    public function sumIndividualCatalogueAmounts(array $moduleKeys): float
    {
        $pricing = app(ModulePricingService::class);
        $sum = 0.0;
        foreach ($moduleKeys as $key) {
            try {
                $quote = $pricing->quote($key);
                $sum += (float) $quote['amount'];
            } catch (InvalidArgumentException) {
                // ignore unknown
            }
        }

        return round($sum, 2);
    }

    /**
     * Packages payload for the hub Modules page.
     *
     * @return list<array<string, mixed>>
     */
    public function packagesPayloadForHub(Hub $hub): array
    {
        if ($hub->isCentral()) {
            return [];
        }

        $enabledIds = array_fill_keys($hub->enabledModulePackageIds(), true);
        $ownership = $this->packageOwnershipMap($hub);

        return ModulePackage::query()
            ->with('items')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(function (ModulePackage $package) use ($enabledIds, $ownership) {
                $row = $this->serialize($package);
                $row['enabled'] = isset($enabledIds[$package->id]);
                $conflictKeys = [];
                foreach ($package->moduleKeys() as $key) {
                    $owner = $ownership[$key] ?? null;
                    if ($owner && (int) $owner->id !== (int) $package->id) {
                        $conflictKeys[] = $key;
                    }
                }
                $row['available'] = $conflictKeys === [] || $row['enabled'];
                $row['unavailable_reason'] = $conflictKeys === [] || $row['enabled']
                    ? null
                    : 'One or more modules in this package are already covered by another enabled package.';
                $row['conflict_module_keys'] = $conflictKeys;

                return $row;
            })
            ->all();
    }
}
