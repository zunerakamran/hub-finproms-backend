<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ModulePricing extends Model
{
    protected $table = 'module_pricing';

    protected $fillable = [
        'module_key',
        'amount',
        'billing_unit',
        'currency',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
