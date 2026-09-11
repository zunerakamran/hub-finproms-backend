<?php

namespace App\Services;

use App\Mail\OrderConfirmationMail;
use App\Models\Invoice;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class OrderConfirmationMailService
{
    public function __construct(
        private readonly HubService $hubs
    ) {}

    /**
     * Send order confirmation for newly created customer purchase invoices.
     * Skips advisor billing and any invoice that already existed (idempotent callers).
     */
    public function sendForInvoice(Invoice $invoice): void
    {
        if (! in_array($invoice->type, [
            Invoice::TYPE_SUBSCRIPTION,
            Invoice::TYPE_POST_PURCHASE,
            Invoice::TYPE_BUNDLE_PURCHASE,
        ], true)) {
            return;
        }

        $invoice->loadMissing([
            'user',
            'subscription.plan',
            'postPurchase.post',
            'bundlePurchase.bundle',
        ]);

        $user = $invoice->user;
        if (! $user?->email) {
            return;
        }

        $hub = $this->hubs->current();
        $frontendUrl = rtrim((string) config('app.frontend_url', 'http://localhost:5173'), '/');
        $primary = $hub->primary_color ?: '#1d4ed8';
        $secondary = $hub->secondary_color ?: '#0f766e';
        $fromEmail = $hub->mailFromAddress();
        $supportEmail = filled($hub->from_email)
            ? (string) $hub->from_email
            : $fromEmail;

        $data = [
            'username' => $user->name ?: 'there',
            'site_name' => $hub->name,
            'logo_url' => $hub->logoAbsoluteUrl(),
            'primary_color' => $primary,
            'secondary_color' => $secondary,
            'support_email' => $supportEmail,
            'from_email' => $fromEmail,
            'receipt_id' => $invoice->invoice_number,
            'date' => optional($invoice->issued_at)->timezone(config('app.timezone'))->format('d M Y, H:i')
                ?? now()->format('d M Y, H:i'),
            'payment_method' => $this->paymentMethodLabel($invoice),
            'price' => $this->formatMoney((float) $invoice->amount, (string) $invoice->currency),
            'order_list' => $this->orderList($invoice),
            'invoice_url' => $frontendUrl.'/invoices/'.$invoice->id,
            'login_url' => $frontendUrl.'/login',
        ];

        try {
            Mail::to($user->email)->send(new OrderConfirmationMail($data));
        } catch (Throwable $e) {
            Log::warning('Failed to send order confirmation email.', [
                'invoice_id' => $invoice->id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function paymentMethodLabel(Invoice $invoice): string
    {
        if ($invoice->type === Invoice::TYPE_SUBSCRIPTION) {
            $method = strtolower((string) ($invoice->subscription?->payment_method ?? ''));

            return match ($method) {
                'stripe' => 'Card (Stripe)',
                'bank_transfer' => 'Bank transfer',
                default => $method !== '' ? ucwords(str_replace('_', ' ', $method)) : 'Card',
            };
        }

        return 'Credits';
    }

    /**
     * @return list<array{label: string, detail: ?string, amount: string}>
     */
    private function orderList(Invoice $invoice): array
    {
        $currency = (string) $invoice->currency;
        $items = is_array($invoice->line_items) ? $invoice->line_items : [];

        if ($items === []) {
            return [[
                'label' => $invoice->description ?: 'Order',
                'detail' => null,
                'amount' => $this->formatMoney((float) $invoice->amount, $currency),
            ]];
        }

        $list = [];
        foreach ($items as $item) {
            $label = (string) ($item['label'] ?? 'Item');
            $detail = isset($item['note']) && $item['note'] !== null && $item['note'] !== ''
                ? (string) $item['note']
                : null;
            $amount = array_key_exists('total', $item)
                ? $this->formatMoney((float) $item['total'], $currency)
                : $this->formatMoney((float) $invoice->amount, $currency);

            $list[] = [
                'label' => $label,
                'detail' => $detail,
                'amount' => $amount,
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
