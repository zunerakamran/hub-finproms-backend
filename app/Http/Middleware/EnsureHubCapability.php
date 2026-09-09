<?php

namespace App\Http\Middleware;

use App\Services\CapabilitiesMatrixService;
use App\Services\HubService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureHubCapability
{
    public function __construct(
        private readonly HubService $hubs,
        private readonly CapabilitiesMatrixService $matrix
    ) {}

    /**
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string $capability): Response
    {
        $hub = $this->hubs->current();
        $user = $request->user();

        // Guest / no user: hub-level checklist only (public member routes).
        if (! $user) {
            if (! $hub->can($capability)) {
                return response()->json([
                    'message' => 'This capability is disabled for this hub by Power Admin.',
                    'capability' => $capability,
                ], 403);
            }

            return $next($request);
        }

        // Authenticated: role matrix (falls back to hub checklist inside service).
        if (! $this->matrix->roleCan($hub, (string) $user->role, $capability)) {
            return response()->json([
                'message' => 'This capability is disabled for your role on this hub.',
                'capability' => $capability,
                'role' => $user->role,
            ], 403);
        }

        return $next($request);
    }
}
