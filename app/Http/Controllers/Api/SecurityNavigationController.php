<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SecurityEvent;
use App\Services\HubService;
use App\Services\SecurityMonitoringService;
use App\Support\SafeFrontendRedirect;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SecurityNavigationController extends Controller
{
    public function __construct(
        private HubService $hubs,
        private SecurityMonitoringService $securityMonitoring,
    ) {}

    /**
     * Optional beacon when the SPA blocks an external / pasted / email entry.
     * Never fails loudly — monitoring must not break navigation UX.
     */
    public function reportBlocked(Request $request): JsonResponse
    {
        $data = $request->validate([
            'path' => ['nullable', 'string', 'max:500'],
            'referrer' => ['nullable', 'string', 'max:1000'],
        ]);

        if (! config('security_navigation.enabled', true)
            || ! config('security_navigation.record_security_events', true)) {
            return response()->json(['recorded' => false]);
        }

        try {
            $hub = $this->hubs->current();
            if (! $hub || ! $hub->hasSecurityMonitoringFunctionality()) {
                return response()->json(['recorded' => false]);
            }

            $path = SafeFrontendRedirect::sanitize($data['path'] ?? '/', $hub, '/');
            $referrer = mb_substr((string) ($data['referrer'] ?? ''), 0, 500);

            $actor = $request->user()
                ?? Auth::guard('sanctum')->user()
                ?? Auth::guard('web')->user();

            $this->securityMonitoring->recordEvent($hub, [
                'category' => SecurityEvent::CATEGORY_AUTHORIZATION,
                'event_type' => 'nav.external_entry_blocked',
                'severity' => SecurityEvent::SEVERITY_INFO,
                'title' => 'External navigation blocked',
                'description' => 'Blocked opening a protected page via external link, email, chat, paste, or redirect from another application. Path: '.$path
                    .($referrer !== '' ? ' Referrer: '.$referrer : ' Referrer: (none)'),
                'actor' => $actor,
                'request' => $request,
                'route' => $path,
                'properties' => [
                    'path' => $path,
                    'referrer' => $referrer !== '' ? $referrer : null,
                ],
            ]);

            return response()->json(['recorded' => true]);
        } catch (\Throwable) {
            return response()->json(['recorded' => false]);
        }
    }
}
