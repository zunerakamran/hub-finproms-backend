<?php

namespace App\Support;

use App\Services\HubService;
use Illuminate\Http\JsonResponse;

/**
 * 403 payload for firewall / Access Control denials.
 * Includes hub branding so the SPA can show logo + loader without calling /hub.
 */
class AccessDeniedResponse
{
    public static function json(string $message, string $code): JsonResponse
    {
        $branding = null;
        try {
            $hub = app(HubService::class)->current();
            if ($hub) {
                $branding = $hub->brandingPayload();
            }
        } catch (\Throwable) {
            $branding = null;
        }

        return response()->json([
            'message' => $message,
            'code' => $code,
            'branding' => $branding,
        ], 403);
    }
}
