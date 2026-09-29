<?php

namespace App\Http\Middleware;

use App\Services\PowerAdminCapabilitiesService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePowerAdminCapability
{
    public function __construct(
        private readonly PowerAdminCapabilitiesService $capabilities
    ) {}

    /**
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string $capability): Response
    {
        if (! $request->user()?->isPowerAdmin()) {
            return response()->json([
                'message' => 'Power admin access required.',
            ], 403);
        }

        if (! $this->capabilities->can($capability)) {
            return response()->json([
                'message' => 'This Power Admin capability is disabled.',
                'capability' => $capability,
            ], 403);
        }

        return $next($request);
    }
}
