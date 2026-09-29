<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GeneralComplianceContentType;
use App\Models\GeneralComplianceRequestVersion;
use App\Models\Hub;
use App\Models\User;
use App\Services\ActingHubService;
use App\Services\GeneralComplianceService;
use App\Services\HubService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class GeneralComplianceContentTypeController extends Controller
{
    public function __construct(
        private readonly GeneralComplianceService $compliance,
        private readonly HubService $hubs,
        private readonly ActingHubService $actingHubs
    ) {}

    private function gcHub(?User $user): Hub
    {
        if ($user) {
            return $this->actingHubs->capabilityHub($user, 'gc_manage_content_types');
        }

        return $this->hubs->current();
    }

    public function index(Request $request): JsonResponse
    {
        $hub = $this->gcHub($request->user());
        $this->compliance->assertModuleEnabled($hub);

        $types = GeneralComplianceContentType::query()
            ->orderBy('name')
            ->get(['id', 'name', 'slug']);

        $counts = GeneralComplianceRequestVersion::query()
            ->whereNotNull('content_type')
            ->selectRaw('content_type, COUNT(*) as usage_count')
            ->groupBy('content_type')
            ->pluck('usage_count', 'content_type');

        return response()->json([
            'types' => $types->map(fn (GeneralComplianceContentType $type) => [
                'id' => $type->id,
                'name' => $type->name,
                'slug' => $type->slug,
                'usage_count' => (int) ($counts[$type->name] ?? 0),
            ])->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $hub = $this->gcHub($request->user());
        $this->compliance->assertModuleEnabled($hub);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'slug' => ['nullable', 'string', 'max:100'],
        ]);

        $name = trim($validated['name']);
        $slug = trim((string) ($validated['slug'] ?? '')) ?: Str::slug($name);

        if (GeneralComplianceContentType::query()->where('name', $name)->exists()) {
            throw ValidationException::withMessages([
                'name' => 'That content type name is already in use.',
            ]);
        }
        if (GeneralComplianceContentType::query()->where('slug', $slug)->exists()) {
            throw ValidationException::withMessages([
                'slug' => 'That slug is already in use.',
            ]);
        }

        $type = GeneralComplianceContentType::query()->create([
            'name' => $name,
            'slug' => $slug,
        ]);

        return response()->json([
            'message' => 'Content type created successfully.',
            'type' => $type,
        ], 201);
    }

    public function update(Request $request, int $generalComplianceContentType): JsonResponse
    {
        $hub = $this->gcHub($request->user());
        $this->compliance->assertModuleEnabled($hub);

        $model = GeneralComplianceContentType::query()->findOrFail($generalComplianceContentType);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'slug' => ['nullable', 'string', 'max:100'],
        ]);

        $oldName = $model->name;
        $newName = trim($validated['name']);
        $slug = array_key_exists('slug', $validated) && filled($validated['slug'])
            ? trim($validated['slug'])
            : Str::slug($newName);

        if (GeneralComplianceContentType::query()
            ->where('name', $newName)
            ->where('id', '!=', $model->id)
            ->exists()) {
            throw ValidationException::withMessages([
                'name' => 'That content type name is already in use.',
            ]);
        }
        if (GeneralComplianceContentType::query()
            ->where('slug', $slug)
            ->where('id', '!=', $model->id)
            ->exists()) {
            throw ValidationException::withMessages([
                'slug' => 'That slug is already in use.',
            ]);
        }

        $model->update([
            'name' => $newName,
            'slug' => $slug,
        ]);

        if ($oldName !== $newName) {
            GeneralComplianceRequestVersion::query()
                ->where('content_type', $oldName)
                ->update(['content_type' => $newName]);
        }

        return response()->json([
            'message' => 'Content type updated successfully.',
            'type' => $model->fresh(),
        ]);
    }

    public function destroy(Request $request, int $generalComplianceContentType): JsonResponse
    {
        $hub = $this->gcHub($request->user());
        $this->compliance->assertModuleEnabled($hub);

        $model = GeneralComplianceContentType::query()->findOrFail($generalComplianceContentType);
        $inUse = GeneralComplianceRequestVersion::query()
            ->where('content_type', $model->name)
            ->exists();

        if ($inUse) {
            return response()->json([
                'message' => 'Cannot delete a content type that is used by general compliance requests.',
            ], 422);
        }

        $model->delete();

        return response()->json([
            'message' => 'Content type deleted successfully.',
        ]);
    }
}
