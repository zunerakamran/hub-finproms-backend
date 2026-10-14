<?php

/**
 * NFR-SEC-NAV — external link / copied-URL / cross-site entry hardening.
 *
 * Frontend enforces the user-facing error page. This config documents the
 * allowlist and helpers for open-redirect checks / optional security events.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Feature enabled
    |--------------------------------------------------------------------------
    |
    | When false, the optional report endpoint still accepts calls but does not
    | record Security Monitoring events. Frontend guard is independent.
    |
    */

    'enabled' => env('SECURITY_NAV_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Frontend paths allowlisted for external entry (email / Stripe / auth)
    |--------------------------------------------------------------------------
    |
    | These must stay reachable when opened from email or payment providers.
    | Keep in sync with hub-finproms-frontend src/security/navigationContinuity.js
    |
    */

    'allowlist_exact' => [
        '/',
        '/login',
        '/register',
        '/forgot-password',
        '/reset-password',
        '/verify-email',
        '/client-admin/login',
        '/power-admin/login',
    ],

    'allowlist_prefixes' => [
        '/subscriptions/success',
        '/subscriptions/bank-transfer',
        '/purchases/success',
        '/my-dashboard/payment-card/success',
        '/my-dashboard/advisor-billing/success',
    ],

    /*
    |--------------------------------------------------------------------------
    | Record Security Monitoring events for blocked external entry
    |--------------------------------------------------------------------------
    */

    'record_security_events' => env('SECURITY_NAV_RECORD_EVENTS', true),

];
