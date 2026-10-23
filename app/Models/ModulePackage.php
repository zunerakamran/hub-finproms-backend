<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ModulePackage extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'description',
        'amount',
        'recurring_amount',
        'recurring_billing_unit',
        'recurring_tier_slot',
        'billing_unit',
        'currency',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'recurring_amount' => 'decimal:2',
            'recurring_tier_slot' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(ModulePackageItem::class)->orderBy('id');
    }

    /**
     * Product module keys in this package (ordered).
     *
     * @return list<string>
     */
    public function moduleKeys(): array
    {
        return $this->items
            ->pluck('module_key')
            ->map(fn ($key) => (string) $key)
            ->values()
            ->all();
    }

    /**
     * Billing key stored on hub_module_billings.module_key for package invoices.
     */
    public function billingModuleKey(): string
    {
        return 'module_package:'.$this->id;
    }

    public static function parseBillingPackageId(string $moduleKey): ?int
    {
        if (! str_starts_with($moduleKey, 'module_package:')) {
            return null;
        }

        $id = (int) substr($moduleKey, strlen('module_package:'));

        return $id > 0 ? $id : null;
    }
}
