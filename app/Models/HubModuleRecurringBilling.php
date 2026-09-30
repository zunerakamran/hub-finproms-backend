<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class HubModuleRecurringBilling extends Model
{
    public const STATUS_UNPAID = 'unpaid';

    public const STATUS_PAID = 'paid';

    public const STATUS_CANCELED = 'canceled';

    public const KIND_BATCH = 'batch';

    public const KIND_ANNIVERSARY = 'anniversary';

    public const KIND_FLAT = 'flat';

    protected $fillable = [
        'hub_id',
        'module_billing_batch_id',
        'module_key',
        'billed_user_id',
        'user_count',
        'total_users_for_tier',
        'rate_per_user',
        'amount',
        'currency',
        'status',
        'payment_status',
        'due_on',
        'period_starts_on',
        'period_ends_on',
        'billing_kind',
        'meta',
        'paid_at',
        'paid_by_user_id',
        'payment_method',
        'payment_reference',
        'payment_notes',
    ];

    protected function casts(): array
    {
        return [
            'user_count' => 'integer',
            'total_users_for_tier' => 'integer',
            'rate_per_user' => 'decimal:2',
            'amount' => 'decimal:2',
            'due_on' => 'date',
            'period_starts_on' => 'date',
            'period_ends_on' => 'date',
            'meta' => 'array',
            'paid_at' => 'datetime',
        ];
    }

    public function hub(): BelongsTo
    {
        return $this->belongsTo(Hub::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ModuleBillingBatch::class, 'module_billing_batch_id');
    }

    public function billedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'billed_user_id');
    }

    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by_user_id');
    }

    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class, 'hub_module_recurring_billing_id');
    }

    public function paymentDetailsForApi(): ?array
    {
        if ($this->status !== self::STATUS_PAID && ! $this->paid_at) {
            return null;
        }

        $this->loadMissing('paidBy:id,name,email');

        return [
            'status' => $this->status,
            'payment_status' => $this->payment_status,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'payment_method' => $this->payment_method,
            'payment_reference' => $this->payment_reference,
            'payment_notes' => $this->payment_notes,
            'paid_by' => $this->paidBy ? [
                'id' => $this->paidBy->id,
                'name' => $this->paidBy->name,
                'email' => $this->paidBy->email,
            ] : null,
            'billing_kind' => $this->billing_kind,
            'user_count' => $this->user_count,
            'total_users_for_tier' => $this->total_users_for_tier,
            'rate_per_user' => $this->rate_per_user !== null ? (float) $this->rate_per_user : null,
            'due_on' => $this->due_on?->toDateString(),
        ];
    }

    /**
     * Human-readable calculation used on invoice UI / line notes.
     *
     * @return array{
     *   kind: string,
     *   unit: string,
     *   summary: string,
     *   lines: list<string>,
     *   active_users_for_rate: ?int,
     *   charged_quantity: int,
     *   slot: ?int,
     *   tier_label: ?string,
     *   rate: ?float,
     *   amount: float
     * }
     */
    public function billingBreakdownForApi(): array
    {
        $quote = is_array($this->meta['quote'] ?? null) ? $this->meta['quote'] : [];
        $unit = (string) ($this->meta['recurring_billing_unit'] ?? $quote['billing_unit'] ?? '');
        $kind = (string) $this->billing_kind;
        $qty = max(0, (int) $this->user_count);
        $rate = $this->rate_per_user !== null ? (float) $this->rate_per_user : null;
        $amount = (float) $this->amount;
        $active = $this->total_users_for_tier !== null ? (int) $this->total_users_for_tier : null;
        $slot = isset($quote['slot']) ? (int) $quote['slot'] : null;
        $tier = is_array($quote['tier'] ?? null) ? $quote['tier'] : null;
        $tierLabel = null;
        if ($tier) {
            $min = $tier['min_users'] ?? null;
            $max = $tier['max_users'] ?? null;
            $tierLabel = $max === null
                ? sprintf('%s+ users', $min ?? '?')
                : sprintf('%s–%s users', $min ?? '?', $max);
        }

        $lines = [];
        $summary = '';

        if (in_array($unit, ['per_user', 'per_adviser'], true) || in_array($kind, [self::KIND_BATCH, self::KIND_ANNIVERSARY], true)) {
            $active ??= (int) ($quote['total_users'] ?? 0);
            $slot ??= isset($quote['slot']) ? (int) $quote['slot'] : null;
            $rate ??= isset($quote['rate_per_user']) ? (float) $quote['rate_per_user'] : null;

            $lines[] = sprintf(
                'Active users with this module (used to pick the rate): %d',
                $active
            );
            if ($slot) {
                $lines[] = $tierLabel
                    ? sprintf('Applied pricing slot #%d (%s)', $slot, $tierLabel)
                    : sprintf('Applied pricing slot #%d', $slot);
            } else {
                $lines[] = 'Applied pricing slot: not configured';
            }
            if ($rate !== null) {
                $lines[] = sprintf('Rate from that slot: £%s per user', number_format($rate, 2));
            }
            $cohort = $kind === self::KIND_ANNIVERSARY
                ? 'active users remaining in this billing cohort (anniversary)'
                : 'users in this import batch';
            $lines[] = sprintf('Charged on this invoice: %d (%s)', $qty, $cohort);
            if ($rate !== null) {
                $lines[] = sprintf('%d × £%s = £%s', $qty, number_format($rate, 2), number_format($amount, 2));
            }
            $summary = sprintf(
                '%d active → slot #%s → %d charged @ £%s',
                $active,
                $slot ?: '—',
                $qty,
                number_format($rate ?? 0, 2)
            );
        } elseif ($unit === 'per_website') {
            $lines[] = sprintf('Deployed websites counted (wc_template_requests status=deployed): %d', $qty);
            if ($rate !== null) {
                $lines[] = sprintf('Rate: £%s per website / month', number_format($rate, 2));
                $lines[] = sprintf('%d × £%s = £%s', $qty, number_format($rate, 2), number_format($amount, 2));
            }
            $summary = sprintf('%d website(s) × £%s', $qty, number_format($rate ?? 0, 2));
        } elseif ($unit === 'per_firm') {
            $lines[] = sprintf('Firms counted on this hub: %d', $qty);
            if ($rate !== null) {
                $lines[] = sprintf('Rate: £%s per firm / month', number_format($rate, 2));
                $lines[] = sprintf('%d × £%s = £%s', $qty, number_format($rate, 2), number_format($amount, 2));
            }
            $summary = sprintf('%d firm(s) × £%s', $qty, number_format($rate ?? 0, 2));
        } elseif ($unit === 'per_network') {
            $lines[] = 'Billing unit: per network (flat monthly)';
            $lines[] = sprintf('Amount: £%s', number_format($amount, 2));
            $summary = sprintf('Network flat £%s', number_format($amount, 2));
        } else {
            $lines[] = sprintf('Quantity: %d', $qty);
            if ($rate !== null) {
                $lines[] = sprintf('Rate: £%s', number_format($rate, 2));
            }
            $lines[] = sprintf('Amount: £%s', number_format($amount, 2));
            $summary = sprintf('£%s', number_format($amount, 2));
        }

        if ($this->due_on) {
            $lines[] = 'Due on: '.$this->due_on->toDateString();
        }

        return [
            'kind' => $kind,
            'unit' => $unit !== '' ? $unit : 'seat',
            'summary' => $summary,
            'lines' => $lines,
            'active_users_for_rate' => $active,
            'charged_quantity' => $qty,
            'slot' => $slot,
            'tier_label' => $tierLabel,
            'rate' => $rate,
            'amount' => $amount,
        ];
    }
}
