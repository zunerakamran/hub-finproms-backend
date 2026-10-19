<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'currency' => env('STRIPE_CURRENCY', 'usd'),
    ],

    // Outbound HTTPS certificate verification (cPanel sync, embed proxy).
    // Set HTTP_TLS_VERIFY=false only for local/dev with broken/self-signed certs.
    'http_tls_verify' => filter_var(env('HTTP_TLS_VERIFY', true), FILTER_VALIDATE_BOOLEAN),

    'website_compliance' => [
        'hub_api_url' => env('WEBSITE_COMPLIANCE_HUB_API_URL'),
        'uploads_origin' => env('WEBSITE_COMPLIANCE_UPLOADS_ORIGIN'),
        'scheduler_secret' => env('SCHEDULER_SECRET'),
        // Empty = derive from APP_URL (correct for myhub / shared / other white-labels)
        'template_preview_base_url' => env('TEMPLATE_PREVIEW_BASE_URL'),
        'template_preview_node_binary' => env('TEMPLATE_PREVIEW_NODE_BINARY'),
        /*
         | Showcase templates owned by each hub slug (HUB_SLUG).
         | Only those slugs are seeded / kept in that deploy's wc_* tables.
         | Optional WC_SHOWCASE_TEMPLATES=slug1,slug2 overrides the map for this deploy.
         */
        'hub_showcase_templates' => [
            'myhub' => ['template4'],
            'shared' => [],
        ],
        'showcase_templates' => env('WC_SHOWCASE_TEMPLATES'),
    ],

    /*
    | Hub backup / restore (dual store: local hub + Central).
    | CENTRAL_API_URL = https://central.example.com/api  (preferred on content hubs).
    | CENTRAL_ALLOWED_RECEIVE_HOSTS = comma-separated hosts allowed when CENTRAL_API_URL
    | is empty and Central passes X-Central-Receive-Url (e.g. central.fin-proms.com).
    | HUB_BACKUP_SECRET = shared fallback key when per-hub backup_token is empty.
    */
    'hub_backup' => [
        'central_api_url' => env('CENTRAL_API_URL'),
        'allowed_receive_hosts' => env('CENTRAL_ALLOWED_RECEIVE_HOSTS', ''),
        'secret' => env('HUB_BACKUP_SECRET'),
        'upload_timeout' => (int) env('HUB_BACKUP_UPLOAD_TIMEOUT', 600),
        // NFR-BACKUP-ENC: encrypt ZIP archives at rest (AES-256-GCM).
        // Prefer a shared HUB_BACKUP_ENCRYPTION_KEY on Central + content hubs.
        'encrypt_at_rest' => filter_var(env('HUB_BACKUP_ENCRYPT_AT_REST', true), FILTER_VALIDATE_BOOLEAN),
        'encryption_key' => env('HUB_BACKUP_ENCRYPTION_KEY'),
    ],

    /*
    | Hub code updates (Phase 2): Central pushes release zips to selected hubs.
    | HUB_CODE_UPDATE_SECRET = shared fallback when per-hub code_update_token is empty
    | (do not reuse HUB_BACKUP_SECRET). Download hosts are allowlisted to Central.
    */
    'hub_code_update' => [
        'secret' => env('HUB_CODE_UPDATE_SECRET'),
        'download_timeout' => (int) env('HUB_CODE_UPDATE_DOWNLOAD_TIMEOUT', 600),
        'apply_timeout' => (int) env('HUB_CODE_UPDATE_APPLY_TIMEOUT', 600),
        // Comma-separated extra hosts allowed for artifact downloads (in addition to
        // CENTRAL_API_URL / APP_URL). Example: central.fin-proms.com
        'allowed_download_hosts' => env('HUB_CODE_UPDATE_ALLOWED_DOWNLOAD_HOSTS', ''),
    ],

];
