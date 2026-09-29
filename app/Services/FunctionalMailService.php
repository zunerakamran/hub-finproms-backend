<?php

namespace App\Services;

use App\Models\Hub;
use App\Models\HubAdvisorBilling;
use App\Models\Invoice;
use App\Models\User;
use App\Models\UserSubscription;
use App\Support\EmailTemplateCatalog;

/**
 * Orchestrates remaining transactional / admin emails for product flows.
 */
class FunctionalMailService
{
    public function __construct(
        private readonly HubService $hubs,
        private readonly HubMailService $mail,
        private readonly PaymentSettingsService $paymentSettings,
        private readonly EmailTemplateService $emailTemplates
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

        $copy = $this->emailTemplates->resolve($hub, 'subscription_paid', EmailTemplateCatalog::AUDIENCE_ADMIN, [
            'site_name' => $branding['site_name'],
            'user_name' => $user?->name ?: 'Unknown',
            'user_email' => $user?->email ?: '',
            'plan_name' => $plan?->name ?? ($invoice->description ?: 'Subscription'),
            'amount' => $this->mail->formatMoney((float) $invoice->amount, (string) $invoice->currency),
            'invoice_number' => $invoice->invoice_number,
        ]);

        $this->mail->sendToAdmins(
            subject: $copy['subject'],
            eyebrow: $copy['eyebrow'],
            heading: $copy['heading'],
            intro: $copy['intro'],
            fields: [
                ['label' => 'Purchaser', 'value' => ($user?->name ?: 'Unknown').($user?->email ? "\n".$user->email : '')],
                ['label' => 'Plan', 'value' => $plan?->name ?? ($invoice->description ?: 'Subscription')],
                ['label' => 'Amount', 'value' => $this->mail->formatMoney((float) $invoice->amount, (string) $invoice->currency)],
                ['label' => 'Payment method', 'value' => $this->subscriptionPaymentMethod($invoice->subscription)],
                ['label' => 'Invoice', 'value' => $invoice->invoice_number],
            ],
            cta: [
                'label' => $copy['cta_label'] ?: 'View invoice',
                'url' => $branding['frontend_url'].'/invoices/'.$invoice->id,
            ],
            closing: $copy['closing'],
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

        $vars = [
            'user_name' => $payer?->name ?: 'there',
            'user_email' => $payer?->email ?: '',
            'site_name' => $branding['site_name'],
            'amount' => $this->mail->formatMoney((float) $invoice->amount, (string) $invoice->currency),
            'invoice_number' => $invoice->invoice_number,
            'advisor_count' => (string) ($billing?->advisor_count ?? '—'),
        ];

        $fields = [
            ['label' => 'Invoice', 'value' => $invoice->invoice_number],
            ['label' => 'Description', 'value' => $invoice->description ?: 'Advisor billing'],
            ['label' => 'Amount', 'value' => $vars['amount']],
            ['label' => 'Advisors', 'value' => $vars['advisor_count']],
            ['label' => 'Payment method', 'value' => $this->advisorPaymentMethod($billing)],
        ];

        if ($payer?->email) {
            $userCopy = $this->emailTemplates->resolve($hub, 'advisor_billing_paid', EmailTemplateCatalog::AUDIENCE_USER, $vars);
            $this->mail->sendToUser(
                $payer,
                subject: $userCopy['subject'],
                eyebrow: $userCopy['eyebrow'],
                heading: $userCopy['heading'],
                intro: $userCopy['intro'],
                fields: $fields,
                cta: [
                    'label' => $userCopy['cta_label'] ?: 'View invoice',
                    'url' => $branding['frontend_url'].'/invoices/'.$invoice->id,
                ],
                closing: $userCopy['closing'],
                hub: $hub,
            );
        }

        $adminCopy = $this->emailTemplates->resolve($hub, 'advisor_billing_paid', EmailTemplateCatalog::AUDIENCE_ADMIN, $vars);
        $this->mail->sendToAdmins(
            subject: $adminCopy['subject'],
            eyebrow: $adminCopy['eyebrow'],
            heading: $adminCopy['heading'],
            intro: $adminCopy['intro'],
            fields: array_merge([
                ['label' => 'Paid by', 'value' => ($payer?->name ?: 'Unknown').($payer?->email ? "\n".$payer->email : '')],
            ], $fields),
            cta: [
                'label' => $adminCopy['cta_label'] ?: 'View invoice',
                'url' => $branding['frontend_url'].'/invoices/'.$invoice->id,
            ],
            closing: $adminCopy['closing'],
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

        $vars = [
            'user_name' => $user?->name ?: 'there',
            'user_email' => $user?->email ?: '',
            'site_name' => $branding['site_name'],
            'plan_name' => $subscription->plan?->name ?? 'Subscription',
            'amount' => $amount,
            'payment_reference' => (string) $subscription->payment_reference,
        ];

        $bankLines = $this->formatBankDetails($bank);
        $fields = [
            ['label' => 'Plan', 'value' => $vars['plan_name']],
            ['label' => 'Amount', 'value' => $amount],
            ['label' => 'Payment reference', 'value' => $vars['payment_reference']],
            ['label' => 'Bank details', 'value' => $bankLines],
        ];

        if ($user?->email) {
            $userCopy = $this->emailTemplates->resolve($hub, 'bank_transfer_subscription_pending', EmailTemplateCatalog::AUDIENCE_USER, $vars);
            $this->mail->sendToUser(
                $user,
                subject: $userCopy['subject'],
                eyebrow: $userCopy['eyebrow'],
                heading: $userCopy['heading'],
                intro: $userCopy['intro'],
                fields: $fields,
                cta: ['label' => $userCopy['cta_label'] ?: 'Log in', 'url' => $branding['login_url']],
                closing: $userCopy['closing'],
                hub: $hub,
            );
        }

        $adminCopy = $this->emailTemplates->resolve($hub, 'bank_transfer_subscription_pending', EmailTemplateCatalog::AUDIENCE_ADMIN, $vars);
        $this->mail->sendToAdmins(
            subject: $adminCopy['subject'],
            eyebrow: $adminCopy['eyebrow'],
            heading: $adminCopy['heading'],
            intro: $adminCopy['intro'],
            fields: array_merge([
                ['label' => 'Member', 'value' => ($user?->name ?: 'Unknown').($user?->email ? "\n".$user->email : '')],
            ], $fields),
            closing: $adminCopy['closing'],
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

        $vars = [
            'user_name' => $payer?->name ?: 'there',
            'user_email' => $payer?->email ?: '',
            'site_name' => $branding['site_name'],
            'amount' => $amount,
            'advisor_count' => (string) $billing->advisor_count,
            'payment_reference' => (string) $billing->payment_reference,
        ];

        $fields = [
            ['label' => 'Amount', 'value' => $amount],
            ['label' => 'Advisors', 'value' => $vars['advisor_count']],
            ['label' => 'Payment reference', 'value' => $vars['payment_reference']],
            ['label' => 'Bank details', 'value' => $this->formatBankDetails($bank)],
        ];

        if ($payer?->email) {
            $userCopy = $this->emailTemplates->resolve($hub, 'bank_transfer_advisor_billing_pending', EmailTemplateCatalog::AUDIENCE_USER, $vars);
            $this->mail->sendToUser(
                $payer,
                subject: $userCopy['subject'],
                eyebrow: $userCopy['eyebrow'],
                heading: $userCopy['heading'],
                intro: $userCopy['intro'],
                fields: $fields,
                closing: $userCopy['closing'],
                hub: $hub,
            );
        }

        $adminCopy = $this->emailTemplates->resolve($hub, 'bank_transfer_advisor_billing_pending', EmailTemplateCatalog::AUDIENCE_ADMIN, $vars);
        $this->mail->sendToAdmins(
            subject: $adminCopy['subject'],
            eyebrow: $adminCopy['eyebrow'],
            heading: $adminCopy['heading'],
            intro: $adminCopy['intro'],
            fields: array_merge([
                ['label' => 'Payer', 'value' => ($payer?->name ?: 'Unknown').($payer?->email ? "\n".$payer->email : '')],
            ], $fields),
            closing: $adminCopy['closing'],
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

        $copy = $this->emailTemplates->resolve($hub, 'advisor_invite', EmailTemplateCatalog::AUDIENCE_USER, [
            'user_name' => $user->name ?: 'there',
            'user_email' => (string) $user->email,
            'site_name' => $branding['site_name'],
            'temporary_password' => $temporaryPassword ?? '',
        ]);

        $this->mail->sendToUser(
            $user,
            subject: $copy['subject'],
            eyebrow: $copy['eyebrow'],
            heading: $copy['heading'],
            intro: $copy['intro'],
            fields: $fields,
            bullets: $temporaryPassword
                ? ['Use the temporary password to sign in, then change it after login.']
                : ['Sign in with the password provided by your administrator.'],
            cta: ['label' => $copy['cta_label'] ?: 'Log in', 'url' => $branding['login_url']],
            closing: $copy['closing'],
            hub: $hub,
        );
    }

    public function advisorReactivated(User $user): void
    {
        $hub = $this->hubs->current();
        $branding = $this->mail->branding($hub);
        $copy = $this->emailTemplates->resolve($hub, 'advisor_reactivated', EmailTemplateCatalog::AUDIENCE_USER, [
            'user_name' => $user->name ?: 'there',
            'site_name' => $branding['site_name'],
        ]);

        $this->mail->sendToUser(
            $user,
            subject: $copy['subject'],
            eyebrow: $copy['eyebrow'],
            heading: $copy['heading'],
            intro: $copy['intro'],
            cta: ['label' => $copy['cta_label'] ?: 'Log in', 'url' => $branding['login_url']],
            closing: $copy['closing'],
            hub: $hub,
        );
    }

    public function advisorDiscontinued(User $user): void
    {
        $hub = $this->hubs->current();
        $branding = $this->mail->branding($hub);
        $vars = [
            'user_name' => $user->name ?: 'there',
            'user_email' => (string) $user->email,
            'site_name' => $branding['site_name'],
            'support_email' => $branding['support_email'],
        ];

        $userCopy = $this->emailTemplates->resolve($hub, 'advisor_discontinued', EmailTemplateCatalog::AUDIENCE_USER, $vars);
        $this->mail->sendToUser(
            $user,
            subject: $userCopy['subject'],
            eyebrow: $userCopy['eyebrow'],
            heading: $userCopy['heading'],
            intro: $userCopy['intro'],
            closing: $userCopy['closing'],
            hub: $hub,
        );

        $adminCopy = $this->emailTemplates->resolve($hub, 'advisor_discontinued', EmailTemplateCatalog::AUDIENCE_ADMIN, $vars);
        $this->mail->sendToAdmins(
            subject: $adminCopy['subject'],
            eyebrow: $adminCopy['eyebrow'],
            heading: $adminCopy['heading'],
            intro: $adminCopy['intro'],
            fields: [
                ['label' => 'Advisor', 'value' => $user->name ?: 'Unknown'],
                ['label' => 'E-mail', 'value' => (string) $user->email],
            ],
            closing: $adminCopy['closing'],
            excludeEmail: $user->email,
            hub: $hub,
        );
    }

    public function advisorSuspended(User $user, Hub $hub): void
    {
        $branding = $this->mail->branding($hub);
        $copy = $this->emailTemplates->resolve($hub, 'advisor_suspended', EmailTemplateCatalog::AUDIENCE_USER, [
            'user_name' => $user->name ?: 'there',
            'site_name' => $branding['site_name'],
            'support_email' => $branding['support_email'],
        ]);

        $this->mail->sendToUser(
            $user,
            subject: $copy['subject'],
            eyebrow: $copy['eyebrow'],
            heading: $copy['heading'],
            intro: $copy['intro'],
            closing: $copy['closing'],
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

        $copy = $this->emailTemplates->resolve($hub, 'advisor_import_summary', EmailTemplateCatalog::AUDIENCE_ADMIN, [
            'site_name' => $branding['site_name'],
            'created_count' => (string) count($created),
            'reactivated_count' => (string) count($reactivated),
        ]);

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
            subject: $copy['subject'],
            eyebrow: $copy['eyebrow'],
            heading: $copy['heading'],
            intro: $copy['intro'],
            fields: $fields,
            closing: $copy['closing'],
            hub: $hub,
        );
    }

    public function accountCreatedByAdmin(User $user, ?Hub $hub = null): void
    {
        $hub ??= $this->hubs->current();
        $branding = $this->mail->branding($hub);
        $copy = $this->emailTemplates->resolve($hub, 'account_created_by_admin', EmailTemplateCatalog::AUDIENCE_USER, [
            'user_name' => $user->name ?: 'there',
            'user_email' => (string) $user->email,
            'role_label' => $user->role_label,
            'site_name' => $branding['site_name'],
        ]);

        $this->mail->sendToUser(
            $user,
            subject: $copy['subject'],
            eyebrow: $copy['eyebrow'],
            heading: $copy['heading'],
            intro: $copy['intro'],
            fields: [
                ['label' => 'Email', 'value' => (string) $user->email],
                ['label' => 'Role', 'value' => $user->role_label],
            ],
            bullets: ['Sign in with the password provided by your administrator.'],
            cta: ['label' => $copy['cta_label'] ?: 'Log in', 'url' => $branding['login_url']],
            closing: $copy['closing'],
            hub: $hub,
        );
    }

    public function accountSuspendedByAdmin(User $user, ?Hub $hub = null): void
    {
        $hub ??= $this->hubs->current();
        $branding = $this->mail->branding($hub);
        $copy = $this->emailTemplates->resolve($hub, 'account_suspended_by_admin', EmailTemplateCatalog::AUDIENCE_USER, [
            'user_name' => $user->name ?: 'there',
            'site_name' => $branding['site_name'],
            'support_email' => $branding['support_email'],
        ]);

        $this->mail->sendToUser(
            $user,
            subject: $copy['subject'],
            eyebrow: $copy['eyebrow'],
            heading: $copy['heading'],
            intro: $copy['intro'],
            closing: $copy['closing'],
            hub: $hub,
        );
    }

    public function passwordChangedByAdmin(User $user, ?Hub $hub = null): void
    {
        $hub ??= $this->hubs->current();
        $branding = $this->mail->branding($hub);
        $copy = $this->emailTemplates->resolve($hub, 'password_changed_by_admin', EmailTemplateCatalog::AUDIENCE_USER, [
            'user_name' => $user->name ?: 'there',
            'site_name' => $branding['site_name'],
            'support_email' => $branding['support_email'],
        ]);

        $this->mail->sendToUser(
            $user,
            subject: $copy['subject'],
            eyebrow: $copy['eyebrow'],
            heading: $copy['heading'],
            intro: $copy['intro'],
            cta: ['label' => $copy['cta_label'] ?: 'Log in', 'url' => $branding['login_url']],
            closing: $copy['closing'],
            hub: $hub,
        );
    }

    public function sendPasswordResetLink(User $user, string $token): void
    {
        $hub = $this->hubs->current();
        $branding = $this->mail->branding($hub);
        $url = $branding['frontend_url'].'/reset-password?token='.urlencode($token).'&email='.urlencode((string) $user->email);
        $copy = $this->emailTemplates->resolve($hub, 'password_reset', EmailTemplateCatalog::AUDIENCE_USER, [
            'user_name' => $user->name ?: 'there',
            'site_name' => $branding['site_name'],
        ]);

        $this->mail->sendToUser(
            $user,
            subject: $copy['subject'],
            eyebrow: $copy['eyebrow'],
            heading: $copy['heading'],
            intro: $copy['intro'],
            cta: ['label' => $copy['cta_label'] ?: 'Reset password', 'url' => $url],
            closing: $copy['closing'],
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
