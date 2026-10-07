<?php

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;
use Laravel\Sanctum\Sanctum;

$frontendHost = null;
$frontend = env('FRONTEND_URL');
if (is_string($frontend) && $frontend !== '') {
    $host = parse_url($frontend, PHP_URL_HOST);
    $port = parse_url($frontend, PHP_URL_PORT);
    if (is_string($host) && $host !== '') {
        $frontendHost = $port ? $host.':'.$port : $host;
    }
}

$defaultStateful = array_filter([
    'localhost',
    'localhost:5173',
    'localhost:3000',
    '127.0.0.1',
    '127.0.0.1:5173',
    '127.0.0.1:8000',
    '::1',
    Sanctum::currentApplicationUrlWithPort(),
    $frontendHost,
]);

return [

    /*
    |--------------------------------------------------------------------------
    | Stateful Domains
    |--------------------------------------------------------------------------
    |
    | Requests from the following domains / hosts will receive stateful API
    | authentication cookies. Typically, these should include your local
    | and production domains which access your API via a frontend SPA.
    |
    */

    'stateful' => array_values(array_unique(array_filter(array_map(
        'trim',
        explode(',', (string) env('SANCTUM_STATEFUL_DOMAINS', implode(',', $defaultStateful)))
    )))),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Guards
    |--------------------------------------------------------------------------
    |
    | The authentication guards that will be checked when Sanctum is trying to
    | authenticate a request. If none of these guards are able to authenticate
    | the request, Sanctum will use the bearer token that's present on an
    | incoming request for authentication.
    |
    */

    'guard' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Expiration Minutes
    |--------------------------------------------------------------------------
    |
    | This value controls the number of minutes until an issued token will be
    | considered expired. This will override any values set in the token's
    | "expires_at" attribute, but first-party sessions are not affected.
    |
    */

    // Minutes until personal access tokens expire (null = never). Default 7 days.
    'expiration' => ($v = env('SANCTUM_EXPIRATION', 10080)) === '' || $v === null ? null : (int) $v,

    /*
    |--------------------------------------------------------------------------
    | Token Prefix
    |--------------------------------------------------------------------------
    */

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Middleware
    |--------------------------------------------------------------------------
    */

    'middleware' => [
        'authenticate_session' => AuthenticateSession::class,
        'encrypt_cookies' => EncryptCookies::class,
        'validate_csrf_token' => ValidateCsrfToken::class,
    ],

];
