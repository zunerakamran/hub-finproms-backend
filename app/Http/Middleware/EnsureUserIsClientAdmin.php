<?php

namespace App\Http\Middleware;

use App\Services\CapabilitiesMatrixService;
use App\Services\HubService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsClientAdmin
{
    public function __construct(
        private readonly HubService $hubs,
        private readonly CapabilitiesMatrixService $matrix
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return response()->json([
                'message' => 'Hub admin access required.',
            ], 403);
        }

        $hub = $this->hubs->current();
        if (! $this->matrix->roleHasHubDashboardAccess($hub, (string) $user->role)) {
            return response()->json([
                'message' => 'Hub admin access required.',
            ], 403);
        }

        return $next($request);
    }
}
