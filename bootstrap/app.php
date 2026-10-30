<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // First-party SPA cookie sessions (Sanctum stateful) + CSRF for FRONTEND_URL.
        $middleware->statefulApi();

        // Only honour X-Forwarded-* (client IP / scheme) from configured edge proxies.
        // Empty TRUSTED_PROXIES = ignore forwarding headers (safe for direct origin access).
        $trustedProxies = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('TRUSTED_PROXIES', ''))
        )));
        if ($trustedProxies !== []) {
            $at = in_array('*', $trustedProxies, true) ? '*' : $trustedProxies;
            $middleware->trustProxies(
                at: $at,
                headers: Request::HEADER_X_FORWARDED_FOR
                    | Request::HEADER_X_FORWARDED_HOST
                    | Request::HEADER_X_FORWARDED_PORT
                    | Request::HEADER_X_FORWARDED_PROTO
                    | Request::HEADER_X_FORWARDED_AWS_ELB
            );
        }

        $middleware->alias([
            'client_admin' => \App\Http\Middleware\EnsureUserIsClientAdmin::class,
            'power_admin' => \App\Http\Middleware\EnsureUserIsPowerAdmin::class,
            'control_plane' => \App\Http\Middleware\EnsureControlPlane::class,
            'admin' => \App\Http\Middleware\EnsureUserIsClientAdmin::class,
            'hub_can' => \App\Http\Middleware\EnsureHubCapability::class,
            'pa_can' => \App\Http\Middleware\EnsurePowerAdminCapability::class,
            'acting_wl_wc_db' => \App\Http\Middleware\UseActingWhiteLabelWcDatabase::class,
        ]);

        // Remount WL DB before route-model binding so SMC/WC IDs resolve on the acting hub.
        $middleware->prependToPriorityList(
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            \App\Http\Middleware\UseActingWhiteLabelWcDatabase::class,
        );

        // NFR-SEC-APP: global API rate ceiling (Stripe webhook excluded in routes).
        $middleware->throttleApi('api');

        // NFR-SEC-FW + NFR-SEC-VAL + activity: IP deny, upload scan, input scrub,
        // headers, activity log.
        $middleware->appendToGroup('api', [
            \App\Http\Middleware\DenyBlockedIps::class,
            \App\Http\Middleware\ScanUploadedFiles::class,
            \App\Http\Middleware\SanitizeRequestInput::class,
            \App\Http\Middleware\SecurityHeaders::class,
            \App\Http\Middleware\LogApiActivity::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Symfony rejects route param names > 32 chars with DomainException → opaque 500.
        // Surface a clear message if a stale route:cache still has the old GC attachment param.
        $exceptions->render(function (\DomainException $e, \Illuminate\Http\Request $request) {
            if (! str_contains($e->getMessage(), 'cannot be longer than 32 characters')) {
                return null;
            }

            return response(
                'GC file download route is misconfigured (stale route cache). '
                .'On the server run: php artisan route:clear && php artisan optimize:clear',
                500,
                ['Content-Type' => 'text/plain; charset=UTF-8']
            );
        });

        $exceptions->reportable(function (\Illuminate\Http\Exceptions\ThrottleRequestsException $e): void {
            try {
                $request = request();
                $path = (string) ($request?->path() ?? '');
                $limiter = 'api';
                if (str_contains($path, 'auth')) {
                    $limiter = 'auth';
                } elseif (str_contains($path, 'import')) {
                    $limiter = 'imports';
                } elseif (str_contains($path, 'website-compliance')
                    && (str_contains($path, 'publish') || str_contains($path, 'approve'))) {
                    $limiter = 'wc-publish';
                }

                app(\App\Services\SecurityMonitoringService::class)
                    ->recordRateLimitHit(
                        app(\App\Services\HubService::class)->current(),
                        $limiter,
                        $request
                    );
            } catch (\Throwable) {
                // Never break rate-limit responses for monitoring.
            }
        });

        // Automatic system error feed (Support Tickets → System errors).
        // Captures unhandled backend exceptions even when no user filed a ticket.
        $exceptions->reportable(function (\Throwable $e): void {
            try {
                $request = request();
                $hub = null;
                try {
                    $hub = app(\App\Services\HubService::class)->current();
                } catch (\Throwable) {
                    $hub = null;
                }
                app(\App\Services\SystemErrorReportService::class)
                    ->recordThrowable($hub, $e, $request instanceof \Illuminate\Http\Request ? $request : null);
            } catch (\Throwable) {
                // Never break exception reporting.
            }
        });
    })->create();
