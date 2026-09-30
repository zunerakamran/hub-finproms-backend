<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ModuleBillingBatch extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_CANCELED = 'canceled';

    protected $fillable = [
        'hub_id',
        'module_key',
        'anniversary_day',
        'started_on',
        'next_invoice_on',
        'status',
        'initial_user_count',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'anniversary_day' => 'integer',
            'started_on' => 'date',
            'next_invoice_on' => 'date',
            'initial_user_count' => 'integer',
            'meta' => 'array',
        ];
    }

    public function hub(): BelongsTo
    {
        return $this->belongsTo(Hub::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(ModuleBillingBatchUser::class, 'module_billing_batch_id');
    }

    public function recurringBillings(): HasMany
    {
        return $this->hasMany(HubModuleRecurringBilling::class, 'module_billing_batch_id');
    }
}
