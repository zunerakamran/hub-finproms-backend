<?php

namespace App\Services;

use App\Models\Hub;
use App\Models\HubModuleBilling;
use App\Models\HubModuleRecurringBilling;
use App\Models\Invoice;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Stripe\PaymentIntent;
use Stripe\Stripe;

/**
 * Renew-day collection: charge hub payer's saved card for all due unpaid invoices.
 */
class BillingCollectionService
{
    public function __construct(
        private readonly ModuleBillingService $moduleBilling,
        private readonly AdvisorBillingService $advisorBilling,
        private readonly PaymentSettingsService $paymentSettings
    ) {}

    /**
     * @return array{hubs: int, invoices_charged: int, amount: float, errors: list<string>}
     */
    public function collectDueInvoices(?Carbon $on = null): array
    {
        $on ??= Carbon::today();
        $hubsProcessed = 0;
        $invoicesCharged = 0;
        $amountTotal = 0.0;
        $errors = [];

        $hubs = Hub::query()->where('is_active', true)->get();
        foreach ($hubs as $hub) {
            if ((int) $on->day !== $hub->advisorBillingRenewDay()) {
                continue;
            }

            $hubsProcessed++;
            try {
                $result = $this->collectForHub($hub, $on);
                $invoicesCharged += $result['count'];
                $amountTotal += $result['amount'];
            } catch (\Throwable $e) {
                $errors[] = $hub->slug.': '.$e->getMessage();
                report($e);
            }
        }

        return [
            'hubs' => $hubsProcessed,
            'invoices_charged' => $invoicesCharged,
            'amount' => round($amountTotal, 2),
            'errors' => $errors,
        ];
    }

    /**
     * @return array{count: int, amount: float}
     */
    public function collectForHub(Hub $hub, ?Carbon $on = null): array
    {
        $on ??= Carbon::today();
        $invoices = Invoice::query()
            ->where('status', 'unpaid')
            ->where(function ($q) use ($on) {
                $q->whereDate('due_on', '<=', $on->toDateString())
                    ->orWhere(function ($q2) use ($on) {
                        $q2->whereNull('due_on')->whereDate('issued_at', '<=', $on->toDateString());
                    });
            })
            ->where(function ($q) use ($hub) {
                $q->whereHas('moduleBilling', fn ($b) => $b->where('hub_id', $hub->id))
                    ->orWhereHas('moduleRecurringBilling', fn ($b) => $b->where('hub_id', $hub->id))
                    ->orWhereHas('advisorBilling', fn ($b) => $b->where('hub_id', $hub->id));
            })
            ->orderBy('id')
            ->get();

        if ($invoices->isEmpty()) {
            return ['count' => 0, 'amount' => 0.0];
        }

        $amount = round((float) $invoices->sum('amount'), 2);
        if ($amount <= 0) {
            return ['count' => 0, 'amount' => 0.0];
        }

        $payer = $this->resolveHubPayer($hub, $invoices->first()->user);
        if (! $this->advisorBilling->payerHasSavedCard($payer)) {
            throw new RuntimeException('No saved card on file for hub payer; unpaid invoices remain due until grace day.');
        }

        $this->ensureStripeKey($hub);
        $intent = PaymentIntent::create([
            'amount' => (int) round($amount * 100),
            'currency' => strtolower((string) ($invoices->first()->currency ?: 'gbp')),
            'customer' => $payer->stripe_customer_id,
            'payment_method' => $payer->stripe_payment_method_id,
            'off_session' => true,
            'confirm' => true,
            'description' => sprintf('Hub billing renew day — %s (%d invoices)', $hub->name, $invoices->count()),
            'metadata' => [
                'type' => 'hub_billing_renew_day',
                'hub_id' => (string) $hub->id,
                'invoice_ids' => $invoices->pluck('id')->implode(','),
            ],
        ]);

        if (($intent->status ?? null) !== 'succeeded') {
            throw new RuntimeException('Card charge failed on renew day (status: '.($intent->status ?? 'unknown').').');
        }

        DB::transaction(function () use ($invoices, $payer, $intent) {
            foreach ($invoices as $invoice) {
                $invoice->status = 'paid';
                $invoice->save();

                if ($invoice->hub_module_billing_id) {
                    HubModuleBilling::query()->where('id', $invoice->hub_module_billing_id)->update([
                        'status' => HubModuleBilling::STATUS_PAID,
                        'payment_status' => 'paid',
                        'paid_at' => now(),
                        'paid_by_user_id' => $payer->id,
                        'payment_method' => 'saved_card',
                        'payment_reference' => $intent->id,
                    ]);
                }

                if ($invoice->hub_module_recurring_billing_id) {
                    HubModuleRecurringBilling::query()->where('id', $invoice->hub_module_recurring_billing_id)->update([
                        'status' => HubModuleRecurringBilling::STATUS_PAID,
                        'payment_status' => 'paid',
                        'paid_at' => now(),
                        'paid_by_user_id' => $payer->id,
                        'payment_method' => 'saved_card',
                        'payment_reference' => $intent->id,
                    ]);
                }

                if ($invoice->hub_advisor_billing_id) {
                    \App\Models\HubAdvisorBilling::query()->where('id', $invoice->hub_advisor_billing_id)->update([
                        'status' => 'paid',
                        'payment_status' => 'paid',
                        'paid_at' => now(),
                    ]);
                }
            }
        });

        return ['count' => $invoices->count(), 'amount' => $amount];
    }

    private function resolveHubPayer(Hub $hub, ?User $fallback): User
    {
        $actor = $fallback ?: User::query()->orderBy('id')->firstOrFail();

        return $this->moduleBilling->resolvePayer($actor);
    }

    private function ensureStripeKey(Hub $hub): void
    {
        $secret = $hub->stripe_secret ?: $this->paymentSettings->stripeSecret();
        if (! $secret) {
            throw new RuntimeException('Stripe is not configured for this hub.');
        }
        Stripe::setApiKey($secret);
    }
}
