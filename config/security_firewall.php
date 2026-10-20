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
    | GeoIP (hub access restrictions)
    |--------------------------------------------------------------------------
    |
    | Country / city for access rules prefer CDN headers (CF-IPCountry /
    | CF-IPCity, or X-App-Geo-*). Optional HTTP fallback (ip-api.com by default)
    | is cached. Disable HTTP in locked-down hosts with GEOIP_HTTP_ENABLED=false.
    |
    */

    // When true, 127.0.0.1 / ::1 / RFC1918 clients skip hub geo/IP access rules
    // (env deny-list still applies). Disable only if you must gate private networks.
    'access_restriction_bypass_private_ips' => env('ACCESS_RESTRICTION_BYPASS_PRIVATE_IPS', true),

    'geoip' => [
        'http_enabled' => env('GEOIP_HTTP_ENABLED', true),
        'http_url' => env(
            'GEOIP_HTTP_URL',
            'http://ip-api.com/json/{ip}?fields=status,countryCode,city,proxy,hosting'
        ),
        'cache_minutes' => (int) env('GEOIP_CACHE_MINUTES', 1440),
        'timeout_seconds' => (int) env('GEOIP_TIMEOUT', 2),
        // When true, ip-api "hosting" (datacenter) exits count as VPN for Restrict/Allow VPN.
        'treat_hosting_as_vpn' => env('GEOIP_TREAT_HOSTING_AS_VPN', true),
        // Map of IP => ['country' => 'GB', 'city' => 'London', 'vpn' => true] for tests / staging.
        'test_overrides' => [],
    ],

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
