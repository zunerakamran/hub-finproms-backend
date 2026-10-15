<?php

/**
 * NFR-SEC-APP — application attack-surface baseline (SQLi / XSS / CSRF /
 * brute-force / app-layer abuse & DDoS throttling).
 *
 * NFR-SEC-VAL — server-side validation + sanitization of user inputs (§9.8).
 *
 * Network volumetric DDoS and commercial WAF remain ops / CDN (see §9.6).
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Global API rate limit (app-layer abuse / DDoS mitigation)
    |--------------------------------------------------------------------------
    */

    'api_requests_per_minute' => (int) env('SECURITY_API_RATE_PER_MINUTE', 120),

    /*
    |--------------------------------------------------------------------------
    | Auth brute-force throttle
    |--------------------------------------------------------------------------
    */

    'auth_requests_per_minute' => (int) env('SECURITY_AUTH_RATE_PER_MINUTE', 20),

    /*
    |--------------------------------------------------------------------------
    | Sanitize rich HTML on save (Terms / Privacy / Cookies / compliance / WC)
    |--------------------------------------------------------------------------
    */

    'sanitize_rich_html' => env('SECURITY_SANITIZE_RICH_HTML', true),

    /*
    |--------------------------------------------------------------------------
    | NFR-SEC-VAL — scrub null bytes / control chars on all request strings
    |--------------------------------------------------------------------------
    */

    'sanitize_request_input' => env('SECURITY_SANITIZE_REQUEST_INPUT', true),

];
