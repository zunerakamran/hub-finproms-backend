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
            $hub = $this->applyPageContentPayload(
                $hub,
                $request->input('page_content'),
                $previousPoweredByLogo,
                $request->hasFile('footer_powered_by_logo'),
                $removePoweredByLogo
            );
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
            try {
                $hub = $this->saveAndSync($hub);
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

    /**
     * Hubs whose website content can be copied onto the current (acting) hub.
     */
    public function importSources(Request $request): JsonResponse
    {
        $target = $this->targetHub($request);

        $sources = Hub::query()
            ->where('is_active', true)
            ->where('id', '!=', $target->id)
            ->orderByRaw('CASE WHEN type = ? THEN 0 WHEN type = ? THEN 1 ELSE 2 END', [
                Hub::TYPE_WHITE_LABEL,
                Hub::TYPE_SHARED,
            ])
            ->orderBy('name')
            ->get()
            ->map(function (Hub $hub) {
                $custom = is_array($hub->page_content) && $hub->page_content !== [];
                $typeLabel = match ($hub->type) {
                    Hub::TYPE_CENTRAL => 'central',
                    Hub::TYPE_SHARED => 'shared',
                    default => 'white-label',
                };

                return [
                    'id' => $hub->id,
                    'name' => $hub->name,
                    'slug' => $hub->slug,
                    'type' => $hub->type,
                    'has_custom_content' => $custom,
                    'label' => $hub->name.' ('.$typeLabel.')'.($custom ? '' : ' — defaults only'),
                ];
            })
            ->values()
            ->all();

        return response()->json([
            'hub' => $this->hubPayload($target),
            'sources' => $sources,
        ]);
    }

    /**
     * Copy another hub’s website page content onto the current (acting) hub.
     */
    public function import(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'source_hub_id' => ['required', 'integer', 'exists:hubs,id'],
        ]);

        $target = $this->targetHub($request);
        $source = Hub::query()->find((int) $validated['source_hub_id']);

        if (! $source || ! $source->is_active) {
            return response()->json(['message' => 'Source hub not found or inactive.'], 404);
        }

        if ((int) $source->id === (int) $target->id) {
            return response()->json([
                'message' => 'Choose a different hub to import from.',
            ], 422);
        }

        $payload = $source->resolvedPageContent();
        // Prefer the source’s stored relative logo path so both hubs share the same file on Central.
        $storedLogo = is_array($source->page_content)
            ? ($source->page_content['home']['footer_powered_by_logo'] ?? null)
            : null;
        if (is_string($storedLogo) && str_starts_with($storedLogo, 'hubs/powered-by-logos/')) {
            $payload['home']['footer_powered_by_logo'] = $storedLogo;
        }

        try {
            $target = $this->applyPageContentPayload($target, $payload, null, false, false);
            $target = $this->saveAndSync($target);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Website content imported from '.$source->name.'.',
            'hub' => $this->hubPayload($target),
            'source_hub' => $this->hubPayload($source),
            'page_content' => $target->resolvedPageContent(),
        ]);
    }

    /**
     * @param  array<string, mixed>|string|null  $raw
     */
    private function applyPageContentPayload(
        Hub $hub,
        mixed $raw,
        mixed $previousPoweredByLogo,
        bool $hasNewLogoFile,
        bool $removePoweredByLogo
    ): Hub {
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
                if ($value === '' || $value === $fields[$key]) {
                    continue;
                }
                $merged[$section][$key] = $value;
            }
        }

        if (
            ! $hasNewLogoFile
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

        // When importing, keep an explicit relative logo path from the source payload.
        $incomingLogoPath = trim((string) ($incoming['home']['footer_powered_by_logo'] ?? ''));
        if (
            ! $hasNewLogoFile
            && ! $removePoweredByLogo
            && str_starts_with($incomingLogoPath, 'hubs/powered-by-logos/')
        ) {
            $merged['home']['footer_powered_by_logo'] = $incomingLogoPath;
        }

        $hub->page_content = $merged === [] ? null : $merged;

        return $hub;
    }

    private function saveAndSync(Hub $hub): Hub
    {
        $hub->save();
        $this->hubs->forgetCurrentCache();

        if ($hub->isContentHub() && $hub->hasRemoteDatabaseConfigured()) {
            $this->whiteLabelSync->pushSettings($hub->fresh());
        }

        return $hub->fresh() ?? $hub;
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
