<?php

namespace App\Http\Middleware;

use App\Services\ActingHubService;
use App\Services\CapabilitiesMatrixService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsClientAdmin
{
    public function __construct(
        private readonly CapabilitiesMatrixService $matrix,
        private readonly ActingHubService $actingHubs
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

        // Capability-gated tools (Support Tickets, SMC, …) follow the acting hub
        // while the switcher is on a content hub; otherwise use this deploy.
        $hub = $this->actingHubs->targetHub($user);
        if (! $this->matrix->userHasHubDashboardAccess($hub, $user)) {
            return response()->json([
                'message' => 'Hub admin access required.',
            ], 403);
        }

        return $next($request);
    }
}
