<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\CreatesOnActingWhiteLabelHub;
use App\Models\Category;
use App\Models\Post;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CategoryController extends Controller
{
    use CreatesOnActingWhiteLabelHub;

    public function index(Request $request): JsonResponse
    {
        if ($hub = $this->actingWhiteLabelHub($request)) {
            $listed = $this->whiteLabelContent()->listCategories($hub);

            return response()->json(array_merge($listed, [
                'total_posts' => count($listed['categories']),
                'target_hub' => $this->targetHubPayload($hub),
                'acting_on_white_label' => true,
            ]));
        }

        $counts = Post::query()
            ->where('is_active', true)
            ->selectRaw('category, COUNT(*) as posts_count')
            ->groupBy('category')
            ->pluck('posts_count', 'category');

        $categories = Category::query()
            ->orderBy('name')
            ->get(['id', 'name', 'slug'])
            ->map(fn (Category $category) => [
                'id' => $category->id,
                'name' => $category->name,
                'slug' => $category->slug,
                'posts_count' => (int) ($counts[$category->name] ?? 0),
            ])
            ->values();

        return response()->json([
            'categories' => $categories,
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

            return $this->createCategoryOnActingHub($hub, $validated);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100', 'unique:categories,name'],
            'slug' => ['nullable', 'string', 'max:100', 'unique:categories,slug'],
        ]);

        $name = trim($validated['name']);
        $slug = trim((string) ($validated['slug'] ?? '')) ?: Str::slug($name);

        $category = Category::create([
            'name' => $name,
            'slug' => $slug,
        ]);

        return response()->json([
            'message' => 'Category created successfully.',
            'category' => $category,
        ], 201);
    }

    public function update(Request $request, int $category): JsonResponse
    {
        if ($hub = $this->actingWhiteLabelHub($request)) {
            $validated = $request->validate([
                'name' => ['required', 'string', 'max:100'],
                'slug' => ['nullable', 'string', 'max:100'],
            ]);

            return $this->updateCategoryOnActingHub($hub, $category, $validated);
        }

        $model = Category::query()->findOrFail($category);

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('categories', 'name')->ignore($model->id),
            ],
            'slug' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('categories', 'slug')->ignore($model->id),
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
                ->where('category', $oldName)
                ->update(['category' => $newName]);
        }

        return response()->json([
            'message' => 'Category updated successfully.',
            'category' => $model->fresh(),
        ]);
    }

    public function destroy(Request $request, int $category): JsonResponse
    {
        if ($hub = $this->actingWhiteLabelHub($request)) {
            return $this->deleteCategoryOnActingHub($hub, $category);
        }

        $model = Category::query()->findOrFail($category);
        $inUse = Post::query()->where('category', $model->name)->exists();

        if ($inUse) {
            return response()->json([
                'message' => 'Cannot delete a category that is used by posts.',
            ], 422);
        }

        $model->delete();

        return response()->json([
            'message' => 'Category deleted successfully.',
        ]);
    }
}
