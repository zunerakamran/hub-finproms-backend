<?php

namespace App\Services;

use App\Mail\AdminNewUserRegistrationMail;
use App\Models\Hub;
use App\Models\User;
use App\Support\EmailTemplateCatalog;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class AdminNewUserRegistrationMailService
{
    public function __construct(
        private readonly HubService $hubs,
        private readonly AdminMailRecipientService $adminRecipients,
        private readonly EmailTemplateService $emailTemplates
    ) {}

    /**
     * Notify users with the Receive admin emails capability
     * when a new account is registered or created.
     */
    public function send(User $user, ?Hub $hub = null): void
    {
        $hub ??= $this->hubs->current();
        $newEmail = strtolower(trim((string) $user->email));

        $recipients = array_values(array_filter(
            $this->adminRecipients->emailsForHub($hub),
            fn (string $email) => $email !== $newEmail
        ));

        if ($recipients === []) {
            return;
        }

        $fromEmail = $hub->mailFromAddress();
        $supportEmail = filled($hub->from_email)
            ? (string) $hub->from_email
            : $fromEmail;

        $vars = [
            'user_name' => $user->name ?: 'Unknown',
            'user_email' => $user->email ?: '—',
            'site_name' => $hub->name,
            'support_email' => $supportEmail,
        ];

        $copy = $this->emailTemplates->resolve(
            $hub,
            'user_registered',
            EmailTemplateCatalog::AUDIENCE_ADMIN,
            $vars
        );

        $data = [
            'site_name' => $hub->name,
            'logo_url' => $hub->logoAbsoluteUrl(),
            'primary_color' => $hub->primary_color ?: '#1d4ed8',
            'secondary_color' => $hub->secondary_color ?: '#0f766e',
            'support_email' => $supportEmail,
            'from_email' => $fromEmail,
            'username' => $vars['user_name'],
            'user_email' => $vars['user_email'],
            'subject' => $copy['subject'],
            'eyebrow' => $copy['eyebrow'],
            'heading' => $copy['heading'],
            'intro' => $copy['intro'],
            'closing' => $copy['closing'],
        ];

        foreach ($recipients as $email) {
            try {
                Mail::to($email)->send(new AdminNewUserRegistrationMail($data));
            } catch (Throwable $e) {
                Log::warning('Failed to send admin new user registration email.', [
                    'user_id' => $user->id,
                    'recipient' => $email,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
