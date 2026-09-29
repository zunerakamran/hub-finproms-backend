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
        if (! $this->hubs->current()->isControlPlane()) {
            return response()->json([
                'message' => 'Platform control tools are only available on the Central Hub Controller. This deploy is a content hub.',
            ], 403);
        }

        return $next($request);
    }
}
