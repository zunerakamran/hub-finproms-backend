<?php

namespace App\Services;

use App\Models\Hub;
use App\Models\HubAdvisorBilling;
use App\Models\Invoice;
use App\Models\User;
use App\Models\UserSubscription;

/**
 * Orchestrates remaining transactional / admin emails for product flows.
 */
class FunctionalMailService
{
    public function __construct(
        private readonly HubService $hubs,
        private readonly HubMailService $mail,
        private readonly PaymentSettingsService $paymentSettings
    ) {}

    public function adminSubscriptionPaid(Invoice $invoice): void
    {
        if ($invoice->type !== Invoice::TYPE_SUBSCRIPTION) {
            return;
        }

        $invoice->loadMissing(['user', 'subscription.plan']);
        $hub = $this->hubs->current();
        $branding = $this->mail->branding($hub);
        $user = $invoice->user;
        $plan = $invoice->subscription?->plan;

        $this->mail->sendToAdmins(
            subject: "[{$branding['site_name']}] New subscription payment",
            eyebrow: 'Admin notification',
            heading: 'New subscription payment',
            intro: "A plan subscription payment was received on {$branding['site_name']}.",
            fields: [
                ['label' => 'Purchaser', 'value' => ($user?->name ?: 'Unknown').($user?->email ? "\n".$user->email : '')],
                ['label' => 'Plan', 'value' => $plan?->name ?? ($invoice->description ?: 'Subscription')],
                ['label' => 'Amount', 'value' => $this->mail->formatMoney((float) $invoice->amount, (string) $invoice->currency)],
                ['label' => 'Payment method', 'value' => $this->subscriptionPaymentMethod($invoice->subscription)],
                ['label' => 'Invoice', 'value' => $invoice->invoice_number],
            ],
            cta: [
                'label' => 'View invoice',
                'url' => $branding['frontend_url'].'/invoices/'.$invoice->id,
            ],
            excludeEmail: $user?->email,
            hub: $hub,
        );
    }

    public function advisorBillingPaid(Invoice $invoice): void
    {
        if ($invoice->type !== Invoice::TYPE_ADVISOR_BILLING) {
            return;
        }

        $invoice->loadMissing(['user', 'advisorBilling.hub']);
        $hub = $this->hubs->current();
        $branding = $this->mail->branding($hub);
        $payer = $invoice->user;
        $billing = $invoice->advisorBilling;

        $fields = [
            ['label' => 'Invoice', 'value' => $invoice->invoice_number],
            ['label' => 'Description', 'value' => $invoice->description ?: 'Advisor billing'],
            ['label' => 'Amount', 'value' => $this->mail->formatMoney((float) $invoice->amount, (string) $invoice->currency)],
            ['label' => 'Advisors', 'value' => (string) ($billing?->advisor_count ?? '—')],
            ['label' => 'Payment method', 'value' => $this->advisorPaymentMethod($billing)],
        ];

        if ($payer?->email) {
            $this->mail->sendToUser(
                $payer,
                subject: "Your {$branding['site_name']} advisor billing receipt",
                eyebrow: 'Payment received',
                heading: 'Advisor billing payment confirmed',
                intro: "Hi {$payer->name},\n\nWe’ve received your advisor billing payment for {$branding['site_name']}.",
                fields: $fields,
                cta: [
                    'label' => 'View invoice',
                    'url' => $branding['frontend_url'].'/invoices/'.$invoice->id,
                ],
                closing: 'Thank you for your payment.',
                hub: $hub,
            );
        }

        $this->mail->sendToAdmins(
            subject: "[{$branding['site_name']}] Advisor billing paid",
            eyebrow: 'Admin notification',
            heading: 'Advisor billing payment received',
            intro: "An advisor billing payment was received on {$branding['site_name']}.",
            fields: array_merge([
                ['label' => 'Paid by', 'value' => ($payer?->name ?: 'Unknown').($payer?->email ? "\n".$payer->email : '')],
            ], $fields),
            cta: [
                'label' => 'View invoice',
                'url' => $branding['frontend_url'].'/invoices/'.$invoice->id,
            ],
            excludeEmail: $payer?->email,
            hub: $hub,
        );
    }

    public function bankTransferSubscriptionPending(UserSubscription $subscription): void
    {
        if ($subscription->payment_status === 'paid' || $subscription->payment_method !== 'bank_transfer') {
            return;
        }

        $subscription->loadMissing(['user', 'plan']);
        $hub = $this->hubs->current();
        $branding = $this->mail->branding($hub);
        $user = $subscription->user;
        $bank = app(BankTransferSubscriptionService::class)->bankDetails();
        $amount = $this->mail->formatMoney((float) $subscription->amount_paid, strtoupper((string) ($this->paymentSettings->stripeCurrency() ?: 'gbp')));

        $bankLines = $this->formatBankDetails($bank);
        $fields = [
            ['label' => 'Plan', 'value' => $subscription->plan?->name ?? 'Subscription'],
            ['label' => 'Amount', 'value' => $amount],
            ['label' => 'Payment reference', 'value' => (string) $subscription->payment_reference],
            ['label' => 'Bank details', 'value' => $bankLines],
        ];

        if ($user?->email) {
            $this->mail->sendToUser(
                $user,
                subject: "[{$branding['site_name']}] Complete your bank transfer",
                eyebrow: 'Payment pending',
                heading: 'Bank transfer instructions',
                intro: "Hi {$user->name},\n\nYour subscription order is pending. Please complete the bank transfer using the details below.",
                fields: $fields,
                cta: ['label' => 'Log in', 'url' => $branding['login_url']],
                closing: 'Include the payment reference exactly so we can match your payment.',
                hub: $hub,
            );
        }

        $this->mail->sendToAdmins(
            subject: "[{$branding['site_name']}] Pending bank transfer subscription",
            eyebrow: 'Admin notification',
            heading: 'Pending subscription bank transfer',
            intro: "A member started a bank transfer subscription on {$branding['site_name']}.",
            fields: array_merge([
                ['label' => 'Member', 'value' => ($user?->name ?: 'Unknown').($user?->email ? "\n".$user->email : '')],
            ], $fields),
            excludeEmail: $user?->email,
            hub: $hub,
        );
    }

    public function bankTransferAdvisorBillingPending(HubAdvisorBilling $billing): void
    {
        if ($billing->payment_status === 'paid' || $billing->payment_method !== 'bank_transfer') {
            return;
        }

        $billing->loadMissing(['billedUser', 'hub']);
        $hub = $billing->hub ?? $this->hubs->current();
        $branding = $this->mail->branding($hub);
        $payer = $billing->billedUser;
        $bank = app(BankTransferSubscriptionService::class)->bankDetails();
        $amount = $this->mail->formatMoney((float) $billing->amount, 'gbp');

        $fields = [
            ['label' => 'Amount', 'value' => $amount],
            ['label' => 'Advisors', 'value' => (string) $billing->advisor_count],
            ['label' => 'Payment reference', 'value' => (string) $billing->payment_reference],
            ['label' => 'Bank details', 'value' => $this->formatBankDetails($bank)],
        ];

        if ($payer?->email) {
            $this->mail->sendToUser(
                $payer,
                subject: "[{$branding['site_name']}] Advisor billing bank transfer",
                eyebrow: 'Payment pending',
                heading: 'Advisor billing transfer instructions',
                intro: "Hi {$payer->name},\n\nYour advisor billing payment is pending. Please complete the bank transfer using the details below.",
                fields: $fields,
                closing: 'Include the payment reference exactly so we can match your payment.',
                hub: $hub,
            );
        }

        $this->mail->sendToAdmins(
            subject: "[{$branding['site_name']}] Pending advisor billing transfer",
            eyebrow: 'Admin notification',
            heading: 'Pending advisor billing bank transfer',
            intro: "An advisor billing bank transfer is awaiting confirmation on {$branding['site_name']}.",
            fields: array_merge([
                ['label' => 'Payer', 'value' => ($payer?->name ?: 'Unknown').($payer?->email ? "\n".$payer->email : '')],
            ], $fields),
            excludeEmail: $payer?->email,
            hub: $hub,
        );
    }

    public function advisorInvite(User $user, ?string $temporaryPassword = null): void
    {
        $hub = $this->hubs->current();
        $branding = $this->mail->branding($hub);
        $fields = [
            ['label' => 'Email', 'value' => (string) $user->email],
        ];
        if ($temporaryPassword) {
            $fields[] = ['label' => 'Temporary password', 'value' => $temporaryPassword];
        }

        $this->mail->sendToUser(
            $user,
            subject: "Welcome to {$branding['site_name']} — your advisor access",
            eyebrow: 'Advisor invite',
            heading: "You're invited to {$branding['site_name']}",
            intro: "Hi {$user->name},\n\nAn advisor account has been created for you on {$branding['site_name']}.",
            fields: $fields,
            bullets: $temporaryPassword
                ? ['Use the temporary password to sign in, then change it after login.']
                : ['Sign in with the password provided by your administrator.'],
            cta: ['label' => 'Log in', 'url' => $branding['login_url']],
            hub: $hub,
        );
    }

    public function advisorReactivated(User $user): void
    {
        $hub = $this->hubs->current();
        $branding = $this->mail->branding($hub);

        $this->mail->sendToUser(
            $user,
            subject: "[{$branding['site_name']}] Your advisor access was restored",
            eyebrow: 'Access restored',
            heading: 'Advisor access restored',
            intro: "Hi {$user->name},\n\nYour advisor access on {$branding['site_name']} has been restored. You can log in again.",
            cta: ['label' => 'Log in', 'url' => $branding['login_url']],
            hub: $hub,
        );
    }

    public function advisorDiscontinued(User $user): void
    {
        $hub = $this->hubs->current();
        $branding = $this->mail->branding($hub);

        $this->mail->sendToUser(
            $user,
            subject: "[{$branding['site_name']}] Your advisor access has ended",
            eyebrow: 'Access ended',
            heading: 'Advisor access discontinued',
            intro: "Hi {$user->name},\n\nYour advisor access on {$branding['site_name']} has been discontinued. You will no longer be able to sign in.",
            closing: "If you think this was a mistake, contact {$branding['support_email']}.",
            hub: $hub,
        );

        $this->mail->sendToAdmins(
            subject: "[{$branding['site_name']}] Advisor discontinued",
            eyebrow: 'Admin notification',
            heading: 'Advisor discontinued',
            intro: "An advisor was discontinued on {$branding['site_name']}.",
            fields: [
                ['label' => 'Advisor', 'value' => $user->name ?: 'Unknown'],
                ['label' => 'E-mail', 'value' => (string) $user->email],
            ],
            excludeEmail: $user->email,
            hub: $hub,
        );
    }

    public function advisorSuspended(User $user, Hub $hub): void
    {
        $branding = $this->mail->branding($hub);

        $this->mail->sendToUser(
            $user,
            subject: "[{$branding['site_name']}] Advisor access suspended",
            eyebrow: 'Access suspended',
            heading: 'Advisor access temporarily suspended',
            intro: "Hi {$user->name},\n\n{$branding['site_name']} is now operating in public mode. Your invite-only advisor access has been suspended.",
            closing: "Contact {$branding['support_email']} if you need help.",
            hub: $hub,
        );
    }

    /**
     * @param  list<array{name: string, email: string}>  $created
     * @param  list<array{name: string, email: string}>  $reactivated
     */
    public function adminAdvisorImportSummary(array $created, array $reactivated): void
    {
        if ($created === [] && $reactivated === []) {
            return;
        }

        $hub = $this->hubs->current();
        $branding = $this->mail->branding($hub);
        $createdList = collect($created)->map(fn ($r) => ($r['name'] ?? '').' <'.($r['email'] ?? '').'>')->implode("\n");
        $reactivatedList = collect($reactivated)->map(fn ($r) => ($r['name'] ?? '').' <'.($r['email'] ?? '').'>')->implode("\n");

        $fields = [
            ['label' => 'Created', 'value' => (string) count($created)],
            ['label' => 'Reactivated', 'value' => (string) count($reactivated)],
        ];
        if ($createdList !== '') {
            $fields[] = ['label' => 'New advisors', 'value' => $createdList];
        }
        if ($reactivatedList !== '') {
            $fields[] = ['label' => 'Reactivated advisors', 'value' => $reactivatedList];
        }

        $this->mail->sendToAdmins(
            subject: "[{$branding['site_name']}] Advisor import completed",
            eyebrow: 'Admin notification',
            heading: 'Advisor import summary',
            intro: "An advisor Excel/CSV import finished on {$branding['site_name']}.",
            fields: $fields,
            hub: $hub,
        );
    }

    public function accountCreatedByAdmin(User $user): void
    {
        $hub = $this->hubs->current();
        $branding = $this->mail->branding($hub);

        $this->mail->sendToUser(
            $user,
            subject: "Welcome to {$branding['site_name']}!",
            eyebrow: 'Account created',
            heading: "Your {$branding['site_name']} account is ready",
            intro: "Hi {$user->name},\n\nAn administrator created an account for you on {$branding['site_name']}.",
            fields: [
                ['label' => 'Email', 'value' => (string) $user->email],
                ['label' => 'Role', 'value' => $user->role_label],
            ],
            bullets: ['Sign in with the password provided by your administrator.'],
            cta: ['label' => 'Log in', 'url' => $branding['login_url']],
            hub: $hub,
        );
    }

    public function accountSuspendedByAdmin(User $user): void
    {
        $hub = $this->hubs->current();
        $branding = $this->mail->branding($hub);

        $this->mail->sendToUser(
            $user,
            subject: "[{$branding['site_name']}] Account suspended",
            eyebrow: 'Account update',
            heading: 'Your account has been suspended',
            intro: "Hi {$user->name},\n\nYour account on {$branding['site_name']} has been suspended. You will not be able to sign in until an administrator restores access.",
            closing: "Questions? Contact {$branding['support_email']}.",
            hub: $hub,
        );
    }

    public function passwordChangedByAdmin(User $user): void
    {
        $hub = $this->hubs->current();
        $branding = $this->mail->branding($hub);

        $this->mail->sendToUser(
            $user,
            subject: "[{$branding['site_name']}] Your password was changed",
            eyebrow: 'Security',
            heading: 'Password updated',
            intro: "Hi {$user->name},\n\nAn administrator updated the password for your {$branding['site_name']} account. If you did not expect this, contact support immediately.",
            cta: ['label' => 'Log in', 'url' => $branding['login_url']],
            closing: "Support: {$branding['support_email']}",
            hub: $hub,
        );
    }

    public function sendPasswordResetLink(User $user, string $token): void
    {
        $hub = $this->hubs->current();
        $branding = $this->mail->branding($hub);
        $url = $branding['frontend_url'].'/reset-password?token='.urlencode($token).'&email='.urlencode((string) $user->email);

        $this->mail->sendToUser(
            $user,
            subject: "[{$branding['site_name']}] Reset your password",
            eyebrow: 'Security',
            heading: 'Reset your password',
            intro: "Hi {$user->name},\n\nWe received a request to reset your password for {$branding['site_name']}.",
            cta: ['label' => 'Reset password', 'url' => $url],
            closing: "If you did not request this, you can ignore this email. This link expires soon.",
            hub: $hub,
        );
    }

    private function subscriptionPaymentMethod(?UserSubscription $subscription): string
    {
        $method = strtolower((string) ($subscription?->payment_method ?? ''));

        return match ($method) {
            'stripe' => 'Card (Stripe)',
            'bank_transfer' => 'Bank transfer',
            default => $method !== '' ? ucwords(str_replace('_', ' ', $method)) : 'Card',
        };
    }

    private function advisorPaymentMethod(?HubAdvisorBilling $billing): string
    {
        $method = strtolower((string) ($billing?->payment_method ?? ''));

        return match ($method) {
            'stripe' => 'Card (Stripe)',
            'bank_transfer' => 'Bank transfer',
            default => $method !== '' ? ucwords(str_replace('_', ' ', $method)) : 'Card',
        };
    }

    /**
     * @param  array<string, mixed>  $bank
     */
    private function formatBankDetails(array $bank): string
    {
        $lines = [];
        foreach ([
            'account_name' => 'Account name',
            'bank_name' => 'Bank',
            'account_number' => 'Account number',
            'sort_code' => 'Sort code',
            'iban' => 'IBAN',
            'swift' => 'SWIFT',
            'instructions' => 'Instructions',
        ] as $key => $label) {
            if (! empty($bank[$key])) {
                $lines[] = $label.': '.$bank[$key];
            }
        }

        return implode("\n", $lines);
    }
}
