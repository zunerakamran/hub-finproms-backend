<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\PostPurchase;
use App\Models\User;
use App\Models\UserSubscription;
use Illuminate\Support\Facades\DB;

class InvoiceService
{
    public function createForSubscription(UserSubscription $subscription): Invoice
    {
        $existing = Invoice::query()
            ->where('user_subscription_id', $subscription->id)
            ->first();

        if ($existing) {
            return $existing->loadMissing(['subscription.plan', 'user']);
        }

        $subscription->loadMissing(['plan', 'user']);
        $user = $subscription->user ?? User::findOrFail($subscription->user_id);
        $plan = $subscription->plan;
        $amount = (float) $subscription->amount_paid;
        $credits = (int) $subscription->credits_granted;
        $planName = $plan?->name ?? 'Subscription';

        return $this->createInvoice([
            'user_id' => $user->id,
            'type' => Invoice::TYPE_SUBSCRIPTION,
            'user_subscription_id' => $subscription->id,
            'post_purchase_id' => null,
            'description' => "Subscription — {$planName}",
            'amount' => $amount,
            'credits' => $credits,
            'billing_name' => $user->name,
            'billing_email' => $user->email,
            'line_items' => [[
                'label' => "{$planName} plan ({$credits} credits)",
                'quantity' => 1,
                'unit_amount' => $amount,
                'total' => $amount,
            ]],
        ]);
    }

    public function createForPostPurchase(PostPurchase $purchase): Invoice
    {
        $existing = Invoice::query()
            ->where('post_purchase_id', $purchase->id)
            ->first();

        if ($existing) {
            return $existing->loadMissing(['postPurchase.post', 'user']);
        }

        $purchase->loadMissing(['post', 'user']);
        $user = $purchase->user ?? User::findOrFail($purchase->user_id);
        $credits = (int) $purchase->credits_spent;
        // 1 credit = £1 for one-off / credit-spend purchases
        $amount = (float) $credits;
        $title = $purchase->post?->title ?? 'Post';

        return $this->createInvoice([
            'user_id' => $user->id,
            'type' => Invoice::TYPE_POST_PURCHASE,
            'user_subscription_id' => null,
            'post_purchase_id' => $purchase->id,
            'description' => "Post purchase — {$title}",
            'amount' => $amount,
            'credits' => $credits,
            'billing_name' => $user->name,
            'billing_email' => $user->email,
            'line_items' => [[
                'label' => $title,
                'quantity' => $credits,
                'unit_amount' => 1.00,
                'total' => $amount,
                'note' => '1 credit = £1',
            ]],
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function createInvoice(array $payload): Invoice
    {
        return DB::transaction(function () use ($payload) {
            for ($attempt = 0; $attempt < 5; $attempt++) {
                try {
                    $invoice = Invoice::create([
                        ...$payload,
                        'invoice_number' => $this->nextInvoiceNumber(),
                        'currency' => 'gbp',
                        'status' => 'paid',
                        'issued_at' => now(),
                    ]);

                    return $invoice->fresh()->load(['subscription.plan', 'postPurchase.post', 'user']);
                } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                    if ($attempt === 4) {
                        throw $e;
                    }
                }
            }

            throw new \RuntimeException('Unable to allocate invoice number.');
        });
    }

    private function nextInvoiceNumber(): string
    {
        $prefix = 'INV-'.now()->format('Ymd').'-';

        $latest = Invoice::query()
            ->where('invoice_number', 'like', $prefix.'%')
            ->lockForUpdate()
            ->orderByDesc('invoice_number')
            ->value('invoice_number');

        $sequence = 1;
        if ($latest && preg_match('/(\d+)$/', $latest, $matches)) {
            $sequence = ((int) $matches[1]) + 1;
        }

        return $prefix.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }
}
