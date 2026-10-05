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
    | CENTRAL_API_URL = https://central.example.com/api  (content hubs; optional if
    | Central always triggers backups and passes X-Central-Receive-Url).
    | HUB_BACKUP_SECRET = shared fallback key when per-hub backup_token is empty.
    */
    'hub_backup' => [
        'central_api_url' => env('CENTRAL_API_URL'),
        'secret' => env('HUB_BACKUP_SECRET'),
        'upload_timeout' => (int) env('HUB_BACKUP_UPLOAD_TIMEOUT', 600),
    ],

];
