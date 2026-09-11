<?php

namespace App\Services;

use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\UserSubscription;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * TEMPORARY payment path for testing until Stripe keys are configured.
 * Enable/disable from Power Admin → Payment methods (or BANK_TRANSFER_ENABLED fallback).
 */
class BankTransferSubscriptionService
{
    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly PaymentSettingsService $paymentSettings
    ) {}

    public function isEnabled(): bool
    {
        return $this->paymentSettings->isBankTransferEnabled();
    }

    /**
     * Testing shortcut: immediately activate and grant credits.
     */
    public function autoConfirm(): bool
    {
        return $this->paymentSettings->isBankTransferAutoConfirm();
    }

    /**
     * Dummy bank details for local/testing use.
     *
     * @return array<string, mixed>
     */
    public function bankDetails(): array
    {
        return [
            'account_name' => 'Hub Finproms Test Account',
            'bank_name' => 'Demo Test Bank',
            'account_number' => '12345678',
            'sort_code' => '12-34-56',
            'iban' => 'GB00TEST12345678901234',
            'swift' => 'TESTGB2L',
            'currency' => 'USD',
            'instructions' => 'TEST MODE: no real transfer needed. Payment is simulated with dummy bank details and credits are granted automatically.',
        ];
    }

    public function createPendingSubscription(User $user, SubscriptionPlan $plan): UserSubscription
    {
        $reference = $this->generateReference($user->id);

        $subscription = UserSubscription::create([
            'user_id' => $user->id,
            'subscription_plan_id' => $plan->id,
            'credits_granted' => $plan->credits,
            'amount_paid' => $plan->price,
            'status' => 'pending',
            'payment_status' => 'pending',
            'payment_method' => 'bank_transfer',
            'payment_reference' => $reference,
            'starts_at' => now(),
            'ends_at' => now()->addDays($plan->duration_days),
        ]);

        if ($this->autoConfirm()) {
            return $this->markPaidAndGrantCredits($subscription);
        }

        $subscription = $subscription->load('plan', 'user');
        app(FunctionalMailService::class)->bankTransferSubscriptionPending($subscription);

        return $subscription;
    }

    public function markPaidAndGrantCredits(UserSubscription $subscription): UserSubscription
    {
        return DB::transaction(function () use ($subscription) {
            $locked = UserSubscription::query()
                ->whereKey($subscription->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->payment_method !== 'bank_transfer') {
                throw new \InvalidArgumentException('Subscription is not a bank transfer payment.');
            }

            if ($locked->payment_status === 'paid') {
                $this->invoices->createForSubscription($locked);

                return $locked->load('plan', 'user');
            }

            $plan = $locked->plan ?? SubscriptionPlan::find($locked->subscription_plan_id);
            $durationDays = $plan?->duration_days ?? 30;

            $locked->update([
                'status' => 'active',
                'payment_status' => 'paid',
                'starts_at' => now(),
                'ends_at' => now()->addDays($durationDays),
            ]);

            $user = User::query()->whereKey($locked->user_id)->lockForUpdate()->first();
            if ($user) {
                $user->increment('credits', $locked->credits_granted);
            }

            $locked = $locked->fresh()->load('plan', 'user');
            $this->invoices->createForSubscription($locked);

            return $locked;
        });
    }

    private function generateReference(int $userId): string
    {
        do {
            $reference = 'BT-TEST-'.$userId.'-'.Str::upper(Str::random(6));
        } while (UserSubscription::query()->where('payment_reference', $reference)->exists());

        return $reference;
    }
}
