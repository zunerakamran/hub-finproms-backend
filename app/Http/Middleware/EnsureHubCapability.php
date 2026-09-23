<?php

namespace App\Http\Middleware;

use App\Models\Hub;
use App\Models\User;
use App\Services\ActingAdvisorService;
use App\Services\ActingHubService;
use App\Services\CapabilitiesMatrixService;
use App\Services\HubService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureHubCapability
{
    public function __construct(
        private readonly HubService $hubs,
        private readonly CapabilitiesMatrixService $matrix,
        private readonly ActingHubService $actingHubs,
        private readonly ActingAdvisorService $actingAdvisors
    ) {}

    /**
     * Gate by one or more hub capabilities (OR).
     * Example: hub_can:smc_view_all_requests,smc_assign_requests
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$capabilities): Response
    {
        $capabilities = array_values(array_filter($capabilities, fn ($c) => $c !== ''));
        if ($capabilities === []) {
            return $next($request);
        }

        $hub = $this->hubs->current();
        $user = $request->user();

        // Guest / no user: hub-level checklist only (public member routes).
        if (! $user) {
            foreach ($capabilities as $capability) {
                if ($hub->can($capability)) {
                    return $next($request);
                }
            }

            return response()->json([
                'message' => 'This capability is disabled for this hub by Power Admin.',
                'capability' => $capabilities[0],
                'capabilities' => $capabilities,
            ], 403);
        }

        foreach ($capabilities as $capability) {
            // When controlling a white-label hub, hub-scoped caps follow that hub's matrix.
            $hubForCap = $this->actingHubs->capabilityHub($user, $capability);

            if ($this->controlPlaneRemoteWebsiteComplianceAllows($user, $hubForCap, $capability)) {
                return $next($request);
            }

            if ($this->matrix->userCan($hubForCap, $user, $capability)) {
                return $next($request);
            }
        }

        $failedHub = $this->actingHubs->capabilityHub($user, $capabilities[0]);
        $effectiveRole = $this->actingAdvisors->effectiveCapabilityRole($user);

        return response()->json([
            'message' => 'This capability is disabled for your role on this hub.',
            'capability' => $capabilities[0],
            'capabilities' => $capabilities,
            'role' => $user->role,
            'effective_role' => $effectiveRole,
            'hub_id' => $failedHub->id,
            'hub_slug' => $failedHub->slug,
        ], 403);
    }

    /**
     * Power Admin / FinProms Admin are shared-hub only. While the switcher is on a
     * white-label with Website Compliance enabled, allow WC route caps remotely.
     */
    private function controlPlaneRemoteWebsiteComplianceAllows(User $user, Hub $hubForCap, string $capability): bool
    {
        if (! ActingHubService::isControlPlaneRole((string) $user->role)) {
            return false;
        }

        if (! $this->actingHubs->isActingOnWhiteLabel($user)) {
            return false;
        }

        if (! Hub::isWebsiteComplianceCapability($capability) && $capability !== 'module_website_compliance') {
            return false;
        }

        return $hubForCap->hasWebsiteComplianceModule();
    }
}
