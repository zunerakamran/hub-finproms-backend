<?php

namespace App\Services;

use App\Models\Hub;
use App\Models\ModulePricing;
use InvalidArgumentException;

class ModulePricingService
{
    /** @deprecated Prefer UNIT_PER_NETWORK for flat one-time charges. Still accepted. */
    public const BILLING_UNIT_ONE_TIME = 'one_time';

    public const BILLING_UNIT_PER_WEBSITE = 'per_website';

    public const UNIT_PER_NETWORK = 'per_network';

    public const UNIT_PER_FIRM = 'per_firm';

    public const UNIT_PER_USER = 'per_user';

    public const UNIT_PER_USER_SLOTS = 'per_user_slots';

    public const UNIT_PER_WEBSITE = 'per_website';

    /**
     * Canonical units for both one-time (`billing_unit`) and ongoing
     * (`recurring_billing_unit`) dropdowns. Recurring also allows `none`.
     *
     * @var list<string>
     */
    public const PRICE_UNITS = [
        self::UNIT_PER_NETWORK,
        self::UNIT_PER_FIRM,
        self::UNIT_PER_USER,
        self::UNIT_PER_USER_SLOTS,
        self::UNIT_PER_WEBSITE,
    ];

    /**
     * Units accepted when saving one-time billing_unit (canonical + legacy).
     *
     * @var list<string>
     */
    public const ONE_TIME_UNITS = [
        self::UNIT_PER_NETWORK,
        self::UNIT_PER_FIRM,
        self::UNIT_PER_USER,
        self::UNIT_PER_USER_SLOTS,
        self::UNIT_PER_WEBSITE,
        self::BILLING_UNIT_ONE_TIME, // legacy flat
    ];

    /**
     * Units accepted when saving recurring_billing_unit (canonical + none + legacy).
     *
     * @var list<string>
     */
    public const RECURRING_UNITS = [
        ModuleRecurringBillingService::UNIT_NONE,
        self::UNIT_PER_NETWORK,
        self::UNIT_PER_FIRM,
        self::UNIT_PER_USER,
        self::UNIT_PER_USER_SLOTS,
        self::UNIT_PER_WEBSITE,
        ModuleRecurringBillingService::UNIT_PER_ADVISER, // legacy → slots
    ];

    /**
     * Default catalogue prices (amount + billing unit + recurring).
     *
     * @var array<string, array{amount: float, billing_unit: string, recurring_amount: float, recurring_billing_unit: string, recurring_tier_slot: ?int}>
     */
    public const DEFAULT_CATALOGUE = [
        'module_shared_hub' => [
            'amount' => 14500.00,
            'billing_unit' => self::UNIT_PER_NETWORK,
            'recurring_amount' => 4500.00,
            'recurring_billing_unit' => self::UNIT_PER_NETWORK,
            'recurring_tier_slot' => null,
        ],
        'module_white_label_hub' => [
            'amount' => 14500.00,
            'billing_unit' => self::UNIT_PER_NETWORK,
            'recurring_amount' => 4500.00,
            'recurring_billing_unit' => self::UNIT_PER_NETWORK,
            'recurring_tier_slot' => null,
        ],
        'module_social_media_template_library' => [
            'amount' => 0.00,
            'billing_unit' => self::UNIT_PER_NETWORK,
            'recurring_amount' => 0.00,
            'recurring_billing_unit' => self::UNIT_PER_USER_SLOTS,
            'recurring_tier_slot' => 1,
        ],
        'module_social_media_compliance' => [
            'amount' => 5000.00,
            'billing_unit' => self::UNIT_PER_NETWORK,
            'recurring_amount' => 0.00,
            'recurring_billing_unit' => self::UNIT_PER_USER_SLOTS,
            'recurring_tier_slot' => 2,
        ],
        'module_website_template_library' => [
            'amount' => 300.00,
            'billing_unit' => self::UNIT_PER_WEBSITE,
            'recurring_amount' => 125.00,
            'recurring_billing_unit' => self::UNIT_PER_WEBSITE,
            'recurring_tier_slot' => null,
        ],
        'module_website_compliance' => [
            'amount' => 10000.00,
            'billing_unit' => self::UNIT_PER_NETWORK,
            'recurring_amount' => 1500.00,
            'recurring_billing_unit' => self::UNIT_PER_FIRM,
            'recurring_tier_slot' => null,
        ],
        'module_general_compliance' => [
            'amount' => 3500.00,
            'billing_unit' => self::UNIT_PER_NETWORK,
            'recurring_amount' => 0.00,
            'recurring_billing_unit' => self::UNIT_PER_USER_SLOTS,
            'recurring_tier_slot' => 3,
        ],
    ];

    /** @deprecated Use DEFAULT_CATALOGUE */
    public const DEFAULT_AMOUNTS = [
        'module_shared_hub' => 14500.00,
        'module_white_label_hub' => 14500.00,
        'module_social_media_template_library' => 0.00,
        'module_social_media_compliance' => 5000.00,
        'module_website_template_library' => 300.00,
        'module_website_compliance' => 10000.00,
        'module_general_compliance' => 3500.00,
    ];

    /**
     * Dropdown options for one-time and ongoing price units (same list).
     *
     * @return list<array{value: string, label: string, description: string, requires_tier_slot: bool}>
     */
    public static function priceUnitOptions(): array
    {
        return [
            [
                'value' => self::UNIT_PER_NETWORK,
                'label' => 'Per network',
                'description' => 'Invoice per the network / central firm (usually there is only one). Flat charge × 1.',
                'requires_tier_slot' => false,
            ],
            [
                'value' => self::UNIT_PER_FIRM,
                'label' => 'Per firm',
                'description' => 'Invoice generated per firm on the hub (amount × firm count).',
                'requires_tier_slot' => false,
            ],
            [
                'value' => self::UNIT_PER_USER,
                'label' => 'Per user',
                'description' => 'Invoice generated per user at a fixed catalogue rate (amount × active users with the module / package).',
                'requires_tier_slot' => false,
            ],
            [
                'value' => self::UNIT_PER_USER_SLOTS,
                'label' => 'Per user (slots)',
                'description' => 'User-count slots (1–3) define the rate from the recurring tier table; invoice uses that slot rate × billed users.',
                'requires_tier_slot' => true,
            ],
            [
                'value' => self::UNIT_PER_WEBSITE,
                'label' => 'Per website',
                'description' => 'Per website template request (deployed wc_template_requests) — not the template showcase catalogue.',
                'requires_tier_slot' => false,
            ],
        ];
    }

    /**
     * Recurring dropdown = same price units + “none” (disable ongoing charge).
     *
     * @return list<array{value: string, label: string, description: string, requires_tier_slot: bool}>
     */
    public static function recurringUnitOptions(): array
    {
        return array_merge([
            [
                'value' => ModuleRecurringBillingService::UNIT_NONE,
                'label' => 'None',
                'description' => 'No ongoing / recurring charge for this module or package.',
                'requires_tier_slot' => false,
            ],
        ], self::priceUnitOptions());
    }

    public static function billingUnitLabel(string $unit): string
    {
        $normalized = self::normalizeUnit($unit);

        return match ($normalized) {
            self::UNIT_PER_NETWORK => 'Per network',
            self::UNIT_PER_FIRM => 'Per firm',
            self::UNIT_PER_USER => 'Per user',
            self::UNIT_PER_USER_SLOTS => 'Per user (slots)',
            self::UNIT_PER_WEBSITE => 'Per website',
            ModuleRecurringBillingService::UNIT_NONE => 'None',
            self::BILLING_UNIT_ONE_TIME => 'Per network',
            default => 'Per network',
        };
    }

    /**
     * Normalize legacy unit codes to canonical values used for billing math.
     */
    public static function normalizeUnit(string $unit): string
    {
        return match ($unit) {
            self::BILLING_UNIT_ONE_TIME => self::UNIT_PER_NETWORK,
            ModuleRecurringBillingService::UNIT_PER_ADVISER => self::UNIT_PER_USER_SLOTS,
            ModuleRecurringBillingService::UNIT_PER_USER => self::UNIT_PER_USER,
            default => $unit,
        };
    }

    public static function isSeatUnit(string $unit): bool
    {
        $normalized = self::normalizeUnit($unit);

        return $normalized === self::UNIT_PER_USER_SLOTS
            || $unit === ModuleRecurringBillingService::UNIT_PER_ADVISER
            || $unit === self::UNIT_PER_USER_SLOTS;
    }

    public static function isFlatQuantityUnit(string $unit): bool
    {
        $normalized = self::normalizeUnit($unit);

        return in_array($normalized, [
            self::UNIT_PER_NETWORK,
            self::UNIT_PER_FIRM,
            self::UNIT_PER_USER,
            self::UNIT_PER_WEBSITE,
        ], true);
    }

    public function seedDefaultsIfEmpty(): void
    {
        if (ModulePricing::query()->exists()) {
            $this->ensureAllModuleKeysExist();

            return;
        }

        $sort = 0;
        foreach (Hub::MODULE_KEYS as $key) {
            $meta = self::DEFAULT_CATALOGUE[$key] ?? [
                'amount' => 0.0,
                'billing_unit' => self::UNIT_PER_NETWORK,
            ];
            ModulePricing::query()->create([
                'module_key' => $key,
                'amount' => $meta['amount'],
                'billing_unit' => $meta['billing_unit'],
                'recurring_amount' => $meta['recurring_amount'] ?? 0,
                'recurring_billing_unit' => $meta['recurring_billing_unit'] ?? ModuleRecurringBillingService::UNIT_NONE,
                'recurring_tier_slot' => $meta['recurring_tier_slot'] ?? null,
                'currency' => 'gbp',
                'is_active' => true,
                'sort_order' => $sort++,
            ]);
        }
    }

    public function ensureAllModuleKeysExist(): void
    {
        $existing = ModulePricing::query()->pluck('module_key')->all();
        $sort = (int) ModulePricing::query()->max('sort_order');

        foreach (Hub::MODULE_KEYS as $key) {
            if (in_array($key, $existing, true)) {
                continue;
            }
            $meta = self::DEFAULT_CATALOGUE[$key] ?? [
                'amount' => 0.0,
                'billing_unit' => self::UNIT_PER_NETWORK,
                'recurring_amount' => 0.0,
                'recurring_billing_unit' => ModuleRecurringBillingService::UNIT_NONE,
                'recurring_tier_slot' => null,
            ];
            ModulePricing::query()->create([
                'module_key' => $key,
                'amount' => $meta['amount'],
                'billing_unit' => $meta['billing_unit'],
                'recurring_amount' => $meta['recurring_amount'] ?? 0,
                'recurring_billing_unit' => $meta['recurring_billing_unit'] ?? ModuleRecurringBillingService::UNIT_NONE,
                'recurring_tier_slot' => $meta['recurring_tier_slot'] ?? null,
                'currency' => 'gbp',
                'is_active' => true,
                'sort_order' => ++$sort,
            ]);
        }
    }

    /**
     * @return list<array{
     *   id: int,
     *   module_key: string,
     *   label: string,
     *   amount: float,
     *   billing_unit: string,
     *   billing_unit_label: string,
     *   currency: string,
     *   is_active: bool,
     *   sort_order: int
     * }>
     */
    public function listPricing(): array
    {
        $this->seedDefaultsIfEmpty();

        return ModulePricing::query()
            ->orderBy('sort_order')
            ->orderBy('module_key')
            ->get()
            ->map(fn (ModulePricing $row) => $this->serialize($row))
            ->all();
    }

    /**
     * Catalogue rows that apply to this hub type only.
     * Central → Central Hub Controller; Shared → Shared Hub + product modules;
     * White-label → White Label Hub + product modules.
     *
     * @return list<array<string, mixed>>
     */
    public function listPricingForHub(Hub $hub): array
    {
        $allowed = array_fill_keys($hub->moduleKeysForPage(), true);

        return array_values(array_filter(
            $this->listPricing(),
            fn (array $row) => isset($allowed[$row['module_key'] ?? ''])
        ));
    }

    public function pricingAppliesToHub(ModulePricing $pricing, Hub $hub): bool
    {
        return in_array((string) $pricing->module_key, $hub->moduleKeysForPage(), true);
    }

    /**
     * @return array{
     *   module_key: string,
     *   amount: float,
     *   billing_unit: string,
     *   currency: string,
     *   pricing: ?ModulePricing
     * }
     */
    public function quote(string $moduleKey): array
    {
        $this->seedDefaultsIfEmpty();

        if (! in_array($moduleKey, Hub::MODULE_KEYS, true)) {
            throw new InvalidArgumentException("Unknown module key: {$moduleKey}");
        }

        $pricing = ModulePricing::query()
            ->where('module_key', $moduleKey)
            ->where('is_active', true)
            ->first();

        return [
            'module_key' => $moduleKey,
            'amount' => $pricing ? (float) $pricing->amount : 0.0,
            'billing_unit' => $pricing?->billing_unit ?? self::UNIT_PER_NETWORK,
            'currency' => $pricing?->currency ?? 'gbp',
            'pricing' => $pricing,
        ];
    }

    /**
     * @param  array{
     *   amount?: mixed,
     *   billing_unit?: mixed,
     *   recurring_amount?: mixed,
     *   recurring_billing_unit?: mixed,
     *   recurring_tier_slot?: mixed,
     *   is_active?: mixed,
     *   sort_order?: mixed
     * }  $data
     */
    public function updatePricing(ModulePricing $pricing, array $data): ModulePricing
    {
        if (array_key_exists('amount', $data)) {
            $pricing->amount = round((float) $data['amount'], 2);
        }
        if (array_key_exists('billing_unit', $data)) {
            $unit = (string) $data['billing_unit'];
            if (! in_array($unit, self::ONE_TIME_UNITS, true)) {
                throw new InvalidArgumentException(
                    'billing_unit must be one of: '.implode(', ', self::PRICE_UNITS).'.'
                );
            }
            // Persist canonical value (map legacy one_time → per_network).
            $pricing->billing_unit = $unit === self::BILLING_UNIT_ONE_TIME
                ? self::UNIT_PER_NETWORK
                : $unit;
        }
        if (array_key_exists('recurring_amount', $data)) {
            $pricing->recurring_amount = round((float) $data['recurring_amount'], 2);
        }
        if (array_key_exists('recurring_billing_unit', $data)) {
            $unit = (string) $data['recurring_billing_unit'];
            if (! in_array($unit, self::RECURRING_UNITS, true)) {
                throw new InvalidArgumentException('Invalid recurring_billing_unit.');
            }
            // Persist canonical seat unit.
            $pricing->recurring_billing_unit = $unit === ModuleRecurringBillingService::UNIT_PER_ADVISER
                ? self::UNIT_PER_USER_SLOTS
                : $unit;
        }
        if (array_key_exists('recurring_tier_slot', $data)) {
            $slot = $data['recurring_tier_slot'];
            $pricing->recurring_tier_slot = $slot === null || $slot === '' ? null : max(1, min(3, (int) $slot));
        }
        if (array_key_exists('is_active', $data)) {
            $pricing->is_active = (bool) $data['is_active'];
        }
        if (array_key_exists('sort_order', $data)) {
            $pricing->sort_order = (int) $data['sort_order'];
        }
        $pricing->save();

        return $pricing->fresh();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listRecurringTiers(): array
    {
        return \App\Models\ModuleRecurringTier::query()
            ->orderBy('slot')
            ->orderBy('sort_order')
            ->orderBy('min_users')
            ->get()
            ->map(fn ($row) => [
                'id' => $row->id,
                'slot' => (int) $row->slot,
                'min_users' => (int) $row->min_users,
                'max_users' => $row->max_users !== null ? (int) $row->max_users : null,
                'rate_per_user' => (float) $row->rate_per_user,
                'network_margin_per_user' => (float) $row->network_margin_per_user,
                'currency' => $row->currency,
                'is_active' => (bool) $row->is_active,
                'sort_order' => (int) $row->sort_order,
            ])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateRecurringTier(\App\Models\ModuleRecurringTier $tier, array $data): \App\Models\ModuleRecurringTier
    {
        foreach (['min_users', 'max_users', 'sort_order', 'slot'] as $intKey) {
            if (array_key_exists($intKey, $data)) {
                $tier->{$intKey} = $data[$intKey] === null || $data[$intKey] === ''
                    ? null
                    : (int) $data[$intKey];
            }
        }
        foreach (['rate_per_user', 'network_margin_per_user'] as $decKey) {
            if (array_key_exists($decKey, $data)) {
                $tier->{$decKey} = round((float) $data[$decKey], 2);
            }
        }
        if (array_key_exists('is_active', $data)) {
            $tier->is_active = (bool) $data['is_active'];
        }
        $tier->save();

        return $tier->fresh();
    }

    /**
     * @return array{
     *   id: int,
     *   module_key: string,
     *   label: string,
     *   amount: float,
     *   billing_unit: string,
     *   billing_unit_label: string,
     *   recurring_amount: float,
     *   recurring_billing_unit: string,
     *   recurring_billing_unit_label: string,
     *   recurring_tier_slot: ?int,
     *   currency: string,
     *   is_active: bool,
     *   sort_order: int
     * }
     */
    public function serialize(ModulePricing $row): array
    {
        $unit = (string) ($row->billing_unit ?: self::UNIT_PER_NETWORK);
        $recurringUnit = (string) ($row->recurring_billing_unit ?: ModuleRecurringBillingService::UNIT_NONE);

        return [
            'id' => $row->id,
            'module_key' => $row->module_key,
            'label' => (string) (Hub::CHECKLIST_DEFINITIONS[$row->module_key]['label'] ?? $row->module_key),
            'amount' => (float) $row->amount,
            'billing_unit' => $unit,
            'billing_unit_label' => self::billingUnitLabel($unit),
            'recurring_amount' => (float) ($row->recurring_amount ?? 0),
            'recurring_billing_unit' => $recurringUnit,
            'recurring_billing_unit_label' => self::billingUnitLabel($recurringUnit),
            'recurring_tier_slot' => $row->recurring_tier_slot !== null ? (int) $row->recurring_tier_slot : null,
            'currency' => $row->currency,
            'is_active' => (bool) $row->is_active,
            'sort_order' => (int) $row->sort_order,
        ];
    }
}
