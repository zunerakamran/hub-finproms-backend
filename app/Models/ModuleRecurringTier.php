<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ModuleRecurringTier extends Model
{
    protected $table = 'module_recurring_tiers';

    protected $fillable = [
        'slot',
        'min_users',
        'max_users',
        'rate_per_user',
        'network_margin_per_user',
        'currency',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'slot' => 'integer',
            'min_users' => 'integer',
            'max_users' => 'integer',
            'rate_per_user' => 'decimal:2',
            'network_margin_per_user' => 'decimal:2',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
