<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Storage;

class HubModuleBilling extends Model
{
    public const STATUS_PAID = 'paid';

    public const STATUS_UNPAID = 'unpaid';

    public const STATUS_CANCELED = 'canceled';

    protected $fillable = [
        'hub_id',
        'module_key',
        'billed_user_id',
        'amount',
        'currency',
        'status',
        'payment_status',
        'meta',
        'paid_at',
        'paid_by_user_id',
        'payment_method',
        'payment_reference',
        'payment_notes',
        'payment_attachment_path',
        'payment_attachment_name',
        'payment_attachment_mime',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'meta' => 'array',
            'paid_at' => 'datetime',
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

    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by_user_id');
    }

    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class, 'hub_module_billing_id');
    }

    public function paymentAttachmentUrl(): ?string
    {
        if (! $this->payment_attachment_path) {
            return null;
        }

        return Storage::disk('public')->url($this->payment_attachment_path);
    }

    /**
     * Settlement details for API / invoice UI.
     *
     * @return array<string, mixed>|null
     */
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
            'attachment' => $this->payment_attachment_path ? [
                'name' => $this->payment_attachment_name,
                'mime' => $this->payment_attachment_mime,
                'url' => $this->paymentAttachmentUrl(),
            ] : null,
            'auto_paid_on_hub_enable' => (bool) ($this->meta['auto_paid_on_hub_enable'] ?? false),
        ];
    }

    /**
     * @return array{kind: string, unit: string, summary: string, lines: list<string>, amount: float}
     */
    public function billingBreakdownForApi(): array
    {
        $amount = (float) $this->amount;
        $unit = (string) ($this->meta['billing_unit'] ?? 'one_time');
        $requestId = (int) ($this->meta['wc_template_request_id'] ?? 0);
        $websiteCount = (int) ($this->meta['website_count'] ?? 0);
        $unitAmount = isset($this->meta['unit_amount'])
            ? (float) $this->meta['unit_amount']
            : $amount;
        $domain = (string) ($this->meta['domain_name'] ?? '');
        $lines = [];

        if ($websiteCount > 1 || ! empty($this->meta['consolidated_websites']) || ! empty($this->meta['template_count'])) {
            $count = max(1, (int) ($this->meta['template_count'] ?? $websiteCount));
            $billedByTemplates = ($this->meta['billed_by'] ?? '') === 'wc_templates'
                || ! empty($this->meta['wc_template_ids']);
            $unitLabel = $billedByTemplates ? 'template' : 'website';
            $lines[] = sprintf(
                'One-time Website Template Library charge for %d %s%s.',
                $count,
                $unitLabel,
                $count === 1 ? '' : 's'
            );
            $lines[] = sprintf('Unit rate: £%s per %s', number_format($unitAmount, 2), $unitLabel);
            $lines[] = sprintf('Total: £%s × %d = £%s', number_format($unitAmount, 2), $count, number_format($amount, 2));
            $names = array_values(array_filter(array_map('strval', (array) ($this->meta['template_names'] ?? $this->meta['domains'] ?? []))));
            if ($names !== []) {
                $lines[] = ($billedByTemplates ? 'Templates: ' : 'Domains: ').implode(', ', $names);
            }
            $summary = sprintf('%d %ss — £%s', $count, $unitLabel, number_format($amount, 2));
        } elseif ($requestId > 0 || $unit === 'per_website') {
            $lines[] = 'One-time charge for a deployed website (Website Template Library).';
            if ($domain !== '') {
                $lines[] = 'Domain: '.$domain;
            }
            if ($requestId > 0) {
                $lines[] = 'Template request #'.$requestId;
            }
            $lines[] = sprintf('Amount: £%s per website', number_format($amount, 2));
            $summary = $domain !== ''
                ? sprintf('Website %s — £%s', $domain, number_format($amount, 2))
                : sprintf('1 website — £%s', number_format($amount, 2));
        } else {
            $lines[] = 'One-time module enablement charge.';
            $lines[] = sprintf('Amount: £%s', number_format($amount, 2));
            $summary = sprintf('One-time £%s', number_format($amount, 2));
        }

        return [
            'kind' => 'one_time',
            'unit' => $unit,
            'summary' => $summary,
            'lines' => $lines,
            'amount' => $amount,
        ];
    }
}
