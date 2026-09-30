<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ModulePricing extends Model
{
    protected $table = 'module_pricing';

    protected $fillable = [
        'module_key',
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
}
