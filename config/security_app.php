<?php

/**
 * NFR-SEC-APP — application attack-surface baseline (SQLi / XSS / CSRF /
 * brute-force / app-layer abuse & DDoS throttling).
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
    | Sanitize rich HTML on save (Terms / Privacy / Cookie notice)
    |--------------------------------------------------------------------------
    */

    'sanitize_rich_html' => env('SECURITY_SANITIZE_RICH_HTML', true),

];
