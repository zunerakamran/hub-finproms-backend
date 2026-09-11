<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Hub;
use App\Services\CapabilitiesMatrixService;
use App\Services\HubService;
use App\Services\HubVisibilityTransitionService;
use App\Services\SubscriberCreditsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PowerAdminHubController extends Controller
{
    public function __construct(
        private readonly HubService $hubs,
        private readonly HubVisibilityTransitionService $visibilityTransitions,
        private readonly SubscriberCreditsService $subscriberCredits,
        private readonly CapabilitiesMatrixService $matrix
    ) {}

    public function index(): JsonResponse
    {
        $hubs = Hub::query()
            ->orderByRaw("CASE WHEN type = ? THEN 0 ELSE 1 END", [Hub::TYPE_SHARED])
            ->orderBy('name')
            ->get()
            ->map(fn (Hub $hub) => $hub->toAdminArray())
            ->values();

        return response()->json([
            'hubs' => $hubs,
            'checklist_definitions' => $this->definitionsPayload(),
            'checklist_groups' => $this->functionalityGroupsPayload(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->normalizeOptionalUrlFields($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'alpha_dash', 'unique:hubs,slug'],
            'primary_color' => ['nullable', 'string', 'max:32'],
            'secondary_color' => ['nullable', 'string', 'max:32'],
            'logo_url' => ['nullable', 'string', 'max:2048'],
            'frontend_url' => ['nullable', 'string', 'max:2048', 'url'],
            'api_url' => ['nullable', 'string', 'max:2048', 'url'],
            'deploy_notes' => ['nullable', 'string', 'max:5000'],
            'db_driver' => ['nullable', 'string', Rule::in(['mysql', 'pgsql', 'sqlsrv'])],
            'db_host' => ['nullable', 'string', 'max:255'],
            'db_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'db_database' => ['nullable', 'string', 'max:255'],
            'db_username' => ['nullable', 'string', 'max:255'],
            'db_password' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
            'checklist' => ['sometimes', 'array'],
        ]);

        $slug = $validated['slug'] ?? Str::slug($validated['name']);
        if ($slug === '' || Hub::query()->where('slug', $slug)->exists()) {
            $slug = Str::slug($validated['name']).'-'.Str::lower(Str::random(4));
        }

        $checklist = array_key_exists('checklist', $validated)
            ? $this->hubs->sanitizeChecklist($validated['checklist'], Hub::TYPE_WHITE_LABEL)
            : Hub::defaultChecklist(Hub::TYPE_WHITE_LABEL);

        $hub = Hub::query()->create([
            'name' => $validated['name'],
            'slug' => $slug,
            'type' => Hub::TYPE_WHITE_LABEL,
            'is_active' => $validated['is_active'] ?? true,
            'primary_color' => $validated['primary_color'] ?? null,
            'secondary_color' => $validated['secondary_color'] ?? null,
            'logo_url' => $validated['logo_url'] ?? null,
            'frontend_url' => $validated['frontend_url'] ?? null,
            'api_url' => $validated['api_url'] ?? null,
            'deploy_notes' => $validated['deploy_notes'] ?? null,
            'db_driver' => $validated['db_driver'] ?? 'mysql',
            'db_host' => $validated['db_host'] ?? null,
            'db_port' => $validated['db_port'] ?? null,
            'db_database' => $validated['db_database'] ?? null,
            'db_username' => $validated['db_username'] ?? null,
            'db_password' => $validated['db_password'] ?? null,
            'checklist' => $checklist,
        ]);

        return response()->json([
            'message' => 'White-labelled hub created.',
            'hub' => $hub->toAdminArray(),
        ], 201);
    }

    public function show(Hub $hub): JsonResponse
    {
        return response()->json([
            'hub' => $hub->toAdminArray(),
            'checklist_definitions' => $this->definitionsPayload(),
            'checklist_groups' => $this->functionalityGroupsPayload(),
        ]);
    }

    public function update(Request $request, Hub $hub): JsonResponse
    {
        $this->normalizeOptionalUrlFields($request);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'slug' => [
                'sometimes',
                'string',
                'max:255',
                'alpha_dash',
                Rule::unique('hubs', 'slug')->ignore($hub->id),
            ],
            'primary_color' => ['nullable', 'string', 'max:32'],
            'secondary_color' => ['nullable', 'string', 'max:32'],
            'logo_url' => ['nullable', 'string', 'max:2048'],
            'frontend_url' => ['nullable', 'string', 'max:2048', 'url'],
            'api_url' => ['nullable', 'string', 'max:2048', 'url'],
            'deploy_notes' => ['nullable', 'string', 'max:5000'],
            'db_driver' => ['nullable', 'string', Rule::in(['mysql', 'pgsql', 'sqlsrv'])],
            'db_host' => ['nullable', 'string', 'max:255'],
            'db_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'db_database' => ['nullable', 'string', 'max:255'],
            'db_username' => ['nullable', 'string', 'max:255'],
            'db_password' => ['nullable', 'string', 'max:1000'],
            'clear_db_password' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            // null / omitted unlimited flag + credits: Power Admin subscriber allotment
            'subscriber_credits_unlimited' => ['sometimes', 'boolean'],
            'subscriber_credits' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000000'],
        ]);

        // Shared hub slug/type stay stable.
        if ($hub->isShared() && array_key_exists('slug', $validated)) {
            unset($validated['slug']);
        }

        if ($hub->isShared() && array_key_exists('is_active', $validated) && ! $validated['is_active']) {
            return response()->json([
                'message' => 'The shared hub cannot be deactivated.',
            ], 422);
        }

        $creditsPayload = array_intersect_key($validated, array_flip([
            'subscriber_credits_unlimited',
            'subscriber_credits',
        ]));
        unset($validated['subscriber_credits_unlimited'], $validated['subscriber_credits']);

        $clearDbPassword = (bool) ($validated['clear_db_password'] ?? false);
        unset($validated['clear_db_password']);

        // Blank password on update means "keep existing".
        if (array_key_exists('db_password', $validated) && ! filled($validated['db_password'])) {
            unset($validated['db_password']);
        }

        $hub->fill($validated);
        if ($clearDbPassword) {
            $hub->db_password = null;
        }
        $hub->save();

        if ($creditsPayload !== []) {
            $user = $request->user();
            if (! $user || ! $this->matrix->roleCan($hub, (string) $user->role, 'dashboard_manage_subscriber_credits')) {
                return response()->json([
                    'message' => 'Setting subscriber credits is disabled for your role. Enable it in Power Admin → Capabilities.',
                ], 403);
            }

            $unlimited = array_key_exists('subscriber_credits_unlimited', $creditsPayload)
                ? (bool) $creditsPayload['subscriber_credits_unlimited']
                : $hub->givesUnlimitedSubscriberCredits();

            if ($unlimited) {
                $this->subscriberCredits->updateHubSetting($hub, null);
            } else {
                if (! array_key_exists('subscriber_credits', $creditsPayload)
                    || $creditsPayload['subscriber_credits'] === null) {
                    throw ValidationException::withMessages([
                        'subscriber_credits' => 'Enter the number of credits per subscriber, or choose Unlimited.',
                    ]);
                }
                $this->subscriberCredits->updateHubSetting($hub, (int) $creditsPayload['subscriber_credits']);
            }
        }

        $this->hubs->forgetCurrentCache();

        return response()->json([
            'message' => 'Hub updated successfully.',
            'hub' => $hub->fresh()->toAdminArray(),
        ]);
    }

    public function updateChecklist(Request $request, Hub $hub): JsonResponse
    {
        $validated = $request->validate([
            'checklist' => ['required', 'array'],
        ]);

        // Accept either { checklist: { key: bool } } or { checklist: [ { key, enabled } ] }
        $input = $this->normalizeChecklistPayload($validated['checklist']);

        $unknown = array_diff(array_keys($input), array_keys(Hub::CHECKLIST_DEFINITIONS));
        if ($unknown !== []) {
            return response()->json([
                'message' => 'Unknown checklist keys: '.implode(', ', $unknown),
            ], 422);
        }

        // Hub checklist screen edits Functionalities only; ignore capability keys.
        $input = array_filter(
            $input,
            fn ($key) => Hub::isFunctionalityKey((string) $key),
            ARRAY_FILTER_USE_KEY
        );

        $before = $hub->resolvedChecklist();
        $merged = $this->hubs->mergeChecklist($hub, $input);
        $transitionType = $this->visibilityTransitions->detectTransition($before, $merged);
        $checklist = $this->visibilityTransitions->applyModeFlags($merged, $transitionType);

        // Keep a previously configured fixed allotment when returning to private
        // (PRIVATE_MODE_FLAGS defaults unlimited; do not wipe Power Admin setting).
        if ($transitionType === 'public_to_private' && ! $hub->givesUnlimitedSubscriberCredits()) {
            $checklist['unlimited_credits'] = false;
            $checklist['paid_credits'] = true;
        }

        $this->subscriberCredits->syncFieldFromChecklist($hub, $checklist);

        $hub->checklist = $checklist;
        $hub->save();

        $this->hubs->forgetCurrentCache();

        $sideEffects = $this->visibilityTransitions->runSideEffects($hub->fresh(), $transitionType);

        $message = 'Checklist updated successfully.';
        if ($sideEffects['type'] === 'private_to_public') {
            $message = sprintf(
                'Hub switched to public. Suspended %d imported advisor(s) and stopped advisor auto-renew.',
                $sideEffects['advisors_suspended']
            );
        } elseif ($sideEffects['type'] === 'public_to_private') {
            $message = sprintf(
                'Hub switched to private. Reactivated %d imported advisor(s).',
                $sideEffects['advisors_reactivated']
            );
        }

        return response()->json([
            'message' => $message,
            'hub' => $hub->fresh()->toAdminArray(),
            'visibility_transition' => $sideEffects,
        ]);
    }

    /**
     * @param  array<int|string, mixed>  $checklist
     * @return array<string, mixed>
     */
    private function normalizeChecklistPayload(array $checklist): array
    {
        // List form: [ { key: 'public_subscribe', enabled: true }, ... ]
        if (array_is_list($checklist)) {
            $map = [];
            foreach ($checklist as $row) {
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

        return $checklist;
    }

    /**
     * Functionalities definitions only (hub checklist screen).
     *
     * @return list<array{key: string, label: string, description: string, group: string, group_label: string, default_shared: bool, default_white_label: bool, exclusive_with: ?string}>
     */
    private function definitionsPayload(): array
    {
        $items = [];
        foreach (Hub::CHECKLIST_DEFINITIONS as $key => $meta) {
            $group = $meta['group'] ?? Hub::GROUP_BEHAVIOUR;
            if (! in_array($group, Hub::FUNCTIONALITY_GROUPS, true)) {
                continue;
            }
            $items[] = [
                'key' => $key,
                'label' => $meta['label'],
                'description' => $meta['description'],
                'group' => $group,
                'group_label' => Hub::CHECKLIST_GROUPS[$group] ?? 'Other',
                'default_shared' => $meta['default_shared'],
                'default_white_label' => $meta['default_white_label'],
                'exclusive_with' => Hub::CHECKLIST_OPPOSITES[$key] ?? null,
            ];
        }

        return $items;
    }

    /**
     * @return array<string, string>
     */
    private function functionalityGroupsPayload(): array
    {
        $groups = [];
        foreach (Hub::FUNCTIONALITY_GROUPS as $group) {
            $groups[$group] = Hub::CHECKLIST_GROUPS[$group] ?? $group;
        }

        return $groups;
    }

    /**
     * Empty strings fail Laravel's "url" rule; treat them as null.
     */
    private function normalizeOptionalUrlFields(Request $request): void
    {
        $merge = [];
        foreach (['frontend_url', 'api_url', 'deploy_notes', 'logo_url', 'db_host', 'db_database', 'db_username', 'db_password'] as $key) {
            if ($request->exists($key) && $request->input($key) === '') {
                $merge[$key] = null;
            }
        }
        if ($request->exists('db_port') && $request->input('db_port') === '') {
            $merge['db_port'] = null;
        }
        if ($merge !== []) {
            $request->merge($merge);
        }
    }
}
