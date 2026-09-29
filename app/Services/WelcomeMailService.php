<?php

namespace App\Services;

use App\Mail\WelcomeMail;
use App\Models\User;
use App\Support\EmailTemplateCatalog;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class WelcomeMailService
{
    public function __construct(
        private readonly HubService $hubs,
        private readonly EmailTemplateService $emailTemplates
    ) {}

    public function send(User $user): void
    {
        if (! $user->email) {
            return;
        }

        $hub = $this->hubs->current();
        $frontendUrl = $hub->frontendBaseUrl();
        $fromEmail = $hub->mailFromAddress();
        $supportEmail = filled($hub->from_email)
            ? (string) $hub->from_email
            : $fromEmail;

        $vars = [
            'user_name' => $user->name ?: 'there',
            'user_email' => (string) $user->email,
            'site_name' => $hub->name,
            'support_email' => $supportEmail,
        ];

        $copy = $this->emailTemplates->resolve(
            $hub,
            'user_registered',
            EmailTemplateCatalog::AUDIENCE_USER,
            $vars
        );

        $data = [
            'username' => $vars['user_name'],
            'site_name' => $hub->name,
            'logo_url' => $hub->logoAbsoluteUrl(),
            'primary_color' => $hub->primary_color ?: '#1d4ed8',
            'secondary_color' => $hub->secondary_color ?: '#0f766e',
            'support_email' => $supportEmail,
            'from_email' => $fromEmail,
            'login_url' => $frontendUrl.'/login',
            'explore_url' => $frontendUrl.'/',
            'subject' => $copy['subject'],
            'eyebrow' => $copy['eyebrow'],
            'heading' => $copy['heading'],
            'intro' => $copy['intro'],
            'closing' => $copy['closing'],
            'cta_label' => $copy['cta_label'] ?: 'Log in',
        ];

        try {
            Mail::to($user->email)->send(new WelcomeMail($data));
        } catch (Throwable $e) {
            Log::warning('Failed to send welcome email.', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
