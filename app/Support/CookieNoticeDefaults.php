<?php

namespace App\Support;

/**
 * Essential-cookies notice (PECR / UK GDPR).
 * This product uses session + CSRF cookies only — no analytics/marketing cookies in v1.
 */
class CookieNoticeDefaults
{
    public static function content(): string
    {
        return <<<'HTML'
<p>This hub uses <strong>essential cookies</strong> only to keep you signed in securely (session) and to protect forms against cross-site request forgery (CSRF). These are required for the service to work and are not used for advertising or analytics.</p>
<p>We do not currently set non-essential or marketing cookies. If that changes, we will ask for your consent first. See the Privacy Policy for how personal data is processed.</p>
HTML;
    }

    /**
     * @return array{essential_only: bool, version: int, content: string}
     */
    public static function publicPayload(): array
    {
        return [
            'essential_only' => true,
            'version' => 1,
            'content' => self::content(),
        ];
    }
}
