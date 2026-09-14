<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\CreatesOnActingWhiteLabelHub;
use App\Models\ContentType;
use App\Models\Post;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ContentTypeController extends Controller
{
    use CreatesOnActingWhiteLabelHub;

    public function index(Request $request): JsonResponse
    {
        if ($hub = $this->actingWhiteLabelHub($request)) {
            $listed = $this->whiteLabelContent()->listTypes($hub);

            return response()->json(array_merge($listed, [
                'total_posts' => count($listed['types']),
                'target_hub' => $this->targetHubPayload($hub),
                'acting_on_white_label' => true,
            ]));
        }

        $counts = Post::query()
            ->where('is_active', true)
            ->selectRaw('type, COUNT(*) as posts_count')
            ->groupBy('type')
            ->pluck('posts_count', 'type');

        $types = ContentType::query()
            ->orderBy('name')
            ->get(['id', 'name', 'slug'])
            ->map(fn (ContentType $type) => [
                'id' => $type->id,
                'name' => $type->name,
                'slug' => $type->slug,
                'posts_count' => (int) ($counts[$type->name] ?? 0),
            ])
            ->values();

        return response()->json([
            'types' => $types,
            'total_posts' => Post::query()->where('is_active', true)->count(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if ($hub = $this->actingWhiteLabelHub($request)) {
            $validated = $request->validate([
                'name' => ['required', 'string', 'max:100'],
                'slug' => ['nullable', 'string', 'max:100'],
            ]);

            return $this->createTypeOnActingHub($hub, $validated);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100', 'unique:content_types,name'],
            'slug' => ['nullable', 'string', 'max:100', 'unique:content_types,slug'],
        ]);

        $name = trim($validated['name']);
        $slug = trim((string) ($validated['slug'] ?? '')) ?: Str::slug($name);

        $type = ContentType::create([
            'name' => $name,
            'slug' => $slug,
        ]);

        Post::clearTypeSlugMap();

        return response()->json([
            'message' => 'Content type created successfully.',
            'type' => $type,
        ], 201);
    }

    public function update(Request $request, int $contentType): JsonResponse
    {
        if ($hub = $this->actingWhiteLabelHub($request)) {
            $validated = $request->validate([
                'name' => ['required', 'string', 'max:100'],
                'slug' => ['nullable', 'string', 'max:100'],
            ]);

            return $this->updateTypeOnActingHub($hub, $contentType, $validated);
        }

        $model = ContentType::query()->findOrFail($contentType);

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('content_types', 'name')->ignore($model->id),
            ],
            'slug' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('content_types', 'slug')->ignore($model->id),
            ],
        ]);

        $oldName = $model->name;
        $newName = trim($validated['name']);
        $slug = array_key_exists('slug', $validated) && filled($validated['slug'])
            ? trim($validated['slug'])
            : Str::slug($newName);

        $model->update([
            'name' => $newName,
            'slug' => $slug,
        ]);

        if ($oldName !== $newName) {
            Post::query()
                ->where('type', $oldName)
                ->update(['type' => $newName]);
        }

        Post::clearTypeSlugMap();

        return response()->json([
            'message' => 'Content type updated successfully.',
            'type' => $model->fresh(),
        ]);
    }

    public function destroy(Request $request, int $contentType): JsonResponse
    {
        if ($hub = $this->actingWhiteLabelHub($request)) {
            return $this->deleteTypeOnActingHub($hub, $contentType);
        }

        $model = ContentType::query()->findOrFail($contentType);
        $inUse = Post::query()->where('type', $model->name)->exists();

        if ($inUse) {
            return response()->json([
                'message' => 'Cannot delete a content type that is used by posts.',
            ], 422);
        }

        $model->delete();
        Post::clearTypeSlugMap();

        return response()->json([
            'message' => 'Content type deleted successfully.',
        ]);
    }
}
