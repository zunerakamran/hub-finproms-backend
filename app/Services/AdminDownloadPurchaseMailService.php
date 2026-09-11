<?php

namespace App\Services;

use App\Mail\AdminDownloadPurchaseMail;
use App\Models\Invoice;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class AdminDownloadPurchaseMailService
{
    public function __construct(
        private readonly HubService $hubs,
        private readonly AdminMailRecipientService $adminRecipients
    ) {}

    /**
     * Notify users with the Receive admin emails capability
     * when a post or bundle download is purchased.
     */
    public function sendForInvoice(Invoice $invoice): void
    {
        if (! in_array($invoice->type, [
            Invoice::TYPE_POST_PURCHASE,
            Invoice::TYPE_BUNDLE_PURCHASE,
        ], true)) {
            return;
        }

        $invoice->loadMissing([
            'user',
            'postPurchase.post',
            'bundlePurchase.bundle',
        ]);

        $hub = $this->hubs->current();
        $recipients = $this->adminRecipients->emailsForHub($hub);
        if ($recipients === []) {
            return;
        }

        $purchaser = $invoice->user;
        $fromEmail = $hub->mailFromAddress();
        $supportEmail = filled($hub->from_email)
            ? (string) $hub->from_email
            : $fromEmail;

        $data = [
            'site_name' => $hub->name,
            'logo_url' => $hub->logoAbsoluteUrl(),
            'primary_color' => $hub->primary_color ?: '#1d4ed8',
            'secondary_color' => $hub->secondary_color ?: '#0f766e',
            'support_email' => $supportEmail,
            'from_email' => $fromEmail,
            'payment_id' => $invoice->invoice_number,
            'purchaser' => $purchaser?->name ?: ($invoice->billing_name ?: 'Unknown'),
            'purchaser_email' => $purchaser?->email ?: $invoice->billing_email,
            'price' => $this->formatMoney((float) $invoice->amount, (string) $invoice->currency),
            'payment_method' => 'Credits',
            'download_list' => $this->downloadList($invoice),
        ];

        foreach ($recipients as $email) {
            try {
                Mail::to($email)->send(new AdminDownloadPurchaseMail($data));
            } catch (Throwable $e) {
                Log::warning('Failed to send admin download purchase email.', [
                    'invoice_id' => $invoice->id,
                    'recipient' => $email,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * @return list<array{label: string, detail: ?string}>
     */
    private function downloadList(Invoice $invoice): array
    {
        $items = is_array($invoice->line_items) ? $invoice->line_items : [];

        if ($items === []) {
            return [[
                'label' => $invoice->description ?: 'Download',
                'detail' => null,
            ]];
        }

        $list = [];
        foreach ($items as $item) {
            $list[] = [
                'label' => (string) ($item['label'] ?? 'Download'),
                'detail' => isset($item['note']) && $item['note'] !== null && $item['note'] !== ''
                    ? (string) $item['note']
                    : null,
            ];
        }

        return $list;
    }

    private function formatMoney(float $amount, string $currency): string
    {
        $currency = strtoupper($currency ?: 'GBP');
        $symbol = match ($currency) {
            'GBP' => '£',
            'USD' => '$',
            'EUR' => '€',
            default => $currency.' ',
        };

        return $symbol.number_format($amount, 2);
    }
}
