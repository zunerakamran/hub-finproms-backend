<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentCheckout extends Model
{
    public const TYPE_POST = 'post';

    public const TYPE_BUNDLE = 'bundle';

    protected $fillable = [
        'user_id',
        'item_type',
        'item_id',
        'credits_cost',
        'amount',
        'payment_method',
        'payment_status',
        'stripe_session_id',
        'payment_reference',
        'stripe_payment_intent',
        'post_purchase_id',
        'bundle_purchase_id',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'credits_cost' => 'integer',
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function postPurchase(): BelongsTo
    {
        return $this->belongsTo(PostPurchase::class);
    }

    public function bundlePurchase(): BelongsTo
    {
        return $this->belongsTo(BundlePurchase::class);
    }

    public function isPaid(): bool
    {
        return $this->payment_status === 'paid';
    }

    public function isBundle(): bool
    {
        return $this->item_type === self::TYPE_BUNDLE;
    }

    public function isPost(): bool
    {
        return $this->item_type === self::TYPE_POST;
    }
}
