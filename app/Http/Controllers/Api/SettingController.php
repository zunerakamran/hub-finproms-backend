<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Hub;
use App\Models\Setting;
use App\Services\ActingHubService;
use App\Services\HubService;
use App\Services\WhiteLabelHubSyncService;
use App\Support\DashboardNavDefaults;
use App\Support\PageContentDefaults;
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
            'white_logo' => ['sometimes', 'file', 'image', 'max:5120'],
            'remove_white_logo' => ['sometimes', 'boolean'],
            // Favicons often use .ico; Laravel's "image" rule rejects that, so use mimes.
            'favicon' => ['sometimes', 'file', 'mimes:ico,png,jpg,jpeg,gif,webp,svg', 'max:1024'],
            'remove_favicon' => ['sometimes', 'boolean'],
            'auth_bg_image' => ['sometimes', 'file', 'image', 'max:8192'],
            'remove_auth_bg_image' => ['sometimes', 'boolean'],
            'color_scheme' => ['sometimes', 'array'],
            'color_scheme.primary' => ['nullable', 'string', 'max:32'],
            'color_scheme.secondary' => ['nullable', 'string', 'max:32'],
            'color_scheme.accent' => ['nullable', 'string', 'max:32'],
            'primary_color' => ['nullable', 'string', 'max:32'],
            'secondary_color' => ['nullable', 'string', 'max:32'],
            'accent_color' => ['nullable', 'string', 'max:32'],
            'page_content' => ['sometimes'],
            'dashboard_nav' => ['sometimes'],
            'footer_powered_by_logo' => ['sometimes', 'file', 'image', 'max:5120'],
            'remove_footer_powered_by_logo' => ['sometimes', 'boolean'],
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

        $removeWhiteLogo = filter_var($request->input('remove_white_logo'), FILTER_VALIDATE_BOOLEAN);
        if ($removeWhiteLogo && ! $request->hasFile('white_logo')) {
            $this->deleteStoredAsset($hub->white_logo_url, 'hubs/white-logos/');
            $hub->white_logo_url = null;
            $hubDirty = true;
        }

        if ($request->hasFile('white_logo')) {
            $this->deleteStoredAsset($hub->white_logo_url, 'hubs/white-logos/');
            $path = $request->file('white_logo')->store('hubs/white-logos', 'public');
            $hub->white_logo_url = $path;
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

        $removeAuthBg = filter_var($request->input('remove_auth_bg_image'), FILTER_VALIDATE_BOOLEAN);
        if ($removeAuthBg && ! $request->hasFile('auth_bg_image')) {
            $this->deleteStoredAsset($hub->auth_bg_image_url, 'hubs/auth-bg/');
            $hub->auth_bg_image_url = null;
            $hubDirty = true;
        }

        if ($request->hasFile('auth_bg_image')) {
            $this->deleteStoredAsset($hub->auth_bg_image_url, 'hubs/auth-bg/');
            $path = $request->file('auth_bg_image')->store('hubs/auth-bg', 'public');
            $hub->auth_bg_image_url = $path;
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
            if (array_key_exists('accent', $validated['color_scheme'])) {
                $hub->accent_color = $validated['color_scheme']['accent'];
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

        if (array_key_exists('accent_color', $validated)) {
            $hub->accent_color = $validated['accent_color'];
            $hubDirty = true;
        }

        $previousPoweredByLogo = is_array($hub->page_content)
            ? ($hub->page_content['home']['footer_powered_by_logo'] ?? null)
            : null;
        $removePoweredByLogo = filter_var(
            $request->input('remove_footer_powered_by_logo'),
            FILTER_VALIDATE_BOOLEAN
        );

        if ($request->exists('page_content')) {
            $raw = $request->input('page_content');
            if (is_string($raw)) {
                $decoded = json_decode($raw, true);
                $raw = is_array($decoded) ? $decoded : [];
            }
            if (! is_array($raw)) {
                $raw = [];
            }

            $incoming = PageContentDefaults::sanitize($raw);
            $defaults = PageContentDefaults::all();
            $merged = [];

            foreach ($defaults as $section => $fields) {
                $sectionIncoming = $incoming[$section] ?? [];
                foreach (array_keys($fields) as $key) {
                    if (! array_key_exists($key, $sectionIncoming)) {
                        continue;
                    }
                    $value = $sectionIncoming[$key];
                    // Empty or equal to default → no override stored.
                    if ($value === '' || $value === $fields[$key]) {
                        continue;
                    }
                    $merged[$section][$key] = $value;
                }
            }

            // Keep the storage path when the UI echoes back the resolved public URL.
            if (
                ! $request->hasFile('footer_powered_by_logo')
                && ! $removePoweredByLogo
                && is_string($previousPoweredByLogo)
                && str_starts_with($previousPoweredByLogo, 'hubs/powered-by-logos/')
            ) {
                $incomingLogo = trim((string) ($incoming['home']['footer_powered_by_logo'] ?? ''));
                $previousPublic = Storage::disk('public')->url($previousPoweredByLogo);
                $previousAbsolute = rtrim((string) config('app.url'), '/').'/'.ltrim($previousPublic, '/');
                if (
                    $incomingLogo === ''
                    || $incomingLogo === $previousPoweredByLogo
                    || $incomingLogo === $previousPublic
                    || $incomingLogo === $previousAbsolute
                ) {
                    $merged['home']['footer_powered_by_logo'] = $previousPoweredByLogo;
                }
            }

            $hub->page_content = $merged === [] ? null : $merged;
            $hubDirty = true;
        }

        if ($removePoweredByLogo && ! $request->hasFile('footer_powered_by_logo')) {
            $this->deleteStoredAsset(
                is_string($previousPoweredByLogo) ? $previousPoweredByLogo : null,
                'hubs/powered-by-logos/'
            );
            $pageContent = is_array($hub->page_content) ? $hub->page_content : [];
            unset($pageContent['home']['footer_powered_by_logo']);
            if (($pageContent['home'] ?? null) === []) {
                unset($pageContent['home']);
            }
            $hub->page_content = $pageContent === [] ? null : $pageContent;
            $hubDirty = true;
        }

        if ($request->hasFile('footer_powered_by_logo')) {
            $pageContent = is_array($hub->page_content) ? $hub->page_content : [];
            $old = $pageContent['home']['footer_powered_by_logo'] ?? $previousPoweredByLogo;
            $this->deleteStoredAsset(is_string($old) ? $old : null, 'hubs/powered-by-logos/');
            $path = $request->file('footer_powered_by_logo')->store('hubs/powered-by-logos', 'public');
            $pageContent['home']['footer_powered_by_logo'] = $path;
            $hub->page_content = $pageContent;
            $hubDirty = true;
        }

        if ($request->exists('dashboard_nav')) {
            $raw = $request->input('dashboard_nav');
            if (is_string($raw)) {
                $decoded = json_decode($raw, true);
                $raw = is_array($decoded) ? $decoded : [];
            }
            if (! is_array($raw)) {
                $raw = [];
            }

            $incoming = DashboardNavDefaults::sanitize($raw);
            $defaults = DashboardNavDefaults::all();
            $merged = [];

            foreach (['sections', 'items'] as $bucket) {
                $bucketIncoming = $incoming[$bucket] ?? [];
                foreach (array_keys($defaults[$bucket]) as $key) {
                    if (! array_key_exists($key, $bucketIncoming)) {
                        continue;
                    }
                    $value = $bucketIncoming[$key];
                    if ($value === '' || $value === $defaults[$bucket][$key]) {
                        continue;
                    }
                    $merged[$bucket][$key] = $value;
                }
            }

            // Always persist custom separator labels.
            foreach (($incoming['sections'] ?? []) as $key => $value) {
                if (! DashboardNavDefaults::isCustomSectionId((string) $key)) {
                    continue;
                }
                $label = trim((string) $value);
                $merged['sections'][$key] = $label !== '' ? $label : 'Custom section';
            }

            $customIds = DashboardNavDefaults::extractCustomSectionIds(
                $incoming['sections'] ?? null,
                $incoming['section_order'] ?? null
            );
            if (($incoming['section_order'] ?? []) !== []
                && (($incoming['section_order'] ?? null) !== ($defaults['section_order'] ?? null) || $customIds !== [])) {
                $merged['section_order'] = $incoming['section_order'];
            }

            if (($incoming['item_groups'] ?? []) !== []) {
                $groupDiff = [];
                foreach ($defaults['item_groups'] as $path => $defaultGroup) {
                    $value = $incoming['item_groups'][$path] ?? null;
                    if ($value !== null && $value !== '' && $value !== $defaultGroup) {
                        $groupDiff[$path] = $value;
                    }
                }
                if ($groupDiff !== []) {
                    $merged['item_groups'] = $groupDiff;
                }
            }

            if (($incoming['item_order'] ?? []) !== []
                && ($incoming['item_order'] ?? null) !== ($defaults['item_order'] ?? null)) {
                $merged['item_order'] = $incoming['item_order'];
            }

            $hub->dashboard_nav = $merged === [] ? null : $merged;
            $hubDirty = true;
        }

        if ($hubDirty) {
            $hub->save();
            $this->hubs->forgetCurrentCache();
            try {
                if ($hub->isContentHub() && $hub->hasRemoteDatabaseConfigured()) {
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
            'white_logo_url' => $hub->whiteLogoPublicUrl(),
            'favicon_url' => $hub->faviconPublicUrl(),
            'auth_bg_image_url' => $hub->authBgImagePublicUrl(),
            'color_scheme' => [
                'primary' => $hub->primary_color,
                'secondary' => $hub->secondary_color,
                'accent' => $hub->accent_color,
            ],
            // Resolved (defaults + overrides) so the settings form shows effective copy.
            'page_content' => $hub->resolvedPageContent(),
            'dashboard_nav' => $hub->resolvedDashboardNav(),
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
