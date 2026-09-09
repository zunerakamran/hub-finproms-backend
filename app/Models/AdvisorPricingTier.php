<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdvisorPricingTier extends Model
{
    protected $table = 'advisor_pricing_tiers';

    protected $fillable = [
        'label',
        'min_advisors',
        'rate_per_advisor',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'min_advisors' => 'integer',
            'rate_per_advisor' => 'decimal:2',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}