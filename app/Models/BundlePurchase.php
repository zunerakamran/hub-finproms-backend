<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BundlePurchase extends Model
{
    protected $fillable = [
        'user_id',
        'bundle_id',
        'credits_spent',
        'purchased_at',
    ];

    protected function casts(): array
    {
        return [
            'credits_spent' => 'integer',
            'purchased_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function bundle(): BelongsTo
    {
        return $this->belongsTo(Bundle::class);
    }
}
