<?php

namespace App\Http\Middleware;

use App\Services\FirewallService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * NFR-SEC-FW — reject requests from denied / temporarily blocked IPs
 * and hub-managed geo / IP access restrictions.
 */
class DenyBlockedIps
{
    public function __construct(private FirewallService $firewall) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->firewall->isIpDenied($request->ip())) {
            $this->firewall->recordDeniedRequest($request);

            return response()->json([
                'message' => 'Access denied by application firewall.',
            ], 403);
        }

        if ($this->firewall->accessRestrictions()->isRequestDenied($request)) {
            $this->firewall->accessRestrictions()->recordDeniedRequest($request);

            return response()->json([
                'message' => 'Access denied by application access restrictions.',
            ], 403);
        }

        return $next($request);
    }
}
