<?php

namespace App\Services;

use App\Mail\WelcomeMail;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class WelcomeMailService
{
    public function __construct(
        private readonly HubService $hubs
    ) {}

    public function send(User $user): void
    {
        if (! $user->email) {
            return;
        }

        $hub = $this->hubs->current();
        $frontendUrl = rtrim((string) config('app.frontend_url', 'http://localhost:5173'), '/');
        $fromEmail = $hub->mailFromAddress();
        $supportEmail = filled($hub->from_email)
            ? (string) $hub->from_email
            : $fromEmail;

        $data = [
            'username' => $user->name ?: 'there',
            'site_name' => $hub->name,
            'logo_url' => $hub->logoAbsoluteUrl(),
            'primary_color' => $hub->primary_color ?: '#1d4ed8',
            'secondary_color' => $hub->secondary_color ?: '#0f766e',
            'support_email' => $supportEmail,
            'from_email' => $fromEmail,
            'login_url' => $frontendUrl.'/login',
            'explore_url' => $frontendUrl.'/',
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
