<?php

namespace App\Http\Middleware;

use App\Services\FirewallService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * NFR-SEC-FW — reject requests from denied / temporarily blocked IPs
 * and hub/user access restrictions (enforce mode). Monitor mode logs only.
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

        $decision = $this->firewall->accessRestrictions()->checkRequest($request);

        if (! empty($decision['denied'])) {
            $this->firewall->accessRestrictions()->recordDeniedRequest(
                $request,
                (string) ($decision['reason'] ?? 'access_restriction')
            );

            return response()->json([
                'message' => 'Access denied by application access restrictions.',
            ], 403);
        }

        if (! empty($decision['would_block'])) {
            $this->firewall->accessRestrictions()->recordWouldBlockRequest(
                $request,
                (string) ($decision['reason'] ?? 'access_restriction')
            );
        }

        return $next($request);
    }
}
