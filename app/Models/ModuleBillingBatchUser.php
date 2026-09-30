<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ModuleBillingBatchUser extends Model
{
    protected $fillable = [
        'module_billing_batch_id',
        'user_id',
        'email',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ModuleBillingBatch::class, 'module_billing_batch_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
