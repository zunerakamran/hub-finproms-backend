<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Post;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CategoryController extends Controller
{
    public function index(): JsonResponse
    {
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
            // Alias: category = content type
            'types' => $categories,
            'total_posts' => Post::query()->where('is_active', true)->count(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
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

        Post::clearCategorySlugMap();

        return response()->json([
            'message' => 'Content type created successfully.',
            'category' => $category,
        ], 201);
    }

    public function update(Request $request, Category $category): JsonResponse
    {
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('categories', 'name')->ignore($category->id),
            ],
            'slug' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('categories', 'slug')->ignore($category->id),
            ],
        ]);

        $oldName = $category->name;
        $newName = trim($validated['name']);
        $slug = array_key_exists('slug', $validated) && filled($validated['slug'])
            ? trim($validated['slug'])
            : Str::slug($newName);

        $category->update([
            'name' => $newName,
            'slug' => $slug,
        ]);

        if ($oldName !== $newName) {
            Post::query()
                ->where('category', $oldName)
                ->update(['category' => $newName]);
        }

        Post::clearCategorySlugMap();

        return response()->json([
            'message' => 'Content type updated successfully.',
            'category' => $category->fresh(),
        ]);
    }

    public function destroy(Category $category): JsonResponse
    {
        $inUse = Post::query()->where('category', $category->name)->exists();

        if ($inUse) {
            return response()->json([
                'message' => 'Cannot delete a content type that is used by posts.',
            ], 422);
        }

        $category->delete();
        Post::clearCategorySlugMap();

        return response()->json([
            'message' => 'Content type deleted successfully.',
        ]);
    }
}
