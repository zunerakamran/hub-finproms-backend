<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

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

        // Record successful mutating API requests (posts, imports, settings, etc.).
        $middleware->appendToGroup('api', [
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
                $limiter = 'unknown';
                if ($request && str_contains((string) $request->path(), 'auth')) {
                    $limiter = 'auth';
                } elseif ($request && str_contains((string) $request->path(), 'import')) {
                    $limiter = 'imports';
                } elseif ($request && str_contains((string) $request->path(), 'website-compliance')) {
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
    })->create();
