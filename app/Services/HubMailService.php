<?php

namespace App\Services;

use App\Mail\BrandedNotificationMail;
use App\Models\Hub;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Shared branded transactional / admin notifications for remaining product flows.
 */
class HubMailService
{
    public function __construct(
        private readonly HubService $hubs,
        private readonly AdminMailRecipientService $adminRecipients
    ) {}

    /**
     * @return array{
     *   site_name: string,
     *   logo_url: ?string,
     *   primary_color: string,
     *   secondary_color: string,
     *   support_email: string,
     *   from_email: string,
     *   frontend_url: string,
     *   login_url: string,
     * }
     */
    public function branding(?Hub $hub = null): array
    {
        $hub ??= $this->hubs->current();
        $fromEmail = $hub->mailFromAddress();
        $supportEmail = filled($hub->from_email) ? (string) $hub->from_email : $fromEmail;
        $frontendUrl = rtrim((string) config('app.frontend_url', 'http://localhost:5173'), '/');

        return [
            'site_name' => $hub->name,
            'logo_url' => $hub->logoAbsoluteUrl(),
            'primary_color' => $hub->primary_color ?: '#1d4ed8',
            'secondary_color' => $hub->secondary_color ?: '#0f766e',
            'support_email' => $supportEmail,
            'from_email' => $fromEmail,
            'frontend_url' => $frontendUrl,
            'login_url' => $frontendUrl.'/login',
        ];
    }

    public function formatMoney(float $amount, string $currency = 'gbp'): string
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

    /**
     * @param  list<array{label: string, value: string}>  $fields
     * @param  list<string>  $bullets
     * @param  array{label: string, url: string}|null  $cta
     */
    public function sendToAddress(
        string $email,
        string $subject,
        string $eyebrow,
        string $heading,
        string $intro,
        array $fields = [],
        array $bullets = [],
        ?array $cta = null,
        ?string $closing = null,
        ?string $footerNote = null,
        ?Hub $hub = null,
    ): void {
        $email = strtolower(trim($email));
        if ($email === '') {
            return;
        }

        $branding = $this->branding($hub);

        try {
            Mail::to($email)->send(new BrandedNotificationMail(
                branding: $branding,
                subjectLine: $subject,
                eyebrow: $eyebrow,
                heading: $heading,
                intro: $intro,
                fields: $fields,
                bullets: $bullets,
                cta: $cta,
                closing: $closing,
                footerNote: $footerNote,
            ));
        } catch (Throwable $e) {
            Log::warning('Failed to send branded notification email.', [
                'recipient' => $email,
                'subject' => $subject,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function sendToUser(
        User $user,
        string $subject,
        string $eyebrow,
        string $heading,
        string $intro,
        array $fields = [],
        array $bullets = [],
        ?array $cta = null,
        ?string $closing = null,
        ?string $footerNote = null,
        ?Hub $hub = null,
    ): void {
        if (! $user->email) {
            return;
        }

        $this->sendToAddress(
            $user->email,
            $subject,
            $eyebrow,
            $heading,
            $intro,
            $fields,
            $bullets,
            $cta,
            $closing,
            $footerNote,
            $hub
        );
    }

    /**
     * @param  list<array{label: string, value: string}>  $fields
     * @param  list<string>  $bullets
     */
    public function sendToAdmins(
        string $subject,
        string $eyebrow,
        string $heading,
        string $intro,
        array $fields = [],
        array $bullets = [],
        ?array $cta = null,
        ?string $closing = null,
        ?string $excludeEmail = null,
        ?Hub $hub = null,
    ): void {
        $hub ??= $this->hubs->current();
        $exclude = $excludeEmail ? strtolower(trim($excludeEmail)) : null;

        foreach ($this->adminRecipients->emailsForHub($hub) as $email) {
            if ($exclude && $email === $exclude) {
                continue;
            }

            $this->sendToAddress(
                $email,
                $subject,
                $eyebrow,
                $heading,
                $intro,
                $fields,
                $bullets,
                $cta,
                $closing,
                'You received this because your role has “Receive admin emails” enabled.',
                $hub
            );
        }
    }
}
