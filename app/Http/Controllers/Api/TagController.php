<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\Tag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TagController extends Controller
{
    public function index(): JsonResponse
    {
        $tags = Tag::query()->orderBy('name')->get(['id', 'name']);

        $tags = $tags->map(function (Tag $tag) {
            return [
                'id' => $tag->id,
                'name' => $tag->name,
                'posts_count' => Post::query()
                    ->where('is_active', true)
                    ->whereJsonContains('tags', $tag->name)
                    ->count(),
            ];
        })->values();

        return response()->json([
            'tags' => $tags,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100', 'unique:tags,name'],
        ]);

        $tag = Tag::create([
            'name' => trim($validated['name']),
        ]);

        return response()->json([
            'message' => 'Tag created successfully.',
            'tag' => $tag,
        ], 201);
    }

    public function update(Request $request, Tag $tag): JsonResponse
    {
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('tags', 'name')->ignore($tag->id),
            ],
        ]);

        $oldName = $tag->name;
        $newName = trim($validated['name']);

        $tag->update(['name' => $newName]);

        if ($oldName !== $newName) {
            Post::query()
                ->whereJsonContains('tags', $oldName)
                ->get()
                ->each(function (Post $post) use ($oldName, $newName) {
                    $tags = collect($post->tags ?? [])
                        ->map(fn ($value) => $value === $oldName ? $newName : $value)
                        ->unique()
                        ->values()
                        ->all();

                    $post->update(['tags' => $tags]);
                });
        }

        return response()->json([
            'message' => 'Tag updated successfully.',
            'tag' => $tag->fresh(),
        ]);
    }

    public function destroy(Tag $tag): JsonResponse
    {
        $inUse = Post::query()->whereJsonContains('tags', $tag->name)->exists();

        if ($inUse) {
            return response()->json([
                'message' => 'Cannot delete a tag that is used by posts.',
            ], 422);
        }

        $tag->delete();

        return response()->json([
            'message' => 'Tag deleted successfully.',
        ]);
    }
}
