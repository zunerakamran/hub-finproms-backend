<?php

/**
 * NFR-SEC-FW — application-layer firewall + upload malware protection.
 *
 * Not a network WAF / IDS replacement (see section 6F out-of-scope). Host /
 * cPanel firewall remains an ops responsibility; this config gates API access
 * and uploaded files inside the Laravel app.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Feature enabled
    |--------------------------------------------------------------------------
    */

    'enabled' => env('SECURITY_FIREWALL_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | IP deny list (application firewall)
    |--------------------------------------------------------------------------
    |
    | Comma-separated IPv4/IPv6 addresses or CIDR ranges in env, plus optional
    | temporary blocks written to cache when failed-login threshold is crossed.
    |
    */

    'denied_ips' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('SECURITY_FIREWALL_DENIED_IPS', ''))
    ))),

    'auto_block_on_failed_login_threshold' => env('SECURITY_FIREWALL_AUTO_BLOCK', false),

    'auto_block_minutes' => (int) env('SECURITY_FIREWALL_AUTO_BLOCK_MINUTES', 60),

    'record_security_events' => env('SECURITY_FIREWALL_RECORD_EVENTS', true),

    /*
    |--------------------------------------------------------------------------
    | Upload / malware protection
    |--------------------------------------------------------------------------
    |
    | Always reject dangerous extensions (PHP webshells, executables, etc.).
    | Optional ClamAV (`clamscan`) when MALWARE_SCAN_ENABLED=true and the
    | binary is installed on the server.
    |
    */

    'upload_scan_enabled' => env('SECURITY_UPLOAD_SCAN_ENABLED', true),

    'blocked_extensions' => [
        'php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'php7', 'php8',
        'exe', 'dll', 'so', 'bat', 'cmd', 'com', 'msi', 'scr',
        'sh', 'bash', 'cgi', 'pl', 'py', 'rb', 'ps1', 'vbs', 'js', 'jse',
        'htaccess', 'htpasswd', 'ini', 'asp', 'aspx', 'jsp',
    ],

    // SVG can carry script; disallow as uploadable content by default.
    'block_svg' => env('SECURITY_UPLOAD_BLOCK_SVG', true),

    'malware_scan_enabled' => env('MALWARE_SCAN_ENABLED', false),

    'malware_scan_binary' => env('MALWARE_SCAN_BINARY', 'clamscan'),

    // When true, refuse uploads if the scanner binary is missing / errors.
    // Default false so cPanel hosts without ClamAV still accept clean files.
    'malware_scan_required' => env('MALWARE_SCAN_REQUIRED', false),

    'malware_scan_timeout_seconds' => (int) env('MALWARE_SCAN_TIMEOUT', 30),

];
