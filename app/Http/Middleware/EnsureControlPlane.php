<?php

namespace App\Http\Middleware;

use App\Services\HubService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Platform Power Admin tools (hub registry, checklists, capabilities matrix, etc.)
 * run only on the Central Hub Controller deploy.
 */
class EnsureControlPlane
{
    public function __construct(
        private readonly HubService $hubs
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Resolve (and heal) this deploy's hub row before the gate.
        $current = $this->hubs->current();

        if (! $current->isControlPlane()) {
            $slug = (string) config('hub.current_slug', 'shared');
            $type = (string) ($current->type ?? '');

            return response()->json([
                'message' => 'Platform control tools are only available on the Central Hub Controller. This deploy is a content hub.',
                'hint' => 'On the Central deploy set HUB_SLUG=central, HUB_TYPE=central, HUB_IS_CONTROL_PLANE=true, then run: php artisan migrate --force && php artisan config:clear && php artisan cache:clear',
                'hub_slug' => $slug,
                'hub_type' => $type,
                'is_control_plane_env' => (bool) config('hub.is_control_plane', false),
            ], 403);
        }

        return $next($request);
    }
}
