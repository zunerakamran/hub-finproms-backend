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
        $hub->save();
        $this->hubs->forgetCurrentCache();

        try {
            if ($hub->isContentHub() && $hub->hasRemoteDatabaseConfigured()) {
                $this->whiteLabelSync->pushSettings($hub->fresh());
            }
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $hub = $hub->fresh() ?? $hub;

        return response()->json([
            'message' => 'Dashboard menu updated successfully.',
            'hub' => $this->hubPayload($hub),
            'dashboard_nav' => $hub->resolvedDashboardNav(),
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
}
