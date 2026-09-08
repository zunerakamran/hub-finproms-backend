<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    public function publicIndex(): JsonResponse
    {
        return response()->json([
            'settings' => [
                'new_banner_days' => Setting::newBannerDays(),
            ],
        ]);
    }

    public function index(): JsonResponse
    {
        return response()->json([
            'settings' => [
                'new_banner_days' => Setting::newBannerDays(),
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'new_banner_days' => ['required', 'integer', 'min:0', 'max:365'],
        ]);

        Setting::setValue(Setting::KEY_NEW_BANNER_DAYS, $validated['new_banner_days']);

        return response()->json([
            'message' => 'Settings updated successfully.',
            'settings' => [
                'new_banner_days' => Setting::newBannerDays(),
            ],
        ]);
    }
}
