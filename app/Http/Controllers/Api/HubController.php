<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ActingHubService;
use App\Services\CapabilitiesMatrixService;
use App\Services\HubService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class HubController extends Controller
{
    public function __construct(
        private readonly HubService $hubs,
        private readonly CapabilitiesMatrixService $matrix,
        private readonly ActingHubService $actingHubs
    ) {}

    /**
     * Public: current hub branding + checklist (for frontend feature gates).
     * When authenticated, also returns effective_capabilities for that user's role.
     * On shared hub with Control white labelled hubs, includes hub_switcher.
     */
    public function current(Request $request): JsonResponse
    {
        $hub = $this->hubs->current();
        $payload = $hub->toPublicArray();

        // Optional Sanctum auth on this public route (Bearer token).
        $user = $request->user('sanctum');
        if (! $user) {
            $bearer = $request->bearerToken();
            if ($bearer) {
                $user = Auth::guard('sanctum')->user();
            }
        }

        if ($user) {
            $switcher = $this->actingHubs->switcherPayload($user);
            if ($switcher !== null) {
                $payload['hub_switcher'] = $switcher;
                // Dashboard menus should follow the acting hub's effective caps.
                $payload['effective_capabilities'] = $switcher['effective_capabilities'];
                $acting = $this->actingHubs->actingHub($user);
                $payload['acting_hub'] = $switcher['acting_hub'];
                // Expose acting hub checklist so UI can mirror selected hub behaviour flags.
                $payload['acting_checklist'] = $acting->resolvedChecklist();
            } else {
                $effective = [];
                foreach (array_keys($payload['checklist']) as $flag) {
                    $effective[$flag] = $this->matrix->roleCan($hub, (string) $user->role, $flag);
                }
                if ($user->isPowerAdmin()) {
                    foreach (app(\App\Services\PowerAdminCapabilitiesService::class)->resolved() as $key => $enabled) {
                        $effective[$key] = $enabled;
                    }
                }
                $payload['effective_capabilities'] = $effective;
            }
            $payload['viewer_role'] = $user->role;
        }

        return response()->json([
            'hub' => $payload,
        ]);
    }
}
