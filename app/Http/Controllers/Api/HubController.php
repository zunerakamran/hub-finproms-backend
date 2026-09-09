<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CapabilitiesMatrixService;
use App\Services\HubService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class HubController extends Controller
{
    public function __construct(
        private readonly HubService $hubs,
        private readonly CapabilitiesMatrixService $matrix
    ) {}

    /**
     * Public: current hub branding + checklist (for frontend feature gates).
     * When authenticated, also returns effective_capabilities for that user's role.
     */
    public function current(Request $request): JsonResponse
    {
        $hub = $this->hubs->current();
        $payload = $hub->toPublicArray();

        $user = $request->user('sanctum') ?? Auth::guard('sanctum')->user();
        if ($user) {
            $effective = [];
            foreach (array_keys($payload['checklist']) as $flag) {
                $effective[$flag] = $this->matrix->roleCan($hub, (string) $user->role, $flag);
            }
            // Also include PA caps for power admins in effective set
            if ($user->isPowerAdmin()) {
                foreach (app(\App\Services\PowerAdminCapabilitiesService::class)->resolved() as $key => $enabled) {
                    $effective[$key] = $enabled;
                }
            }
            $payload['effective_capabilities'] = $effective;
            $payload['viewer_role'] = $user->role;
        }

        return response()->json([
            'hub' => $payload,
        ]);
    }
}
