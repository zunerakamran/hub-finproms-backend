<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserSubscription extends Model
{
    protected $fillable = [
        'user_id',
        'subscription_plan_id',
        'credits_granted',
        'amount_paid',
        'status',
        'stripe_session_id',
        'stripe_payment_intent',
        'payment_status',
        'payment_method',
        'payment_reference',
        'starts_at',
        'ends_at',
    ];

    protected function casts(): array
    {
        return [
            'credits_granted' => 'integer',
            'amount_paid' => 'decimal:2',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }
}
