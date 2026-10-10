<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Hub;
use App\Services\ActingHubService;
use App\Services\HubService;
use App\Services\WhiteLabelHubSyncService;
use App\Support\PageContentDefaults;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Public website page copy (Home / catalog / post detail) — Settings → Website content.
 */
class PageContentController extends Controller
{
    public function __construct(
        private readonly HubService $hubs,
        private readonly ActingHubService $actingHubs,
        private readonly WhiteLabelHubSyncService $whiteLabelSync
    ) {}

    public function show(Request $request): JsonResponse
    {
        $hub = $this->targetHub($request);

        return response()->json([
            'hub' => $this->hubPayload($hub),
            'page_content' => $hub->resolvedPageContent(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $request->validate([
            'page_content' => ['sometimes'],
            'footer_powered_by_logo' => ['sometimes', 'file', 'image', 'max:5120'],
            'remove_footer_powered_by_logo' => ['sometimes', 'boolean'],
        ]);

        $hub = $this->targetHub($request);
        $hubDirty = false;

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
                $previousMedia = $hub->absoluteStoredMediaUrl($previousPoweredByLogo);
                $previousAbsolute = str_starts_with($previousPublic, 'http://') || str_starts_with($previousPublic, 'https://')
                    ? $previousPublic
                    : rtrim((string) config('app.url'), '/').'/'.ltrim($previousPublic, '/');
                $echoesStored = $incomingLogo === ''
                    || $incomingLogo === $previousPoweredByLogo
                    || $incomingLogo === $previousPublic
                    || $incomingLogo === $previousAbsolute
                    || $incomingLogo === $previousMedia
                    || str_ends_with($incomingLogo, '/'.$previousPoweredByLogo)
                    || str_ends_with($incomingLogo, $previousPoweredByLogo);
                if ($echoesStored) {
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

        $hub = $this->targetHub($request)->fresh() ?? $this->targetHub($request);

        return response()->json([
            'message' => 'Website content updated successfully.',
            'hub' => $this->hubPayload($hub),
            'page_content' => $hub->resolvedPageContent(),
        ]);
    }

    private function targetHub(Request $request): Hub
    {
        return $this->actingHubs->targetHub($request->user());
    }

    /**
     * @return array{id: int, name: string, slug: string, type: string}
     */
    private function hubPayload(Hub $hub): array
    {
        return [
            'id' => $hub->id,
            'name' => $hub->name,
            'slug' => $hub->slug,
            'type' => $hub->type,
        ];
    }

    private function deleteStoredAsset(?string $path, string $prefix): void
    {
        if (! $path) {
            return;
        }

        if (str_starts_with($path, $prefix) && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }
}
