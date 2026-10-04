<?php

namespace App\Providers;

use App\Services\WhiteLabelDatabaseService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One instance per request so remote DB leases nest instead of reconnecting.
        $this->app->singleton(WhiteLabelDatabaseService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(20)->by($request->ip());
        });

        RateLimiter::for('imports', function (Request $request) {
            $key = $request->user()?->id ?: $request->ip();

            return Limit::perMinute(6)->by('imports:'.$key);
        });

        RateLimiter::for('wc-publish', function (Request $request) {
            $key = $request->user()?->id ?: $request->ip();

            return Limit::perMinute(30)->by('wc-publish:'.$key);
        });
    }
}
