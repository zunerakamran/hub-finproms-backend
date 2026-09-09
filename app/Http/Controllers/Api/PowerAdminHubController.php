<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Hub;
use App\Services\HubService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PowerAdminHubController extends Controller
{
    public function __construct(
        private readonly HubService $hubs
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
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'alpha_dash', 'unique:hubs,slug'],
            'primary_color' => ['nullable', 'string', 'max:32'],
            'secondary_color' => ['nullable', 'string', 'max:32'],
            'logo_url' => ['nullable', 'string', 'max:2048'],
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
        ]);
    }

    public function update(Request $request, Hub $hub): JsonResponse
    {
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
            'is_active' => ['sometimes', 'boolean'],
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

        $hub->fill($validated);
        $hub->save();

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

        $hub->checklist = $this->hubs->mergeChecklist($hub, $input);
        $hub->save();

        $this->hubs->forgetCurrentCache();

        return response()->json([
            'message' => 'Checklist updated successfully.',
            'hub' => $hub->fresh()->toAdminArray(),
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
     * @return list<array{key: string, label: string, description: string, default_shared: bool, default_white_label: bool}>
     */
    private function definitionsPayload(): array
    {
        $items = [];
        foreach (Hub::CHECKLIST_DEFINITIONS as $key => $meta) {
            $items[] = [
                'key' => $key,
                'label' => $meta['label'],
                'description' => $meta['description'],
                'default_shared' => $meta['default_shared'],
                'default_white_label' => $meta['default_white_label'],
                'exclusive_with' => Hub::CHECKLIST_OPPOSITES[$key] ?? null,
            ];
        }

        return $items;
    }
}
