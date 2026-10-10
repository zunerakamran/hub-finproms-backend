<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Hub;
use App\Services\ActingHubService;
use App\Services\HubService;
use App\Services\WhiteLabelHubSyncService;
use App\Support\DashboardNavDefaults;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Dashboard sidebar separators + menu labels — Settings → Dashboard menu.
 */
class DashboardNavController extends Controller
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
            'dashboard_nav' => $hub->resolvedDashboardNav(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $request->validate([
            'dashboard_nav' => ['required'],
        ]);

        $hub = $this->targetHub($request);

        try {
            $hub = $this->persistDashboardNav($hub, $request->input('dashboard_nav'));
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Dashboard menu updated successfully.',
            'hub' => $this->hubPayload($hub),
            'dashboard_nav' => $hub->resolvedDashboardNav(),
        ]);
    }

    /**
     * Hubs whose dashboard menu can be copied onto the current (acting) hub.
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
                $custom = is_array($hub->dashboard_nav) && $hub->dashboard_nav !== [];
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
                    'has_custom_nav' => $custom,
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
     * Copy another hub’s dashboard menu onto the current (acting) hub.
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

        // Use the source’s effective menu so remaps / new default paths are included.
        try {
            $target = $this->persistDashboardNav($target, $source->resolvedDashboardNav());
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Dashboard menu imported from '.$source->name.'.',
            'hub' => $this->hubPayload($target),
            'source_hub' => $this->hubPayload($source),
            'dashboard_nav' => $target->resolvedDashboardNav(),
        ]);
    }

    /**
     * @param  array<string, mixed>|string|null  $raw
     */
    private function persistDashboardNav(Hub $hub, mixed $raw): Hub
    {
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
}
