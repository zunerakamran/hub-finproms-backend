<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Hub;
use App\Services\ActingHubService;
use App\Services\ActivityLogService;
use App\Services\CapabilitiesMatrixService;
use App\Services\HubService;
use App\Services\WhiteLabelHubSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class HubModulesController extends Controller
{
    public const CAPABILITY = 'dashboard_manage_modules';

    public function __construct(
        private readonly HubService $hubs,
        private readonly CapabilitiesMatrixService $matrix,
        private readonly ActivityLogService $activityLogs,
        private readonly WhiteLabelHubSyncService $whiteLabelSync,
        private readonly ActingHubService $actingHubs
    ) {}

    public function show(Request $request): JsonResponse
    {
        $hub = $this->resolveHub($request);
        $this->assertCanManage($request, $hub);

        return response()->json([
            'hub' => [
                'id' => $hub->id,
                'name' => $hub->name,
                'slug' => $hub->slug,
                'type' => $hub->type,
            ],
            'modules' => $this->modulesPayload($hub),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $hub = $this->resolveHub($request);
        $this->assertCanManage($request, $hub);

        $validated = $request->validate([
            'modules' => ['required', 'array'],
        ]);

        $input = $this->normalizeModulesPayload($validated['modules']);
        $allowedKeys = $hub->moduleKeysForPage();
        $unknown = array_diff(array_keys($input), $allowedKeys);
        if ($unknown !== []) {
            return response()->json([
                'message' => 'Unknown module keys: '.implode(', ', $unknown),
            ], 422);
        }

        $checklist = $hub->resolvedChecklist();
        foreach ($allowedKeys as $key) {
            if (Hub::isLockedModuleKey($key)) {
                continue;
            }
            if (array_key_exists($key, $input)) {
                $checklist[$key] = (bool) $input[$key];
            }
        }

        $checklist = $hub->applyModuleDependencies($checklist);

        $hub->checklist = $checklist;
        $hub->save();
        $this->hubs->forgetCurrentCache();

        try {
            $this->syncWhiteLabelSettings($hub->fresh());
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }

        $hub = $hub->fresh();

        $this->activityLogs->log([
            'action' => 'modules.update',
            'description' => 'Updated hub modules for '.$hub->name,
            'user' => $request->user(),
            'hub' => $hub,
            'subject' => $hub,
            'request' => $request,
            'status_code' => 200,
            'properties' => [
                'modules' => collect($this->modulesPayload($hub))
                    ->mapWithKeys(fn ($row) => [$row['key'] => $row['enabled']])
                    ->all(),
            ],
        ]);

        return response()->json([
            'message' => 'Modules updated successfully.',
            'hub' => [
                'id' => $hub->id,
                'name' => $hub->name,
                'slug' => $hub->slug,
                'type' => $hub->type,
            ],
            'modules' => $this->modulesPayload($hub),
        ]);
    }

    private function resolveHub(Request $request): Hub
    {
        return $this->actingHubs->targetHub(
            $request->user(),
            $request->integer('hub_id') ?: null
        );
    }

    private function syncWhiteLabelSettings(?Hub $hub): void
    {
        if (! $hub || $hub->isShared() || ! $hub->hasRemoteDatabaseConfigured()) {
            return;
        }

        $this->whiteLabelSync->pushSettings($hub);
    }

    private function assertCanManage(Request $request, Hub $hub): void
    {
        $user = $request->user();
        if (! $user || ! $this->matrix->roleCan(
            $this->actingHubs->capabilityHub($user, self::CAPABILITY),
            (string) $user->role,
            self::CAPABILITY
        )) {
            abort(403, 'Managing modules is disabled for your role on this hub. Enable “Manage hub modules” in Power Admin → Capabilities.');
        }
    }

    /**
     * @return list<array{
     *   key: string,
     *   label: string,
     *   description: string,
     *   enabled: bool,
     *   available: bool,
     *   locked: bool,
     *   locked_reason: ?string,
     *   depends_on: list<string>,
     *   dependencies_met: bool
     * }>
     */
    private function modulesPayload(Hub $hub): array
    {
        $resolved = $hub->resolvedChecklist();
        $items = [];

        foreach ($hub->moduleKeysForPage() as $key) {
            $meta = Hub::CHECKLIST_DEFINITIONS[$key] ?? null;
            if (! $meta) {
                continue;
            }

            $dependsOn = $hub->moduleDependenciesFor($key);
            $dependenciesMet = true;
            foreach ($dependsOn as $dep) {
                if (! ($resolved[$dep] ?? false)) {
                    $dependenciesMet = false;
                    break;
                }
            }

            $locked = Hub::isLockedModuleKey($key);
            $lockedReason = null;
            if ($key === 'module_shared_hub') {
                $locked = true;
                $lockedReason = 'shared_hub';
            } elseif ($key === 'module_white_label_hub') {
                $locked = true;
                $lockedReason = 'white_label_hub';
            }

            $enabled = (bool) ($resolved[$key] ?? false);
            // Dependents cannot stay on when parents are off.
            if (! $dependenciesMet) {
                $enabled = false;
            }

            $items[] = [
                'key' => $key,
                'label' => $meta['label'],
                'description' => $meta['description'],
                'enabled' => $enabled,
                'available' => true,
                'locked' => $locked,
                'locked_reason' => $lockedReason,
                'depends_on' => $dependsOn,
                'dependencies_met' => $dependenciesMet,
            ];
        }

        return $items;
    }

    /**
     * @param  array<int|string, mixed>  $modules
     * @return array<string, mixed>
     */
    private function normalizeModulesPayload(array $modules): array
    {
        if (array_is_list($modules)) {
            $map = [];
            foreach ($modules as $row) {
                if (! is_array($row) || ! isset($row['key'])) {
                    continue;
                }
                $enabled = $row['enabled'] ?? $row['value'] ?? null;
                if ($enabled === null) {
                    continue;
                }
                $map[(string) $row['key']] = $enabled;
            }

            return $map;
        }

        return $modules;
    }
}
