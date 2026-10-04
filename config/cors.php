<?php

$frontend = env('FRONTEND_URL', 'http://localhost:5173');
$extra = array_filter(array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))));
$local = env('APP_ENV', 'production') === 'production'
    ? []
    : ['http://localhost:5173', 'http://127.0.0.1:5173'];

$origins = array_values(array_unique(array_filter(array_merge([$frontend], $local, $extra))));

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Cookie (Sanctum SPA) auth requires supports_credentials=true and an
    | explicit origin allowlist (never "*").
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => $origins,

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
