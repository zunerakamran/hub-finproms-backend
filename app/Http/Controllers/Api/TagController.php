<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\CreatesOnActingWhiteLabelHub;
use App\Models\Post;
use App\Models\Tag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TagController extends Controller
{
    use CreatesOnActingWhiteLabelHub;

    public function index(Request $request): JsonResponse
    {
        if ($hub = $this->actingWhiteLabelHub($request)) {
            $listed = $this->whiteLabelContent()->listTags($hub);

            return response()->json(array_merge($listed, [
                'target_hub' => $this->targetHubPayload($hub),
                'acting_on_white_label' => true,
            ]));
        }

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
        if ($hub = $this->actingWhiteLabelHub($request)) {
            $validated = $request->validate([
                'name' => ['required', 'string', 'max:100'],
            ]);

            return $this->createTagOnActingHub($hub, $validated);
        }

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

    public function update(Request $request, int $tag): JsonResponse
    {
        if ($hub = $this->actingWhiteLabelHub($request)) {
            $validated = $request->validate([
                'name' => ['required', 'string', 'max:100'],
            ]);

            return $this->updateTagOnActingHub($hub, $tag, $validated);
        }

        $model = Tag::query()->findOrFail($tag);

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('tags', 'name')->ignore($model->id),
            ],
        ]);

        $oldName = $model->name;
        $newName = trim($validated['name']);

        $model->update(['name' => $newName]);

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
            'tag' => $model->fresh(),
        ]);
    }

    public function destroy(Request $request, int $tag): JsonResponse
    {
        if ($hub = $this->actingWhiteLabelHub($request)) {
            return $this->deleteTagOnActingHub($hub, $tag);
        }

        $model = Tag::query()->findOrFail($tag);
        $inUse = Post::query()->whereJsonContains('tags', $model->name)->exists();

        if ($inUse) {
            return response()->json([
                'message' => 'Cannot delete a tag that is used by posts.',
            ], 422);
        }

        $model->delete();

        return response()->json([
            'message' => 'Tag deleted successfully.',
        ]);
    }
}
