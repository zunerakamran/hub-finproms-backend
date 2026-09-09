<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Invoice extends Model
{
    public const TYPE_SUBSCRIPTION = 'subscription';

    public const TYPE_POST_PURCHASE = 'post_purchase';

    public const TYPE_ADVISOR_BILLING = 'advisor_billing';

    protected $fillable = [
        'invoice_number',
        'user_id',
        'type',
        'user_subscription_id',
        'post_purchase_id',
        'hub_advisor_billing_id',
        'description',
        'amount',
        'currency',
        'credits',
        'status',
        'billing_name',
        'billing_email',
        'line_items',
        'issued_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'credits' => 'integer',
            'line_items' => 'array',
            'issued_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(UserSubscription::class, 'user_subscription_id');
    }

    public function postPurchase(): BelongsTo
    {
        return $this->belongsTo(PostPurchase::class, 'post_purchase_id');
    }

    public function advisorBilling(): BelongsTo
    {
        return $this->belongsTo(HubAdvisorBilling::class, 'hub_advisor_billing_id');
    }
}
