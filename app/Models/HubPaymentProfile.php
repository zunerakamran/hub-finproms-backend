<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HubPaymentProfile extends Model
{
    public const SCOPE_NETWORK = 'network';

    public const SCOPE_FIRM = 'firm';

    protected $fillable = [
        'hub_id',
        'scope',
        'firm_id',
        'stripe_customer_id',
        'stripe_payment_method_id',
        'managed_by_user_id',
    ];

    public function hub(): BelongsTo
    {
        return $this->belongsTo(Hub::class);
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function managedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'managed_by_user_id');
    }

    public function hasSavedCard(): bool
    {
        return filled($this->stripe_customer_id) && filled($this->stripe_payment_method_id);
    }
}
