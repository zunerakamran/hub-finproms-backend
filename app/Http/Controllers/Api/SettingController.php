<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Hub;
use App\Models\Setting;
use App\Services\ActingHubService;
use App\Services\HubService;
use App\Services\WhiteLabelHubSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class SettingController extends Controller
{
    public function __construct(
        private readonly HubService $hubs,
        private readonly ActingHubService $actingHubs,
        private readonly WhiteLabelHubSyncService $whiteLabelSync
    ) {}

    public function publicIndex(): JsonResponse
    {
        return response()->json([
            'settings' => [
                'new_banner_days' => Setting::newBannerDays(),
            ],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'settings' => $this->settingsPayload($request),
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
            // Favicons often use .ico; Laravel's "image" rule rejects that, so use mimes.
            'favicon' => ['sometimes', 'file', 'mimes:ico,png,jpg,jpeg,gif,webp,svg', 'max:1024'],
            'remove_favicon' => ['sometimes', 'boolean'],
            'color_scheme' => ['sometimes', 'array'],
            'color_scheme.primary' => ['nullable', 'string', 'max:32'],
            'color_scheme.secondary' => ['nullable', 'string', 'max:32'],
            'primary_color' => ['nullable', 'string', 'max:32'],
            'secondary_color' => ['nullable', 'string', 'max:32'],
        ]);

        if (array_key_exists('new_banner_days', $validated)) {
            Setting::setValue(Setting::KEY_NEW_BANNER_DAYS, $validated['new_banner_days']);
        }

        $hub = $this->targetHub($request);
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
            $this->deleteStoredAsset($hub->logo_url, 'hubs/logos/');
            $hub->logo_url = null;
            $hubDirty = true;
        }

        if ($request->hasFile('logo')) {
            $this->deleteStoredAsset($hub->logo_url, 'hubs/logos/');
            $path = $request->file('logo')->store('hubs/logos', 'public');
            $hub->logo_url = $path;
            $hubDirty = true;
        }

        $removeFavicon = filter_var($request->input('remove_favicon'), FILTER_VALIDATE_BOOLEAN);
        if ($removeFavicon && ! $request->hasFile('favicon')) {
            $this->deleteStoredAsset($hub->favicon_url, 'hubs/favicons/');
            $hub->favicon_url = null;
            $hubDirty = true;
        }

        if ($request->hasFile('favicon')) {
            $this->deleteStoredAsset($hub->favicon_url, 'hubs/favicons/');
            $path = $request->file('favicon')->store('hubs/favicons', 'public');
            $hub->favicon_url = $path;
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
            try {
                if ($hub->isWhiteLabel() && $hub->hasRemoteDatabaseConfigured()) {
                    $this->whiteLabelSync->pushSettings($hub->fresh());
                }
            } catch (InvalidArgumentException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
        }

        return response()->json([
            'message' => 'Settings updated successfully.',
            'settings' => $this->settingsPayload($request),
            'hub' => $this->targetHub($request)->toPublicArray(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function settingsPayload(?Request $request = null): array
    {
        $hub = $request ? $this->targetHub($request) : $this->hubs->current();

        return [
            'new_banner_days' => Setting::newBannerDays(),
            'application_name' => $hub->name,
            'from_email' => $hub->from_email,
            'logo_url' => $hub->logoPublicUrl(),
            'favicon_url' => $hub->faviconPublicUrl(),
            'color_scheme' => [
                'primary' => $hub->primary_color,
                'secondary' => $hub->secondary_color,
            ],
        ];
    }

    /**
     * Only delete files we stored under the given prefix (not external URLs).
     */
    private function deleteStoredAsset(?string $path, string $prefix): void
    {
        if (! $path) {
            return;
        }

        if (str_starts_with($path, $prefix) && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }

    private function targetHub(Request $request): Hub
    {
        return $this->actingHubs->targetHub($request->user());
    }
}
