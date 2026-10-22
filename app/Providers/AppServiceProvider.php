<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        // Apply this deploy’s hub idle session timeout (SPA cookie + Sanctum)
        // and Central-managed deploy env (CENTRAL_API_URL, frontend path, …).
        // Skipped during migrate when hubs table may not exist yet.
        try {
            if (! $this->app->runningUnitTests()) {
                $hub = app(\App\Services\HubService::class)->current();
                app(\App\Services\HubDeployEnvService::class)->applyRuntimeConfig($hub);
                if (! $this->app->runningInConsole()) {
                    $minutes = $hub->resolvedSessionLifetimeMinutes();
                    config([
                        'session.lifetime' => $minutes,
                        'sanctum.expiration' => $minutes,
                    ]);
                }
            }
        } catch (\Throwable) {
            // Keep .env / config defaults until hubs are available.
        }

        // NFR-SEC-APP: brute-force — per IP and per email+IP when email present.
        RateLimiter::for('auth', function (Request $request) {
            $perMinute = max(5, (int) config('security_app.auth_requests_per_minute', 20));
            $email = strtolower(trim((string) $request->input('email', '')));
            $by = $email !== ''
                ? 'auth:'.$request->ip().'|'.$email
                : 'auth:'.$request->ip();

            return Limit::perMinute($perMinute)->by($by);
        });

        // NFR-SEC-APP: global API ceiling (app-layer abuse / volumetric mitigation).
        RateLimiter::for('api', function (Request $request) {
            $perMinute = max(30, (int) config('security_app.api_requests_per_minute', 120));
            $key = $request->user()?->id
                ? 'api:user:'.$request->user()->id
                : 'api:ip:'.$request->ip();

            return Limit::perMinute($perMinute)->by($key);
        });

        RateLimiter::for('imports', function (Request $request) {
            $key = $request->user()?->id ?: $request->ip();

            return Limit::perMinute(6)->by('imports:'.$key);
        });

        RateLimiter::for('wc-publish', function (Request $request) {
            $key = $request->user()?->id ?: $request->ip();

            return Limit::perMinute(30)->by('wc-publish:'.$key);
        });

        // Hub ↔ Central /internal/* machine callbacks (tighter than global API).
        RateLimiter::for('internal-hub', function (Request $request) {
            $perMinute = max(5, (int) config('services.hub_backup.internal_rate_per_minute', 30));

            return Limit::perMinute($perMinute)->by('internal-hub:'.$request->ip());
        });

        // DB restore is especially sensitive — keep a low ceiling.
        RateLimiter::for('internal-restore', function (Request $request) {
            $perMinute = max(1, (int) config('services.hub_backup.restore_rate_per_minute', 5));

            return Limit::perMinute($perMinute)->by('internal-restore:'.$request->ip());
        });

        // WC advisor embed proxy (incl. hub-scoped remote DB resolve).
        RateLimiter::for('wc-embed', function (Request $request) {
            return Limit::perMinute(60)->by('wc-embed:'.$request->ip());
        });
    }
}
