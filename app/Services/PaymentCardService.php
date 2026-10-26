<?php

namespace App\Services;

use App\Models\Firm;
use App\Models\Hub;
use App\Models\HubPaymentProfile;
use App\Models\User;
use App\Services\ModuleInvoiceSettingsService;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Stripe\Checkout\Session;
use Stripe\Customer;
use Stripe\PaymentMethod;

/**
 * Network / firm hub cards (capability-gated) and personal cards (My payment method, users_pay_own).
 */
class PaymentCardService
{
    public const SCOPE_NETWORK = HubPaymentProfile::SCOPE_NETWORK;

    public const SCOPE_FIRM = HubPaymentProfile::SCOPE_FIRM;

    public const SCOPE_PERSONAL = 'personal';

    public function __construct(
        private readonly HubService $hubs,
        private readonly PaymentSettingsService $paymentSettings,
        private readonly BankTransferSubscriptionService $bankTransfer,
        private readonly ModuleInvoiceSettingsService $invoiceSettings,
        private readonly CapabilitiesMatrixService $matrix
    ) {}

    public function paymentCardFeatureEnabled(?Hub $hub = null): bool
    {
        $hub ??= $this->hubs->current();

        return $hub->can('advisor_subscriber_billing')
            || $hub->can('private_invite_only')
            || $hub->can('charge_amount_per_module')
            || $hub->can('charge_recurring_per_module');
    }

    public function assertCanManageScope(User $user, Hub $hub, string $scope, ?int $firmId = null): void
    {
        if ($scope === self::SCOPE_PERSONAL) {
            $settings = $this->invoiceSettings->forHub($hub);
            if (! $settings->users_pay_own) {
                throw new InvalidArgumentException(
                    'Personal payment methods are available only while Module invoice settings → Users pay own is on.'
                );
            }

            return;
        }

        if (! $this->paymentCardFeatureEnabled($hub)) {
            throw new InvalidArgumentException(
                'Payment card is available when advisor billing or module charging is enabled for this hub.'
            );
        }

        if ($scope === self::SCOPE_NETWORK) {
            if (! $this->matrix->userCan($hub, $user, 'dashboard_manage_network_payment_card')) {
                throw new InvalidArgumentException(
                    'Enable “Add network payment card” in Power Admin → Capabilities.'
                );
            }

            return;
        }

        if ($scope === self::SCOPE_FIRM) {
            $settings = $this->invoiceSettings->forHub($hub);
            if (! $settings->firms_pay_own) {
                throw new InvalidArgumentException(
                    'Firm payment cards are available only while Module invoice settings → Firms pay own is on.'
                );
            }
            if (! $this->matrix->userCan($hub, $user, 'dashboard_manage_firm_payment_card')) {
                throw new InvalidArgumentException(
                    'Enable “Add firm payment card” in Power Admin → Capabilities.'
                );
            }
            $resolvedFirmId = $firmId ?: ($user->firm_id ? (int) $user->firm_id : null);
            if (! $resolvedFirmId) {
                throw new InvalidArgumentException('Firm payment card requires a firm.');
            }
            $firm = Firm::query()->find($resolvedFirmId);
            if (! $firm || $firm->isCentral()) {
                throw new InvalidArgumentException('Choose a member firm (not Central / Network) for the firm card.');
            }

            return;
        }

        throw new InvalidArgumentException('Invalid payment card scope.');
    }

    /**
     * @return array<string, mixed>
     */
    public function profile(User $user, Hub $hub, string $scope, ?int $firmId = null): array
    {
        $this->assertCanManageScope($user, $hub, $scope, $firmId);

        $methods = $this->paymentSettings->publicMethods(
            fn () => $this->bankTransfer->bankDetails()
        );
        $stripe = collect($methods)->firstWhere('id', 'stripe');

        if ($scope === self::SCOPE_PERSONAL) {
            $card = null;
            if (filled($user->stripe_customer_id) && filled($user->stripe_payment_method_id)) {
                $card = $this->retrieveCardSummary($user->stripe_payment_method_id);
            }

            return [
                'scope' => self::SCOPE_PERSONAL,
                'has_saved_card' => filled($user->stripe_customer_id) && filled($user->stripe_payment_method_id),
                'card' => $card,
                'stripe_available' => (bool) ($stripe['available'] ?? false),
                'stripe_unavailable_reason' => $stripe['unavailable_reason'] ?? null,
                'payment_card_enabled' => true,
            ];
        }

        $row = $this->profileRow($hub, $scope, $firmId, $user);
        $card = null;
        if ($row->hasSavedCard()) {
            $card = $this->retrieveCardSummary((string) $row->stripe_payment_method_id);
        }

        return [
            'scope' => $scope,
            'firm_id' => $row->firm_id ? (int) $row->firm_id : null,
            'has_saved_card' => $row->hasSavedCard(),
            'card' => $card,
            'stripe_available' => (bool) ($stripe['available'] ?? false),
            'stripe_unavailable_reason' => $stripe['unavailable_reason'] ?? null,
            'payment_card_enabled' => $this->paymentCardFeatureEnabled($hub),
            'managed_by_user_id' => $row->managed_by_user_id ? (int) $row->managed_by_user_id : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function createSetupSession(User $user, Hub $hub, string $scope, ?int $firmId = null): array
    {
        $this->assertCanManageScope($user, $hub, $scope, $firmId);

        $methods = collect($this->paymentSettings->publicMethods(
            fn () => $this->bankTransfer->bankDetails()
        ))->keyBy('id');

        if (! ($methods['stripe']['available'] ?? false)) {
            throw new InvalidArgumentException(
                $methods['stripe']['unavailable_reason'] ?? 'Stripe is not available.'
            );
        }

        $this->ensureApiKey();
        $frontendUrl = $hub->frontendBaseUrl();
        $successPath = $scope === self::SCOPE_PERSONAL
            ? '/my-dashboard/my-payment-method?card=success&session_id={CHECKOUT_SESSION_ID}'
            : '/my-dashboard/payment-card/success?session_id={CHECKOUT_SESSION_ID}&scope='.$scope
                .($firmId ? '&firm_id='.$firmId : '');
        $cancelPath = $scope === self::SCOPE_PERSONAL
            ? '/my-dashboard/my-payment-method?card=canceled'
            : '/my-dashboard/payment-card?canceled=1&scope='.$scope
                .($firmId ? '&firm_id='.$firmId : '');

        $customerId = $this->ensureCustomerForScope($user, $hub, $scope, $firmId);

        $session = Session::create([
            'mode' => 'setup',
            'customer' => $customerId,
            'payment_method_types' => ['card'],
            'success_url' => $frontendUrl.$successPath,
            'cancel_url' => $frontendUrl.$cancelPath,
            'metadata' => [
                'type' => 'hub_payment_card_setup',
                'scope' => $scope,
                'user_id' => (string) $user->id,
                'hub_id' => (string) $hub->id,
                'firm_id' => $firmId ? (string) $firmId : '',
            ],
        ]);

        return [
            'checkout_url' => $session->url,
            'session_id' => $session->id,
            'saves_card' => true,
            'scope' => $scope,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function fulfillSetupSession(string $sessionId, User $user, Hub $hub): array
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
            throw new InvalidArgumentException('Unexpected Stripe session mode.');
        }

        $scope = (string) ($session->metadata->scope ?? self::SCOPE_PERSONAL);
        $firmId = filled($session->metadata->firm_id ?? null)
            ? (int) $session->metadata->firm_id
            : null;

        $this->assertCanManageScope($user, $hub, $scope, $firmId);

        $customerId = is_object($session->customer)
            ? ($session->customer->id ?? null)
            : ($session->customer ?? null);

        $pmId = $this->paymentMethodIdFromSetupSession($session);
        if (! $customerId || ! $pmId) {
            throw new RuntimeException('Could not read card from Stripe setup session.');
        }

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

        if ($scope === self::SCOPE_PERSONAL) {
            $user->stripe_customer_id = $customerId;
            $user->stripe_payment_method_id = $pmId;
            $user->save();

            return $this->profile($user->fresh(), $hub, self::SCOPE_PERSONAL);
        }

        $row = $this->profileRow($hub, $scope, $firmId, $user);
        $row->stripe_customer_id = $customerId;
        $row->stripe_payment_method_id = $pmId;
        $row->managed_by_user_id = $user->id;
        $row->save();

        return $this->profile($user, $hub, $scope, $firmId);
    }

    public function networkProfile(Hub $hub): ?HubPaymentProfile
    {
        return HubPaymentProfile::query()
            ->where('hub_id', $hub->id)
            ->where('scope', self::SCOPE_NETWORK)
            ->whereNull('firm_id')
            ->first();
    }

    public function firmProfile(Hub $hub, int $firmId): ?HubPaymentProfile
    {
        return HubPaymentProfile::query()
            ->where('hub_id', $hub->id)
            ->where('scope', self::SCOPE_FIRM)
            ->where('firm_id', $firmId)
            ->first();
    }

    private function profileRow(Hub $hub, string $scope, ?int $firmId, User $actor): HubPaymentProfile
    {
        if ($scope === self::SCOPE_FIRM) {
            $firmId = $firmId ?: ($actor->firm_id ? (int) $actor->firm_id : null);
            if (! $firmId) {
                throw new InvalidArgumentException('Firm id is required for firm payment card.');
            }

            return HubPaymentProfile::query()->firstOrCreate(
                [
                    'hub_id' => $hub->id,
                    'scope' => self::SCOPE_FIRM,
                    'firm_id' => $firmId,
                ],
                ['managed_by_user_id' => $actor->id]
            );
        }

        return HubPaymentProfile::query()->firstOrCreate(
            [
                'hub_id' => $hub->id,
                'scope' => self::SCOPE_NETWORK,
                'firm_id' => null,
            ],
            ['managed_by_user_id' => $actor->id]
        );
    }

    private function ensureCustomerForScope(User $user, Hub $hub, string $scope, ?int $firmId): string
    {
        if ($scope === self::SCOPE_PERSONAL) {
            if (filled($user->stripe_customer_id)) {
                return (string) $user->stripe_customer_id;
            }
            $customer = Customer::create([
                'email' => $user->email,
                'name' => $user->name,
                'metadata' => [
                    'user_id' => (string) $user->id,
                    'hub_id' => (string) $hub->id,
                    'scope' => self::SCOPE_PERSONAL,
                ],
            ]);
            $user->stripe_customer_id = $customer->id;
            $user->save();

            return $customer->id;
        }

        $row = $this->profileRow($hub, $scope, $firmId, $user);
        if (filled($row->stripe_customer_id)) {
            return (string) $row->stripe_customer_id;
        }

        $customer = Customer::create([
            'email' => $user->email,
            'name' => $hub->name.($scope === self::SCOPE_FIRM ? ' firm card' : ' network card'),
            'metadata' => [
                'hub_id' => (string) $hub->id,
                'scope' => $scope,
                'firm_id' => $firmId ? (string) $firmId : '',
                'managed_by_user_id' => (string) $user->id,
            ],
        ]);
        $row->stripe_customer_id = $customer->id;
        $row->managed_by_user_id = $user->id;
        $row->save();

        return $customer->id;
    }

    /**
     * @return array{brand: ?string, last4: ?string, exp_month: ?int, exp_year: ?int}|null
     */
    private function retrieveCardSummary(string $paymentMethodId): ?array
    {
        try {
            $this->ensureApiKey();
            $pm = PaymentMethod::retrieve($paymentMethodId);
            $card = $pm->card ?? null;

            return [
                'brand' => $card->brand ?? null,
                'last4' => $card->last4 ?? null,
                'exp_month' => isset($card->exp_month) ? (int) $card->exp_month : null,
                'exp_year' => isset($card->exp_year) ? (int) $card->exp_year : null,
            ];
        } catch (\Throwable $e) {
            Log::debug('payment card summary failed: '.$e->getMessage());

            return [
                'brand' => null,
                'last4' => null,
                'exp_month' => null,
                'exp_year' => null,
            ];
        }
    }

    private function paymentMethodIdFromSetupSession(object $session): ?string
    {
        $setupIntent = $session->setup_intent ?? null;
        if (is_object($setupIntent)) {
            $pm = $setupIntent->payment_method ?? null;

            return is_object($pm) ? ($pm->id ?? null) : (is_string($pm) ? $pm : null);
        }
        if (is_string($setupIntent) && $setupIntent !== '') {
            try {
                $intent = \Stripe\SetupIntent::retrieve($setupIntent);
                $pm = $intent->payment_method ?? null;

                return is_object($pm) ? ($pm->id ?? null) : (is_string($pm) ? $pm : null);
            } catch (\Throwable $e) {
                return null;
            }
        }

        return null;
    }

    private function ensureApiKey(): void
    {
        $secret = config('services.stripe.secret');
        if (! $secret) {
            throw new InvalidArgumentException('Stripe secret key is not configured.');
        }
        \Stripe\Stripe::setApiKey($secret);
    }
}
