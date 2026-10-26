<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HubModuleResellerPricing extends Model
{
    protected $table = 'hub_module_reseller_pricing';

    protected $fillable = [
        'hub_id',
        'module_key',
        'amount',
        'recurring_amount',
        'currency',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'recurring_amount' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function hub(): BelongsTo
    {
        return $this->belongsTo(Hub::class);
    }
}
