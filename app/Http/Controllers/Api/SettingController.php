<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\HubService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SettingController extends Controller
{
    public function __construct(
        private readonly HubService $hubs
    ) {}

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
            'settings' => $this->settingsPayload(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'new_banner_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'application_name' => ['sometimes', 'string', 'max:255'],
            'from_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'logo' => ['sometimes', 'file', 'image', 'max:5120'],
            'remove_logo' => ['sometimes', 'boolean'],
            'color_scheme' => ['sometimes', 'array'],
            'color_scheme.primary' => ['nullable', 'string', 'max:32'],
            'color_scheme.secondary' => ['nullable', 'string', 'max:32'],
            'primary_color' => ['nullable', 'string', 'max:32'],
            'secondary_color' => ['nullable', 'string', 'max:32'],
        ]);

        if (array_key_exists('new_banner_days', $validated)) {
            Setting::setValue(Setting::KEY_NEW_BANNER_DAYS, $validated['new_banner_days']);
        }

        $hub = $this->hubs->current();
        $hubDirty = false;

        if (array_key_exists('application_name', $validated)) {
            $hub->name = $validated['application_name'];
            $hubDirty = true;
        }

        if (array_key_exists('from_email', $validated)) {
            $hub->from_email = $validated['from_email'] ?: null;
            $hubDirty = true;
        }

        $removeLogo = filter_var($request->input('remove_logo'), FILTER_VALIDATE_BOOLEAN);
        if ($removeLogo && ! $request->hasFile('logo')) {
            $this->deleteStoredLogo($hub->logo_url);
            $hub->logo_url = null;
            $hubDirty = true;
        }

        if ($request->hasFile('logo')) {
            $this->deleteStoredLogo($hub->logo_url);
            $path = $request->file('logo')->store('hubs/logos', 'public');
            $hub->logo_url = $path;
            $hubDirty = true;
        }

        if (isset($validated['color_scheme']) && is_array($validated['color_scheme'])) {
            if (array_key_exists('primary', $validated['color_scheme'])) {
                $hub->primary_color = $validated['color_scheme']['primary'];
                $hubDirty = true;
            }
            if (array_key_exists('secondary', $validated['color_scheme'])) {
                $hub->secondary_color = $validated['color_scheme']['secondary'];
                $hubDirty = true;
            }
        }

        if (array_key_exists('primary_color', $validated)) {
            $hub->primary_color = $validated['primary_color'];
            $hubDirty = true;
        }

        if (array_key_exists('secondary_color', $validated)) {
            $hub->secondary_color = $validated['secondary_color'];
            $hubDirty = true;
        }

        if ($hubDirty) {
            $hub->save();
            $this->hubs->forgetCurrentCache();
        }

        return response()->json([
            'message' => 'Settings updated successfully.',
            'settings' => $this->settingsPayload(),
            'hub' => $this->hubs->current()->toPublicArray(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function settingsPayload(): array
    {
        $hub = $this->hubs->current();

        return [
            'new_banner_days' => Setting::newBannerDays(),
            'application_name' => $hub->name,
            'from_email' => $hub->from_email,
            'logo_url' => $hub->logoPublicUrl(),
            'color_scheme' => [
                'primary' => $hub->primary_color,
                'secondary' => $hub->secondary_color,
            ],
        ];
    }

    private function deleteStoredLogo(?string $logo): void
    {
        if (! $logo) {
            return;
        }

        // Only delete files we stored under hubs/logos (not external URLs).
        if (str_starts_with($logo, 'hubs/logos/') && Storage::disk('public')->exists($logo)) {
            Storage::disk('public')->delete($logo);
        }
    }
}
