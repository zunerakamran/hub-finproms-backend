<?php

namespace App\Services;

use App\Models\Hub;
use App\Models\HubAdvisorBilling;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Stripe\Checkout\Session;
use Stripe\Customer;
use Stripe\PaymentIntent;
use Stripe\PaymentMethod;
use Stripe\Subscription;

class AdvisorBillingService
{
    private bool $apiKeySet = false;

    public function __construct(
        private readonly AdvisorPricingService $pricing,
        private readonly InvoiceService $invoices,
        private readonly PaymentSettingsService $paymentSettings,
        private readonly BankTransferSubscriptionService $bankTransfer,
        private readonly HubService $hubs
    ) {}

    public function billingEnabled(?Hub $hub = null): bool
    {
        $hub ??= $this->hubs->current();

        // Private invite-only hubs always use client-admin advisor billing.
        return $hub->can('advisor_subscriber_billing') || $hub->can('private_invite_only');
    }

    /**
     * Prefer the importer when they are the white-label client admin;
     * otherwise the first client_admin (payer of record for this deployment).
     */
    public function resolvePayer(User $importer): User
    {
        if (in_array($importer->role, [User::ROLE_CLIENT_ADMIN, 'admin'], true)) {
            return $importer;
        }

        $clientAdmin = User::query()
            ->whereIn('role', [User::ROLE_CLIENT_ADMIN, 'admin'])
            ->orderBy('id')
            ->first();

        return $clientAdmin ?: $importer;
    }

    public function payerHasSavedCard(User $payer): bool
    {
        return filled($payer->stripe_customer_id) && filled($payer->stripe_payment_method_id);
    }

    /**
     * Card on file summary for the client-admin dashboard (not a capabilities matrix item).
     *
     * @return array<string, mixed>
     */
    public function paymentProfile(User $user): array
    {
        $hub = $this->hubs->current();
        $methods = $this->paymentSettings->publicMethods(
            fn () => $this->bankTransfer->bankDetails()
        );
        $stripe = collect($methods)->firstWhere('id', 'stripe');

        $card = null;
        if ($this->payerHasSavedCard($user)) {
            $card = $this->retrieveCardSummary($user);
        }

        return [
            'billing_enabled' => $this->billingEnabled($hub),
            'has_saved_card' => $this->payerHasSavedCard($user),
            'card' => $card,
            'stripe_available' => (bool) ($stripe['available'] ?? false),
            'stripe_unavailable_reason' => $stripe['unavailable_reason'] ?? null,
            'renew_day' => $hub->advisorBillingRenewDay(),
            'next_renewal_at' => $this->nextRenewalAt($hub)->toIso8601String(),
        ];
    }

    /**
     * Stripe Checkout (setup mode) so client admin can enter/update their card.
     *
     * @return array<string, mixed>
     */
    public function createCardSetupSession(User $user): array
    {
        if (! $this->billingEnabled()) {
            throw new InvalidArgumentException('Advisor billing is not enabled for this hub.');
        }

        $methods = collect($this->paymentSettings->publicMethods(
            fn () => $this->bankTransfer->bankDetails()
        ))->keyBy('id');

        if (! ($methods['stripe']['available'] ?? false)) {
            throw new InvalidArgumentException(
                $methods['stripe']['unavailable_reason'] ?? 'Stripe is not available.'
            );
        }

        $this->ensureApiKey();
        $customerId = $this->ensureStripeCustomer($user);
        $frontendUrl = rtrim(config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:5173')), '/');

        $session = Session::create([
            'mode' => 'setup',
            'customer' => $customerId,
            'payment_method_types' => ['card'],
            'success_url' => $frontendUrl.'/client-admin/payment-card/success?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $frontendUrl.'/client-admin/payment-card?canceled=1',
            'metadata' => [
                'type' => 'client_admin_card_setup',
                'user_id' => (string) $user->id,
                'hub_id' => (string) $this->hubs->current()->id,
            ],
        ]);

        return [
            'checkout_url' => $session->url,
            'session_id' => $session->id,
            'saves_card' => true,
        ];
    }

    /**
     * Persist card from a completed Stripe setup Checkout session.
     *
     * @return array<string, mixed>
     */
    public function fulfillCardSetupSession(string $sessionId, User $user): array
    {
        $this->ensureApiKey();
        $session = Session::retrieve($sessionId, [
            'expand' => ['setup_intent', 'customer'],
        ]);

        $metaUserId = (int) ($session->metadata->user_id ?? 0);
        if ($metaUserId && $metaUserId !== (int) $user->id && ! $user->isPowerAdmin()) {
            throw new InvalidArgumentException('This card setup session belongs to another user.');
        }

        if (($session->mode ?? null) !== 'setup') {
            // Allow billing checkout sessions to also save the card via existing fulfill path.
            $billing = $this->fulfillStripeSession($sessionId);
            if ($billing) {
                $payer = User::query()->find($billing->billed_user_id) ?: $user;

                return $this->paymentProfile($payer->fresh());
            }

            throw new InvalidArgumentException('Unexpected Stripe session mode.');
        }

        $customerId = is_object($session->customer)
            ? ($session->customer->id ?? null)
            : ($session->customer ?? null);

        $setupIntent = $session->setup_intent ?? null;
        $pmId = null;
        if (is_object($setupIntent)) {
            $pm = $setupIntent->payment_method ?? null;
            $pmId = is_object($pm) ? ($pm->id ?? null) : (is_string($pm) ? $pm : null);
        } elseif (is_string($setupIntent) && $setupIntent !== '') {
            try {
                $intent = \Stripe\SetupIntent::retrieve($setupIntent);
                $pm = $intent->payment_method ?? null;
                $pmId = is_object($pm) ? ($pm->id ?? null) : (is_string($pm) ? $pm : null);
            } catch (\Throwable $e) {
                // ignore
            }
        }

        if (! $customerId || ! $pmId) {
            throw new RuntimeException('Could not read card from Stripe setup session.');
        }

        $user->stripe_customer_id = $customerId;
        $user->stripe_payment_method_id = $pmId;

        try {
            PaymentMethod::retrieve($pmId)->attach(['customer' => $customerId]);
        } catch (\Throwable $e) {
            // already attached
        }

        try {
            Customer::update($customerId, [
                'invoice_settings' => ['default_payment_method' => $pmId],
            ]);
        } catch (\Throwable $e) {
            // ignore
        }

        $user->save();

        return $this->paymentProfile($user->fresh());
    }

    /**
     * @return array{brand: ?string, last4: ?string, exp_month: ?int, exp_year: ?int}|null
     */
    private function retrieveCardSummary(User $payer): ?array
    {
        try {
            $this->ensureApiKey();
            $pm = PaymentMethod::retrieve($payer->stripe_payment_method_id);
            $card = $pm->card ?? null;

            return [
                'brand' => $card->brand ?? null,
                'last4' => $card->last4 ?? null,
                'exp_month' => isset($card->exp_month) ? (int) $card->exp_month : null,
                'exp_year' => isset($card->exp_year) ? (int) $card->exp_year : null,
            ];
        } catch (\Throwable $e) {
            return [
                'brand' => null,
                'last4' => null,
                'exp_month' => null,
                'exp_year' => null,
            ];
        }
    }

    private function ensureStripeCustomer(User $user): string
    {
        $this->ensureApiKey();

        if (filled($user->stripe_customer_id)) {
            return $user->stripe_customer_id;
        }

        $customer = Customer::create([
            'email' => $user->email,
            'name' => $user->name,
            'metadata' => [
                'user_id' => (string) $user->id,
                'role' => (string) $user->role,
            ],
        ]);

        $user->stripe_customer_id = $customer->id;
        $user->save();

        return $customer->id;
    }

    /**
     * Create a pending billing quote after advisor import (rate × advisor count).
     *
     * @param  array<string, mixed>  $importSummary
     */
    public function createPendingAfterImport(User $importer, array $importSummary = []): ?HubAdvisorBilling
    {
        $hub = $this->hubs->current();

        if (! $this->billingEnabled($hub)) {
            return null;
        }

        $created = (int) ($importSummary['created'] ?? 0);
        $updated = (int) ($importSummary['updated'] ?? 0);
        $advisorCount = $this->pricing->currentAdvisorCount();

        // Still quote when advisors exist on the hub (e.g. re-import / update).
        if ($advisorCount < 1) {
            return null;
        }

        // Bill whenever this import added/updated advisors, or headcount changed.
        if ($created === 0 && $updated === 0) {
            return null;
        }

        $this->pricing->seedDefaultsIfEmpty();
        $this->pricing->assertHasTiers();
        $quote = $this->pricing->quote($advisorCount);

        if ($quote['amount'] <= 0) {
            throw new RuntimeException(
                'Advisor billing amount is £0. Configure advisor pricing tiers first.'
            );
        }

        $payer = $this->resolvePayer($importer);
        $periodEnds = $this->nextRenewalAt($hub);

        HubAdvisorBilling::query()
            ->where('hub_id', $hub->id)
            ->where('status', HubAdvisorBilling::STATUS_PENDING)
            ->where('payment_status', 'pending')
            ->update(['status' => HubAdvisorBilling::STATUS_CANCELED]);

        return HubAdvisorBilling::query()->create([
            'hub_id' => $hub->id,
            'billed_user_id' => $payer->id,
            'advisor_count' => $quote['advisor_count'],
            'rate_per_advisor' => $quote['rate_per_advisor'],
            'amount' => $quote['amount'],
            'currency' => $quote['currency'],
            'status' => HubAdvisorBilling::STATUS_PENDING,
            'payment_status' => 'pending',
            'auto_renew' => true,
            'period_starts_at' => now(),
            'period_ends_at' => $periodEnds,
            'meta' => [
                'import_summary' => $importSummary,
                'tier' => $quote['tier'],
                'formula' => 'rate_per_advisor × advisor_count',
                'payer_role' => $payer->role,
                'renew_day' => $hub->advisorBillingRenewDay(),
            ],
        ]);
    }

    public function nextRenewalAt(?Hub $hub = null): Carbon
    {
        $hub ??= $this->hubs->current();
        $day = $hub->advisorBillingRenewDay();
        $next = now()->copy()->startOfDay();

        if ($next->day >= $day) {
            $next->addMonthNoOverflow();
        }

        return $next->day(min($day, $next->daysInMonth))->endOfDay();
    }

    /**
     * Cancel Stripe advisor auto-renew and mark open billings so private billing stops
     * when a hub is switched from private invite-only to public.
     */
    public function stopAutoRenewForHub(Hub $hub): void
    {
        if (filled($hub->advisor_stripe_subscription_id)) {
            try {
                $this->ensureApiKey();
                Subscription::update($hub->advisor_stripe_subscription_id, ['cancel_at_period_end' => false]);
                Subscription::retrieve($hub->advisor_stripe_subscription_id)->cancel();
            } catch (\Throwable $e) {
                // Subscription may already be gone; still clear local state below.
            }

            $hub->advisor_stripe_subscription_id = null;
            $hub->save();
        }

        HubAdvisorBilling::query()
            ->where('hub_id', $hub->id)
            ->where('auto_renew', true)
            ->update(['auto_renew' => false]);

        HubAdvisorBilling::query()
            ->where('hub_id', $hub->id)
            ->where('status', HubAdvisorBilling::STATUS_PENDING)
            ->update([
                'status' => HubAdvisorBilling::STATUS_CANCELED,
                'auto_renew' => false,
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function checkout(HubAdvisorBilling $billing, User $user, string $paymentMethod): array
    {
        if ($billing->billed_user_id !== $user->id && ! $user->isClientAdmin() && ! $user->isPowerAdmin()) {
            throw new InvalidArgumentException('You cannot pay this billing.');
        }

        if ($billing->status === HubAdvisorBilling::STATUS_PAID || $billing->payment_status === 'paid') {
            throw new InvalidArgumentException('This billing is already paid.');
        }

        if ($billing->status === HubAdvisorBilling::STATUS_CANCELED) {
            throw new InvalidArgumentException('This billing was canceled. Import again to create a new quote.');
        }

        $payer = User::query()->findOrFail($billing->billed_user_id);

        if ($paymentMethod === 'saved_card') {
            if (! $this->payerHasSavedCard($payer)) {
                throw new InvalidArgumentException('No saved card on file for the client admin. Choose Stripe to add a card.');
            }

            return $this->chargeSavedCard($billing, $payer);
        }

        $methods = collect($this->paymentSettings->publicMethods(
            fn () => $this->bankTransfer->bankDetails()
        ))->keyBy('id');

        if (! ($methods[$paymentMethod]['available'] ?? false)) {
            throw new InvalidArgumentException(
                $methods[$paymentMethod]['unavailable_reason'] ?? 'Payment method unavailable.'
            );
        }

        if ($paymentMethod === 'stripe') {
            // Card already on file from client-admin Card settings → charge it.
            if ($this->payerHasSavedCard($payer)) {
                return $this->chargeSavedCard($billing, $payer);
            }

            return $this->checkoutStripe($billing, $payer);
        }

        if ($paymentMethod === 'bank_transfer') {
            return $this->checkoutBankTransfer($billing, $payer);
        }

        throw new InvalidArgumentException('Unsupported payment method.');
    }

    /**
     * Charge the client admin's saved card and keep / update the auto-renew subscription.
     *
     * @return array<string, mixed>
     */
    private function chargeSavedCard(HubAdvisorBilling $billing, User $payer): array
    {
        $this->ensureApiKey();
        $hub = $billing->hub ?? Hub::query()->findOrFail($billing->hub_id);

        $billing->update([
            'payment_method' => 'stripe',
            'auto_renew' => true,
            'billed_user_id' => $payer->id,
        ]);

        // Prefer updating an existing hub subscription quantity; otherwise create one.
        if (filled($hub->advisor_stripe_subscription_id)) {
            try {
                $this->updateStripeSubscriptionQuantity($hub, $billing, $payer);
                $billing = $this->markPaid($billing->fresh());

                return [
                    'payment_method' => 'saved_card',
                    'auto_renew' => true,
                    'charged_saved_card' => true,
                    'billing' => $billing,
                    'invoice' => $billing->invoice,
                ];
            } catch (\Throwable $e) {
                // Fall through to PaymentIntent charge + recreate subscription.
            }
        }

        $intent = PaymentIntent::create([
            'amount' => (int) round(((float) $billing->amount) * 100),
            'currency' => $billing->currency ?: $this->paymentSettings->stripeCurrency(),
            'customer' => $payer->stripe_customer_id,
            'payment_method' => $payer->stripe_payment_method_id,
            'off_session' => true,
            'confirm' => true,
            'description' => sprintf(
                'Advisor billing: %d × %s',
                $billing->advisor_count,
                number_format((float) $billing->rate_per_advisor, 2)
            ),
            'metadata' => [
                'type' => 'advisor_billing',
                'hub_advisor_billing_id' => (string) $billing->id,
                'hub_id' => (string) $billing->hub_id,
            ],
        ]);

        if (($intent->status ?? null) !== 'succeeded') {
            throw new RuntimeException('Saved card charge failed. Please pay with Stripe Checkout to update the card.');
        }

        try {
            $subscription = $this->createOrReplaceSubscription($hub, $billing, $payer);
            $billing->stripe_subscription_id = $subscription->id;
            $hub->advisor_stripe_subscription_id = $subscription->id;
            $hub->save();
        } catch (\Throwable $e) {
            // Invoice still issued for the successful charge even if subscription recreate fails.
        }

        $billing = $this->markPaid($billing->fresh());

        return [
            'payment_method' => 'saved_card',
            'auto_renew' => true,
            'charged_saved_card' => true,
            'billing' => $billing,
            'invoice' => $billing->invoice,
        ];
    }

    private function updateStripeSubscriptionQuantity(Hub $hub, HubAdvisorBilling $billing, User $payer): void
    {
        $this->ensureApiKey();

        $subscription = Subscription::retrieve($hub->advisor_stripe_subscription_id);
        $itemId = $subscription->items->data[0]->id ?? null;
        if (! $itemId) {
            throw new RuntimeException('Stripe subscription has no items.');
        }

        // Quantity update invoices the prorated difference for new advisors.
        Subscription::update($hub->advisor_stripe_subscription_id, [
            'items' => [[
                'id' => $itemId,
                'quantity' => max(1, (int) $billing->advisor_count),
            ]],
            'proration_behavior' => 'always_invoice',
            'default_payment_method' => $payer->stripe_payment_method_id,
            'metadata' => [
                'type' => 'advisor_billing',
                'hub_id' => (string) $hub->id,
                'hub_advisor_billing_id' => (string) $billing->id,
            ],
        ]);

        $billing->stripe_subscription_id = $hub->advisor_stripe_subscription_id;
        $billing->save();
    }

    private function createOrReplaceSubscription(Hub $hub, HubAdvisorBilling $billing, User $payer): Subscription
    {
        $this->ensureApiKey();

        if (filled($hub->advisor_stripe_subscription_id)) {
            try {
                Subscription::update($hub->advisor_stripe_subscription_id, ['cancel_at_period_end' => false]);
                Subscription::retrieve($hub->advisor_stripe_subscription_id)->cancel();
            } catch (\Throwable $e) {
                // ignore — create a fresh subscription below
            }
        }

        $anchor = $this->nextRenewalAt($hub)->timestamp;

        return Subscription::create([
            'customer' => $payer->stripe_customer_id,
            'default_payment_method' => $payer->stripe_payment_method_id,
            'items' => [[
                'price_data' => [
                    'currency' => $billing->currency ?: $this->paymentSettings->stripeCurrency(),
                    'product_data' => [
                        'name' => 'Advisor hub subscription — '.$hub->name,
                    ],
                    'unit_amount' => (int) round(((float) $billing->rate_per_advisor) * 100),
                    'recurring' => ['interval' => 'month'],
                ],
                'quantity' => max(1, (int) $billing->advisor_count),
            ]],
            'metadata' => [
                'type' => 'advisor_billing',
                'hub_id' => (string) $hub->id,
                'hub_advisor_billing_id' => (string) $billing->id,
                'renew_day' => (string) $hub->advisorBillingRenewDay(),
                'next_renewal_hint' => (string) $anchor,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function checkoutStripe(HubAdvisorBilling $billing, User $payer): array
    {
        $this->ensureApiKey();
        $hub = $billing->hub ?? Hub::query()->findOrFail($billing->hub_id);
        $frontendUrl = rtrim(config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:5173')), '/');

        $params = [
            'mode' => 'subscription',
            'payment_method_types' => ['card'],
            'line_items' => [[
                'price_data' => [
                    'currency' => $billing->currency ?: $this->paymentSettings->stripeCurrency(),
                    'product_data' => [
                        'name' => 'Advisor hub subscription',
                        'description' => sprintf(
                            '%d advisors × %s / advisor / month (auto-renew day %d)',
                            $billing->advisor_count,
                            number_format((float) $billing->rate_per_advisor, 2),
                            $hub->advisorBillingRenewDay()
                        ),
                    ],
                    'unit_amount' => (int) round(((float) $billing->rate_per_advisor) * 100),
                    'recurring' => ['interval' => 'month'],
                ],
                'quantity' => max(1, (int) $billing->advisor_count),
            ]],
            'success_url' => $frontendUrl.'/client-admin/advisor-billing/success?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $frontendUrl.'/client-admin/advisors?billing_canceled=1',
            'client_reference_id' => (string) $payer->id,
            'metadata' => [
                'type' => 'advisor_billing',
                'hub_advisor_billing_id' => (string) $billing->id,
                'user_id' => (string) $payer->id,
                'hub_id' => (string) $billing->hub_id,
            ],
            'subscription_data' => [
                'metadata' => [
                    'type' => 'advisor_billing',
                    'hub_advisor_billing_id' => (string) $billing->id,
                    'hub_id' => (string) $billing->hub_id,
                    'renew_day' => (string) $hub->advisorBillingRenewDay(),
                ],
            ],
        ];

        if (filled($payer->stripe_customer_id)) {
            $params['customer'] = $payer->stripe_customer_id;
        } else {
            $params['customer_email'] = $payer->email;
        }

        $session = Session::create($params);

        $billing->update([
            'payment_method' => 'stripe',
            'auto_renew' => true,
            'stripe_session_id' => $session->id,
            'payment_status' => 'pending',
            'status' => HubAdvisorBilling::STATUS_PENDING,
            'billed_user_id' => $payer->id,
        ]);

        return [
            'payment_method' => 'stripe',
            'auto_renew' => true,
            'checkout_url' => $session->url,
            'billing' => $billing->fresh(),
            'saves_card' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkoutBankTransfer(HubAdvisorBilling $billing, User $payer): array
    {
        $reference = 'ADV-'.strtoupper(Str::random(8)).'-'.$billing->id;

        $billing->update([
            'payment_method' => 'bank_transfer',
            'auto_renew' => false,
            'payment_reference' => $reference,
            'payment_status' => 'pending',
            'status' => HubAdvisorBilling::STATUS_PENDING,
            'billed_user_id' => $payer->id,
        ]);

        if ($this->bankTransfer->autoConfirm()) {
            $billing = $this->markPaid($billing);

            return [
                'payment_method' => 'bank_transfer',
                'auto_renew' => false,
                'auto_confirmed' => true,
                'billing' => $billing,
                'invoice' => $billing->invoice,
                'bank_details' => $this->bankTransfer->bankDetails(),
            ];
        }

        return [
            'payment_method' => 'bank_transfer',
            'auto_renew' => false,
            'auto_confirmed' => false,
            'billing' => $billing->fresh(),
            'bank_details' => $this->bankTransfer->bankDetails(),
            'payment_reference' => $reference,
        ];
    }

    public function fulfillStripeSession(string $sessionId): ?HubAdvisorBilling
    {
        $this->ensureApiKey();
        $session = Session::retrieve($sessionId, [
            'expand' => ['subscription', 'customer', 'setup_intent'],
        ]);

        $billing = HubAdvisorBilling::query()
            ->where('stripe_session_id', $sessionId)
            ->first();

        if (! $billing) {
            $billingId = (int) ($session->metadata->hub_advisor_billing_id ?? 0);
            if ($billingId) {
                $billing = HubAdvisorBilling::query()->find($billingId);
            }
        }

        if (! $billing) {
            return null;
        }

        $payer = User::query()->find($billing->billed_user_id);
        $hub = Hub::query()->find($billing->hub_id);

        $customerId = is_object($session->customer)
            ? ($session->customer->id ?? null)
            : ($session->customer ?? null);

        $subscriptionId = is_object($session->subscription)
            ? ($session->subscription->id ?? null)
            : ($session->subscription ?? null);

        if ($subscriptionId) {
            $billing->stripe_subscription_id = $subscriptionId;
            if ($hub) {
                $hub->advisor_stripe_subscription_id = $subscriptionId;
                $hub->save();
            }
        }

        if ($payer && $customerId) {
            $payer->stripe_customer_id = $customerId;
            $pmId = $this->extractDefaultPaymentMethod($session, $customerId, $subscriptionId);
            if ($pmId) {
                $payer->stripe_payment_method_id = $pmId;
                try {
                    PaymentMethod::retrieve($pmId)->attach(['customer' => $customerId]);
                } catch (\Throwable $e) {
                    // already attached
                }
                try {
                    Customer::update($customerId, [
                        'invoice_settings' => ['default_payment_method' => $pmId],
                    ]);
                } catch (\Throwable $e) {
                    // ignore
                }
            }
            $payer->save();
        }

        $paid = ($session->payment_status ?? null) === 'paid'
            || ($session->status ?? null) === 'complete';

        if (! $paid && ($session->mode ?? null) === 'subscription') {
            $paid = ($session->status ?? null) === 'complete';
        }

        if (! $paid) {
            $billing->save();

            return $billing;
        }

        $billing->auto_renew = true;
        $billing->payment_method = 'stripe';

        return $this->markPaid($billing);
    }

    private function extractDefaultPaymentMethod(object $session, string $customerId, ?string $subscriptionId): ?string
    {
        if ($subscriptionId) {
            try {
                $sub = Subscription::retrieve($subscriptionId);
                $pm = $sub->default_payment_method ?? null;
                if (is_object($pm)) {
                    return $pm->id ?? null;
                }
                if (is_string($pm) && $pm !== '') {
                    return $pm;
                }
            } catch (\Throwable $e) {
                // continue
            }
        }

        try {
            $list = PaymentMethod::all([
                'customer' => $customerId,
                'type' => 'card',
                'limit' => 1,
            ]);
            $first = $list->data[0] ?? null;

            return $first->id ?? null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function fulfillStripeSubscriptionInvoice(object $stripeInvoice): ?HubAdvisorBilling
    {
        $subscriptionId = is_object($stripeInvoice->subscription ?? null)
            ? ($stripeInvoice->subscription->id ?? null)
            : ($stripeInvoice->subscription ?? null);

        if (! $subscriptionId) {
            return null;
        }

        $parent = HubAdvisorBilling::query()
            ->where('stripe_subscription_id', $subscriptionId)
            ->orderByDesc('id')
            ->first();

        if (! $parent) {
            $hub = Hub::query()->where('advisor_stripe_subscription_id', $subscriptionId)->first();
            if ($hub) {
                $parent = HubAdvisorBilling::query()
                    ->where('hub_id', $hub->id)
                    ->orderByDesc('id')
                    ->first();
            }
        }

        if (! $parent) {
            return null;
        }

        $stripeInvoiceId = $stripeInvoice->id ?? null;
        if ($stripeInvoiceId) {
            $existing = HubAdvisorBilling::query()
                ->where('stripe_invoice_id', $stripeInvoiceId)
                ->first();
            if ($existing) {
                return $existing;
            }
        }

        if ($parent->payment_status === 'paid' && empty($parent->stripe_invoice_id) && $stripeInvoiceId) {
            $parent->stripe_invoice_id = $stripeInvoiceId;
            $parent->save();
            if (! $parent->invoice) {
                $this->invoices->createForAdvisorBilling($parent);
            }

            return $parent->fresh(['invoice', 'billedUser', 'hub']);
        }

        $hub = $parent->hub ?? Hub::query()->find($parent->hub_id);
        $amountPaid = isset($stripeInvoice->amount_paid)
            ? round(((int) $stripeInvoice->amount_paid) / 100, 2)
            : (float) $parent->amount;

        $renewal = HubAdvisorBilling::query()->create([
            'hub_id' => $parent->hub_id,
            'billed_user_id' => $parent->billed_user_id,
            'advisor_count' => $parent->advisor_count,
            'rate_per_advisor' => $parent->rate_per_advisor,
            'amount' => $amountPaid,
            'currency' => $parent->currency,
            'status' => HubAdvisorBilling::STATUS_PENDING,
            'payment_method' => 'stripe',
            'payment_status' => 'pending',
            'auto_renew' => true,
            'stripe_subscription_id' => $subscriptionId,
            'stripe_invoice_id' => $stripeInvoiceId,
            'period_starts_at' => now(),
            'period_ends_at' => $hub ? $this->nextRenewalAt($hub) : now()->addMonth(),
            'meta' => [
                'renewal_of' => $parent->id,
                'formula' => 'rate_per_advisor × advisor_count',
            ],
        ]);

        return $this->markPaid($renewal);
    }

    public function markPaid(HubAdvisorBilling $billing): HubAdvisorBilling
    {
        return DB::transaction(function () use ($billing) {
            $locked = HubAdvisorBilling::query()
                ->whereKey($billing->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->payment_status === 'paid') {
                $this->invoices->createForAdvisorBilling($locked);

                return $locked->fresh(['invoice', 'billedUser', 'hub']);
            }

            $hub = Hub::query()->find($locked->hub_id);
            $locked->update([
                'status' => HubAdvisorBilling::STATUS_PAID,
                'payment_status' => 'paid',
                'period_starts_at' => $locked->period_starts_at ?? now(),
                'period_ends_at' => $locked->period_ends_at
                    ?? ($hub ? $this->nextRenewalAt($hub) : now()->addMonth()),
            ]);

            $this->invoices->createForAdvisorBilling($locked->fresh());

            return $locked->fresh(['invoice', 'billedUser', 'hub']);
        });
    }

    public function confirmBankTransfer(HubAdvisorBilling $billing): HubAdvisorBilling
    {
        if ($billing->payment_method !== 'bank_transfer') {
            throw new RuntimeException('Billing is not a bank transfer payment.');
        }

        return $this->markPaid($billing);
    }

    /**
     * @return array<string, mixed>
     */
    public function quotePayload(?HubAdvisorBilling $billing = null, ?User $viewer = null): array
    {
        $advisorCount = $billing?->advisor_count ?? $this->pricing->currentAdvisorCount();
        $quote = $this->pricing->quote((int) $advisorCount);
        $hub = $this->hubs->current();
        $payer = $billing
            ? User::query()->find($billing->billed_user_id)
            : ($viewer ? $this->resolvePayer($viewer) : null);

        $methods = $this->paymentSettings->publicMethods(
            fn () => $this->bankTransfer->bankDetails()
        );

        if ($payer && $this->payerHasSavedCard($payer)) {
            array_unshift($methods, [
                'id' => 'saved_card',
                'label' => 'Saved card (client admin)',
                'available' => true,
                'unavailable_reason' => null,
            ]);

            // Clarify Stripe path when a dashboard card is already on file.
            $methods = array_map(function (array $method) {
                if (($method['id'] ?? null) === 'stripe') {
                    $method['label'] = 'Stripe (charge card on file)';
                }

                return $method;
            }, $methods);
        }

        return [
            ...$quote,
            'billing_enabled' => $this->billingEnabled(),
            'billing' => $billing?->loadMissing(['invoice']),
            'payment_methods' => $methods,
            'formula' => 'amount = rate_per_advisor × advisor_count',
            'auto_renew' => true,
            'renew_day' => $hub->advisorBillingRenewDay(),
            'next_renewal_at' => $this->nextRenewalAt($hub)->toIso8601String(),
            'has_saved_card' => $payer ? $this->payerHasSavedCard($payer) : false,
            'payer' => $payer ? [
                'id' => $payer->id,
                'name' => $payer->name,
                'email' => $payer->email,
                'role' => $payer->role,
            ] : null,
        ];
    }

    private function ensureApiKey(): void
    {
        if ($this->apiKeySet) {
            return;
        }

        $this->paymentSettings->applyStripeApiKey();
        $this->apiKeySet = true;
    }
}
