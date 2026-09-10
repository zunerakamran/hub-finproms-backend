<?php

namespace App\Services;

use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\UserSubscription;
use Illuminate\Support\Facades\DB;
use Stripe\Checkout\Session;

class StripeSubscriptionService
{
    private bool $apiKeySet = false;

    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly PaymentSettingsService $paymentSettings
    ) {}

    private function ensureApiKey(): void
    {
        if ($this->apiKeySet) {
            return;
        }

        $this->paymentSettings->applyStripeApiKey();
        $this->apiKeySet = true;
    }

    public function createCheckoutSession(User $user, SubscriptionPlan $plan): Session
    {
        $this->ensureApiKey();
        $frontendUrl = rtrim(config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:5173')), '/');

        $session = Session::create([
            'mode' => 'payment',
            'payment_method_types' => ['card'],
            'customer_email' => $user->email,
            'line_items' => [[
                'price_data' => [
                    'currency' => $this->paymentSettings->stripeCurrency(),
                    'product_data' => [
                        'name' => $plan->name.' Plan',
                        'description' => $plan->description ?: ($plan->credits.' credits'),
                    ],
                    'unit_amount' => (int) round($plan->price * 100),
                ],
                'quantity' => 1,
            ]],
            'success_url' => $frontendUrl.'/subscriptions/success?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $frontendUrl.'/subscriptions?canceled=1',
            'metadata' => [
                'user_id' => (string) $user->id,
                'subscription_plan_id' => (string) $plan->id,
            ],
        ]);

        UserSubscription::create([
            'user_id' => $user->id,
            'subscription_plan_id' => $plan->id,
            'credits_granted' => $plan->credits,
            'amount_paid' => $plan->price,
            'status' => 'pending',
            'payment_status' => 'pending',
            'payment_method' => 'stripe',
            'stripe_session_id' => $session->id,
            'starts_at' => now(),
            'ends_at' => now()->addDays($plan->duration_days),
        ]);

        return $session;
    }

    public function fulfillCheckoutSession(string $sessionId): ?UserSubscription
    {
        $this->ensureApiKey();
        $session = Session::retrieve($sessionId);

        if ($session->payment_status !== 'paid') {
            return null;
        }

        return $this->markPaidAndGrantCredits($session);
    }

    public function markPaidAndGrantCredits(Session $session): ?UserSubscription
    {
        return DB::transaction(function () use ($session) {
            $subscription = UserSubscription::query()
                ->where('stripe_session_id', $session->id)
                ->lockForUpdate()
                ->first();

            if (! $subscription) {
                $userId = (int) ($session->metadata->user_id ?? 0);
                $planId = (int) ($session->metadata->subscription_plan_id ?? 0);

                if (! $userId || ! $planId) {
                    return null;
                }

                $plan = SubscriptionPlan::find($planId);
                if (! $plan) {
                    return null;
                }

                $subscription = UserSubscription::create([
                    'user_id' => $userId,
                    'subscription_plan_id' => $plan->id,
                    'credits_granted' => $plan->credits,
                    'amount_paid' => $plan->price,
                    'status' => 'pending',
                    'payment_status' => 'pending',
                    'payment_method' => 'stripe',
                    'stripe_session_id' => $session->id,
                    'starts_at' => now(),
                    'ends_at' => now()->addDays($plan->duration_days),
                ]);
            }

            if ($subscription->payment_status === 'paid') {
                $this->invoices->createForSubscription($subscription);

                return $subscription->load('plan', 'user');
            }

            $plan = $subscription->plan ?? SubscriptionPlan::find($subscription->subscription_plan_id);
            $durationDays = $plan?->duration_days ?? 30;

            $subscription->update([
                'status' => 'active',
                'payment_status' => 'paid',
                'stripe_payment_intent' => is_string($session->payment_intent)
                    ? $session->payment_intent
                    : ($session->payment_intent->id ?? null),
                'starts_at' => now(),
                'ends_at' => now()->addDays($durationDays),
            ]);

            $user = User::query()->whereKey($subscription->user_id)->lockForUpdate()->first();
            if ($user) {
                $user->increment('credits', $subscription->credits_granted);
                $user->promoteToAdvisorAfterPlanSubscribe();
            }

            $subscription = $subscription->fresh()->load('plan', 'user');
            $this->invoices->createForSubscription($subscription);

            return $subscription;
        });
    }
}
