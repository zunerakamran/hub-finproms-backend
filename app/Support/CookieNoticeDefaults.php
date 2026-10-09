<?php

namespace App\Support;

use App\Models\Hub;

/**
 * Default Cookie Notice HTML per hub type (essential cookies / PECR).
 * Capable admins can replace this via the dashboard editor.
 */
class CookieNoticeDefaults
{
    public static function forType(string $type): string
    {
        return match ($type) {
            Hub::TYPE_CENTRAL => self::central(),
            Hub::TYPE_WHITE_LABEL => self::whiteLabel(),
            default => self::shared(),
        };
    }

    public static function shared(): string
    {
        return <<<'HTML'
<h2>Cookie Notice — Shared Hub</h2>
<p>This Shared Hub uses <strong>essential cookies</strong> only to keep you signed in securely (session) and to protect forms against cross-site request forgery (CSRF). These are required for the service to work and are not used for advertising or analytics.</p>
<p>We do not currently set non-essential or marketing cookies. If that changes, we will ask for your consent first. See this hub’s Privacy Policy for how personal data is processed.</p>
HTML;
    }

    public static function whiteLabel(): string
    {
        return <<<'HTML'
<h2>Cookie Notice — White-labelled Hub</h2>
<p>This private hub uses <strong>essential cookies</strong> only for secure sign-in (session) and CSRF protection. They are required for the hub to function and are not used for advertising or analytics.</p>
<p>Non-essential or marketing cookies are not used in the current product. If that changes, consent will be requested where required. See the Privacy Policy for further detail.</p>
HTML;
    }

    public static function central(): string
    {
        return <<<'HTML'
<h2>Cookie Notice — Central Hub Controller</h2>
<p>Central Hub uses <strong>essential cookies</strong> only for secure operator sign-in (session) and CSRF protection. These cookies are necessary to operate the control plane and are not used for advertising or analytics.</p>
<p>No marketing or analytics cookies are set by the current product. See the Privacy Policy for how personal data is processed.</p>
HTML;
    }
}
