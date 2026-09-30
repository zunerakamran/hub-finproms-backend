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
use Illuminate\Support\Facades\Auth;
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
        $user = $this->resolveUser($request);

        // Guest / no user: hub-level checklist only (public member routes).
        // Dashboard / Power Admin flags in an OR list must not open public access
        // when their checklist defaults are on (e.g. Central library on Central).
        if (! $user) {
            foreach ($capabilities as $capability) {
                if (str_starts_with($capability, 'dashboard_')
                    || str_starts_with($capability, 'pa_')
                    || str_starts_with($capability, 'smc_')
                    || str_starts_with($capability, 'gc_')
                    || str_starts_with($capability, 'wc_')
                ) {
                    continue;
                }
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
            // When controlling a white-labelled hub, hub-scoped caps follow that hub's matrix.
            $hubForCap = $this->actingHubs->capabilityHub($user, $capability);

            // Central library must be evaluated on a fresh control-plane hub row
            // (matrix saves can leave request caches / stale models behind).
            if ($capability === 'dashboard_central_content_library') {
                $this->matrix->forgetResolvedCaches();
                $this->hubs->forgetCurrentCache();
                $hubForCap = $this->hubs->current();
                if (! $hubForCap->isControlPlane()) {
                    continue;
                }
            }

            if ($this->controlPlaneRemoteWebsiteComplianceAllows($user, $hubForCap, $capability)) {
                return $next($request);
            }

            if ($this->matrix->userCan($hubForCap, $user, $capability)) {
                return $next($request);
            }
        }

        $failedHub = $this->actingHubs->capabilityHub($user, $capabilities[0]);
        $effectiveRole = $this->actingAdvisors->effectiveCapabilityRole($user);
        $message = 'This capability is disabled for your role on this hub.';
        if ($capabilities[0] === 'dashboard_central_content_library' && ! $this->hubs->current()->isControlPlane()) {
            $message = 'Central content library is only available on the Central Hub Controller.';
        }

        return response()->json([
            'message' => $message,
            'capability' => $capabilities[0],
            'capabilities' => $capabilities,
            'role' => $user->role,
            'effective_role' => $effectiveRole,
            'hub_id' => $failedHub->id,
            'hub_slug' => $failedHub->slug,
        ], 403);
    }

    /**
     * Power Admin / FinProms Admin are control-plane only. While the switcher is on a
     * remote content hub with Website Compliance enabled, allow WC route caps remotely.
     */
    private function controlPlaneRemoteWebsiteComplianceAllows(User $user, Hub $hubForCap, string $capability): bool
    {
        if (! ActingHubService::isControlPlaneRole((string) $user->role)) {
            return false;
        }

        if (! $this->actingHubs->isActingRemotely($user)) {
            return false;
        }

        if (! Hub::isWebsiteComplianceCapability($capability)
            && ! Hub::isWebsiteTemplateLibraryCapability($capability)
            && $capability !== 'module_website_compliance'
            && $capability !== 'module_website_template_library'
        ) {
            return false;
        }

        if (Hub::isWebsiteTemplateLibraryCapability($capability)
            || $capability === 'module_website_template_library'
        ) {
            return $hubForCap->hasWebsiteTemplateLibraryModule();
        }

        return $hubForCap->hasWebsiteComplianceModule();
    }

    /**
     * Resolve the viewer for public routes that still send a Bearer token
     * (e.g. GET /types from the dashboard) without auth:sanctum middleware.
     */
    private function resolveUser(Request $request): ?User
    {
        $user = $request->user() ?? $request->user('sanctum');
        if ($user instanceof User) {
            return $user;
        }

        if (! $request->bearerToken()) {
            return null;
        }

        $user = Auth::guard('sanctum')->user();

        return $user instanceof User ? $user : null;
    }
}
