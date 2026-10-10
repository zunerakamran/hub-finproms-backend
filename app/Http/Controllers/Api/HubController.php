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
            $switcher = null;
            try {
                $switcher = $this->actingHubs->switcherPayload($user);
            } catch (\Throwable) {
                $switcher = null;
            }
            if ($switcher !== null) {
                $payload['hub_switcher'] = $switcher;
                // Dashboard menus should follow the acting hub's effective caps
                // and that hub's Settings → dashboard menu labels / separators.
                $payload['effective_capabilities'] = $switcher['effective_capabilities'];
                $payload['acting_hub'] = $switcher['acting_hub'];
                $payload['role_labels'] = $switcher['role_labels'] ?? $payload['role_labels'];
                $payload['compliance_status_labels'] = $switcher['compliance_status_labels']
                    ?? $payload['compliance_status_labels'];
                $payload['dashboard_nav'] = $switcher['dashboard_nav'] ?? $payload['dashboard_nav'];
                try {
                    $acting = $this->actingHubs->actingHub($user);
                    $payload['acting_checklist'] = $acting->resolvedChecklist();
                    $payload['dashboard_nav'] = $acting->resolvedDashboardNav();
                    $payload['firm_document_rights'] = app(\App\Services\FirmDocumentAccessService::class)
                        ->effectiveRightsSummary($user, $acting);
                } catch (\Throwable) {
                    $payload['acting_checklist'] = $payload['checklist'] ?? [];
                    try {
                        $payload['firm_document_rights'] = app(\App\Services\FirmDocumentAccessService::class)
                            ->effectiveRightsSummary($user, $this->hubs->current());
                    } catch (\Throwable) {
                        // leave unset
                    }
                }
            } else {
                $effective = [];
                $checklist = is_array($payload['checklist'] ?? null) ? $payload['checklist'] : [];
                foreach (array_keys($checklist) as $flag) {
                    try {
                        $effective[$flag] = $this->matrix->userCan($hub, $user, $flag);
                    } catch (\Throwable) {
                        $effective[$flag] = false;
                    }
                }
                if ($user->isPowerAdmin()) {
                    try {
                        foreach (app(\App\Services\PowerAdminCapabilitiesService::class)->resolved() as $key => $enabled) {
                            $effective[$key] = $enabled;
                        }
                    } catch (\Throwable) {
                        // keep checklist-derived caps
                    }
                }
                $payload['effective_capabilities'] = $this->actingHubs
                    ->mergeFirmDocumentEffectiveCapabilities($user, $effective, $hub);
                try {
                    $payload['firm_document_rights'] = app(\App\Services\FirmDocumentAccessService::class)
                        ->effectiveRightsSummary($user, $hub);
                } catch (\Throwable) {
                    // Hub boot must succeed even if firm-document rights fail.
                }
            }
            $payload['viewer_role'] = $user->role;
            try {
                $payload['effective_role'] = $this->matrix->effectiveRoleFor($user);
            } catch (\Throwable) {
                $payload['effective_role'] = $user->role;
            }

            try {
                $advisorSwitcher = app(\App\Services\ActingAdvisorService::class)->switcherPayload($user);
            } catch (\Throwable) {
                $advisorSwitcher = null;
            }
            if ($advisorSwitcher !== null) {
                $payload['acting_advisor_switcher'] = $advisorSwitcher;
            }
        }

        return response()->json([
            'hub' => $payload,
        ]);
    }
}
