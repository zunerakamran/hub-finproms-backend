<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ContentType;
use App\Models\Post;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ContentTypeController extends Controller
{
    public function index(): JsonResponse
    {
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

    public function update(Request $request, ContentType $contentType): JsonResponse
    {
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('content_types', 'name')->ignore($contentType->id),
            ],
            'slug' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('content_types', 'slug')->ignore($contentType->id),
            ],
        ]);

        $oldName = $contentType->name;
        $newName = trim($validated['name']);
        $slug = array_key_exists('slug', $validated) && filled($validated['slug'])
            ? trim($validated['slug'])
            : Str::slug($newName);

        $contentType->update([
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
            'type' => $contentType->fresh(),
        ]);
    }

    public function destroy(ContentType $contentType): JsonResponse
    {
        $inUse = Post::query()->where('type', $contentType->name)->exists();

        if ($inUse) {
            return response()->json([
                'message' => 'Cannot delete a content type that is used by posts.',
            ], 422);
        }

        $contentType->delete();
        Post::clearTypeSlugMap();

        return response()->json([
            'message' => 'Content type deleted successfully.',
        ]);
    }
}
