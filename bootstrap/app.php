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
        $middleware->alias([
            'client_admin' => \App\Http\Middleware\EnsureUserIsClientAdmin::class,
            'power_admin' => \App\Http\Middleware\EnsureUserIsPowerAdmin::class,
            'admin' => \App\Http\Middleware\EnsureUserIsClientAdmin::class,
            'hub_can' => \App\Http\Middleware\EnsureHubCapability::class,
            'pa_can' => \App\Http\Middleware\EnsurePowerAdminCapability::class,
        ]);

        // Record successful mutating API requests (posts, imports, settings, etc.).
        $middleware->appendToGroup('api', [
            \App\Http\Middleware\LogApiActivity::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
