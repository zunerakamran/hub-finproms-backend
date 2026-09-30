<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Invoice extends Model
{
    public const TYPE_SUBSCRIPTION = 'subscription';

    public const TYPE_POST_PURCHASE = 'post_purchase';

    public const TYPE_BUNDLE_PURCHASE = 'bundle_purchase';

    public const TYPE_ADVISOR_BILLING = 'advisor_billing';

    public const TYPE_MODULE_BILLING = 'module_billing';

    public const TYPE_MODULE_RECURRING = 'module_recurring';

    /** Billing cadence shown as "Types" in the UI. */
    public const TYPES_ONE_TIME = 'one_time';

    public const TYPES_PER_USER_BUYING = 'per_user_buying';

    public const TYPES_ONGOING = 'ongoing';

    public const TYPES_RECURRING = 'recurring';

    protected $fillable = [
        'invoice_number',
        'user_id',
        'type',
        'types',
        'user_subscription_id',
        'post_purchase_id',
        'bundle_purchase_id',
        'hub_advisor_billing_id',
        'hub_module_billing_id',
        'hub_module_recurring_billing_id',
        'description',
        'amount',
        'currency',
        'credits',
        'status',
        'billing_name',
        'billing_email',
        'line_items',
        'issued_at',
        'due_on',
    ];

    /**
     * Map invoice source type → billing cadence (`types` column).
     */
    public static function typesFor(string $type): string
    {
        return match ($type) {
            self::TYPE_SUBSCRIPTION => self::TYPES_ONGOING,
            self::TYPE_ADVISOR_BILLING => self::TYPES_PER_USER_BUYING,
            self::TYPE_MODULE_RECURRING => self::TYPES_RECURRING,
            self::TYPE_POST_PURCHASE,
            self::TYPE_BUNDLE_PURCHASE,
            self::TYPE_MODULE_BILLING => self::TYPES_ONE_TIME,
            default => self::TYPES_ONE_TIME,
        };
    }

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'credits' => 'integer',
            'line_items' => 'array',
            'issued_at' => 'datetime',
            'due_on' => 'date',
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

    public function bundlePurchase(): BelongsTo
    {
        return $this->belongsTo(BundlePurchase::class, 'bundle_purchase_id');
    }

    public function advisorBilling(): BelongsTo
    {
        return $this->belongsTo(HubAdvisorBilling::class, 'hub_advisor_billing_id');
    }

    public function moduleBilling(): BelongsTo
    {
        return $this->belongsTo(HubModuleBilling::class, 'hub_module_billing_id');
    }

    public function moduleRecurringBilling(): BelongsTo
    {
        return $this->belongsTo(HubModuleRecurringBilling::class, 'hub_module_recurring_billing_id');
    }
}
