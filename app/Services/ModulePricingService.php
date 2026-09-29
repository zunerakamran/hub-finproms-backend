<?php

namespace App\Services;

use App\Models\Hub;
use App\Models\ModulePricing;
use InvalidArgumentException;

class ModulePricingService
{
    public const BILLING_UNIT_ONE_TIME = 'one_time';

    public const BILLING_UNIT_PER_WEBSITE = 'per_website';

    /**
     * Default catalogue prices (amount + billing unit).
     *
     * @var array<string, array{amount: float, billing_unit: string}>
     */
    public const DEFAULT_CATALOGUE = [
        'module_shared_hub' => [
            'amount' => 14500.00,
            'billing_unit' => self::BILLING_UNIT_ONE_TIME,
        ],
        'module_white_label_hub' => [
            'amount' => 14500.00,
            'billing_unit' => self::BILLING_UNIT_ONE_TIME,
        ],
        'module_social_media_template_library' => [
            'amount' => 0.00,
            'billing_unit' => self::BILLING_UNIT_ONE_TIME,
        ],
        'module_social_media_compliance' => [
            'amount' => 5000.00,
            'billing_unit' => self::BILLING_UNIT_ONE_TIME,
        ],
        'module_website_template_library' => [
            'amount' => 300.00,
            'billing_unit' => self::BILLING_UNIT_PER_WEBSITE,
        ],
        'module_website_compliance' => [
            'amount' => 10000.00,
            'billing_unit' => self::BILLING_UNIT_ONE_TIME,
        ],
        'module_general_compliance' => [
            'amount' => 3500.00,
            'billing_unit' => self::BILLING_UNIT_ONE_TIME,
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
                'billing_unit' => self::BILLING_UNIT_ONE_TIME,
            ];
            ModulePricing::query()->create([
                'module_key' => $key,
                'amount' => $meta['amount'],
                'billing_unit' => $meta['billing_unit'],
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
                'billing_unit' => self::BILLING_UNIT_ONE_TIME,
            ];
            ModulePricing::query()->create([
                'module_key' => $key,
                'amount' => $meta['amount'],
                'billing_unit' => $meta['billing_unit'],
                'currency' => 'gbp',
                'is_active' => true,
                'sort_order' => ++$sort,
            ]);
        }
    }

    public static function billingUnitLabel(string $unit): string
    {
        return match ($unit) {
            self::BILLING_UNIT_PER_WEBSITE => 'Per website',
            default => 'One time',
        };
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
            'billing_unit' => $pricing?->billing_unit ?? self::BILLING_UNIT_ONE_TIME,
            'currency' => $pricing?->currency ?? 'gbp',
            'pricing' => $pricing,
        ];
    }

    /**
     * @param  array{amount?: mixed, billing_unit?: mixed, is_active?: mixed, sort_order?: mixed}  $data
     */
    public function updatePricing(ModulePricing $pricing, array $data): ModulePricing
    {
        if (array_key_exists('amount', $data)) {
            $pricing->amount = round((float) $data['amount'], 2);
        }
        if (array_key_exists('billing_unit', $data)) {
            $unit = (string) $data['billing_unit'];
            if (! in_array($unit, [self::BILLING_UNIT_ONE_TIME, self::BILLING_UNIT_PER_WEBSITE], true)) {
                throw new InvalidArgumentException('billing_unit must be one_time or per_website.');
            }
            $pricing->billing_unit = $unit;
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
     * @return array{
     *   id: int,
     *   module_key: string,
     *   label: string,
     *   amount: float,
     *   billing_unit: string,
     *   billing_unit_label: string,
     *   currency: string,
     *   is_active: bool,
     *   sort_order: int
     * }
     */
    public function serialize(ModulePricing $row): array
    {
        $unit = (string) ($row->billing_unit ?: self::BILLING_UNIT_ONE_TIME);

        return [
            'id' => $row->id,
            'module_key' => $row->module_key,
            'label' => (string) (Hub::CHECKLIST_DEFINITIONS[$row->module_key]['label'] ?? $row->module_key),
            'amount' => (float) $row->amount,
            'billing_unit' => $unit,
            'billing_unit_label' => self::billingUnitLabel($unit),
            'currency' => $row->currency,
            'is_active' => (bool) $row->is_active,
            'sort_order' => (int) $row->sort_order,
        ];
    }
}
