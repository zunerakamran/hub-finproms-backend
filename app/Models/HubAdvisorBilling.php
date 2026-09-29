<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class HubAdvisorBilling extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELED = 'canceled';

    protected $fillable = [
        'hub_id',
        'billed_user_id',
        'advisor_count',
        'rate_per_advisor',
        'amount',
        'currency',
        'status',
        'payment_method',
        'payment_status',
        'auto_renew',
        'payment_reference',
        'stripe_session_id',
        'stripe_subscription_id',
        'stripe_invoice_id',
        'period_starts_at',
        'period_ends_at',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'advisor_count' => 'integer',
            'rate_per_advisor' => 'decimal:2',
            'amount' => 'decimal:2',
            'auto_renew' => 'boolean',
            'meta' => 'array',
            'period_starts_at' => 'datetime',
            'period_ends_at' => 'datetime',
        ];
    }

    public function hub(): BelongsTo
    {
        return $this->belongsTo(Hub::class);
    }

    public function billedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'billed_user_id');
    }

    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class, 'hub_advisor_billing_id');
    }
}
