<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\CreatesOnActingWhiteLabelHub;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Post;
use App\Services\ContentPushService;
use App\Support\CategoryIconOptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

class CategoryController extends Controller
{
    use CreatesOnActingWhiteLabelHub;

    public function __construct(
        private readonly ContentPushService $contentPush
    ) {}

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

        $categories = Category::query()
            ->orderBy('name')
            ->get()
            ->map(fn (Category $category) => $category->toApiArray(
                Post::query()
                    ->where('is_active', true)
                    ->whereJsonContains('categories', $category->name)
                    ->count()
            ))
            ->values();

        return response()->json([
            'categories' => $categories,
            'total_posts' => Post::query()->where('is_active', true)->count(),
            'icon_options' => CategoryIconOptions::keys(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validateCategoryPayload($request);

        if ($hub = $this->actingWhiteLabelHub($request)) {
            $payload = $this->payloadForRemote($request, $validated);

            return $this->createCategoryOnActingHub($hub, $payload);
        }

        $category = Category::create([
            'name' => $validated['name'],
            'slug' => $validated['slug'],
            'icon' => $validated['icon'],
            'icon_path' => null,
        ]);

        $this->applyIconUpload($request, $category);
        $fresh = $category->fresh();
        $hubSync = $this->safeSyncCategoryToHubs($fresh);

        return response()->json([
            'message' => 'Category created successfully.',
            'category' => $fresh->toApiArray(0),
            'hub_sync' => $hubSync,
        ], 201);
    }

    public function update(Request $request, int $category): JsonResponse
    {
        if ($hub = $this->actingWhiteLabelHub($request)) {
            $validated = $this->validateCategoryPayload($request, null);
            $payload = $this->payloadForRemote($request, $validated);

            return $this->updateCategoryOnActingHub($hub, $category, $payload);
        }

        $model = Category::query()->findOrFail($category);
        $validated = $this->validateCategoryPayload($request, $model);

        $oldName = $model->name;
        $model->fill([
            'name' => $validated['name'],
            'slug' => $validated['slug'],
            'icon' => $validated['icon'],
        ]);

        $removeIcon = filter_var($request->input('remove_icon'), FILTER_VALIDATE_BOOLEAN);
        if ($removeIcon && ! $request->hasFile('icon_file')) {
            $model->deleteStoredIcon();
            $model->icon_path = null;
            $model->icon = null;
        }

        $model->save();
        $this->applyIconUpload($request, $model);

        if ($oldName !== $model->name) {
            Post::query()
                ->whereJsonContains('categories', $oldName)
                ->get()
                ->each(function (Post $post) use ($oldName, $model) {
                    $categories = collect($post->categories ?? [])
                        ->map(fn ($value) => $value === $oldName ? $model->name : $value)
                        ->unique()
                        ->values()
                        ->all();

                    $post->update(['categories' => $categories]);
                });
        }

        $fresh = $model->fresh();
        $hubSync = $this->safeSyncCategoryToHubs(
            $fresh,
            $oldName !== $fresh->name ? $oldName : null
        );

        return response()->json([
            'message' => 'Category updated successfully.',
            'category' => $fresh->toApiArray(
                Post::query()
                    ->where('is_active', true)
                    ->whereJsonContains('categories', $fresh->name)
                    ->count()
            ),
            'hub_sync' => $hubSync,
        ]);
    }

    public function destroy(Request $request, int $category): JsonResponse
    {
        if ($hub = $this->actingWhiteLabelHub($request)) {
            return $this->deleteCategoryOnActingHub($hub, $category);
        }

        $model = Category::query()->findOrFail($category);
        $inUse = Post::query()->whereJsonContains('categories', $model->name)->exists();

        if ($inUse) {
            return response()->json([
                'message' => 'Cannot delete a category that is used by posts.',
            ], 422);
        }

        $name = $model->name;
        $model->delete();
        $hubSync = $this->safeDeleteCategoryFromHubs($name);

        return response()->json([
            'message' => 'Category deleted successfully.',
            'hub_sync' => $hubSync,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function safeSyncCategoryToHubs(Category $category, ?string $previousName = null): ?array
    {
        try {
            return $this->contentPush->syncCategoryToEligibleHubs($category, $previousName);
        } catch (Throwable $e) {
            report($e);

            return [
                'synced' => 0,
                'failed' => 1,
                'skipped' => 0,
                'results' => [[
                    'hub_id' => 0,
                    'hub_name' => 'all',
                    'status' => 'failed',
                    'message' => $e->getMessage(),
                ]],
            ];
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function safeDeleteCategoryFromHubs(string $categoryName): ?array
    {
        try {
            return $this->contentPush->deleteCategoryFromEligibleHubs($categoryName);
        } catch (Throwable $e) {
            report($e);

            return [
                'deleted' => 0,
                'failed' => 1,
                'skipped' => 0,
                'results' => [[
                    'hub_id' => 0,
                    'hub_name' => 'all',
                    'status' => 'failed',
                    'message' => $e->getMessage(),
                ]],
            ];
        }
    }

    /**
     * @return array{name: string, slug: string, icon: ?string}
     */
    private function validateCategoryPayload(Request $request, ?Category $existing = null): array
    {
        $nameRules = ['required', 'string', 'max:100'];
        $slugRules = ['nullable', 'string', 'max:100'];

        if ($existing) {
            $nameRules[] = Rule::unique('categories', 'name')->ignore($existing->id);
            $slugRules[] = Rule::unique('categories', 'slug')->ignore($existing->id);
        } elseif (! $this->actingWhiteLabelHub($request)) {
            $nameRules[] = 'unique:categories,name';
            $slugRules[] = 'unique:categories,slug';
        }

        if ($request->has('icon') && trim((string) $request->input('icon')) === '') {
            $request->merge(['icon' => null]);
        }

        $validated = $request->validate([
            'name' => $nameRules,
            'slug' => $slugRules,
            'icon' => ['nullable', 'string', 'max:64', Rule::in(CategoryIconOptions::keys())],
            'icon_file' => ['sometimes', 'file', 'image', 'max:2048'],
            'remove_icon' => ['sometimes', 'boolean'],
            'clear_upload' => ['sometimes', 'boolean'],
        ]);

        $name = trim($validated['name']);
        $slug = trim((string) ($validated['slug'] ?? '')) ?: Str::slug($name);
        $icon = array_key_exists('icon', $validated)
            ? (filled($validated['icon'] ?? null) ? (string) $validated['icon'] : null)
            : ($existing?->icon);

        return [
            'name' => $name,
            'slug' => $slug,
            'icon' => $icon,
        ];
    }

    private function applyIconUpload(Request $request, Category $category): void
    {
        if (! $request->hasFile('icon_file')) {
            // Font icon selected → drop any previous upload so the FA icon shows.
            if (
                filled($category->icon)
                && filter_var($request->input('clear_upload'), FILTER_VALIDATE_BOOLEAN)
            ) {
                $category->deleteStoredIcon();
                $category->icon_path = null;
                $category->save();
            }

            return;
        }

        $category->deleteStoredIcon();
        $path = $request->file('icon_file')->store('categories/icons', 'public');
        $category->icon_path = $path;
        // Uploaded image takes precedence; clear font icon so UI shows the file.
        $category->icon = null;
        $category->save();
    }

    /**
     * Build remote payload; uploaded files stay on this deploy and are sent as absolute URLs.
     *
     * @param  array{name: string, slug: string, icon: ?string}  $validated
     * @return array{name: string, slug: string, icon: ?string, icon_path?: ?string, remove_icon?: bool}
     */
    private function payloadForRemote(Request $request, array $validated): array
    {
        $payload = [
            'name' => $validated['name'],
            'slug' => $validated['slug'],
            'icon' => $validated['icon'],
        ];

        $removeIcon = filter_var($request->input('remove_icon'), FILTER_VALIDATE_BOOLEAN);
        if ($removeIcon && ! $request->hasFile('icon_file')) {
            $payload['remove_icon'] = true;
            $payload['icon_path'] = null;
            $payload['icon'] = null;
        }

        if (filter_var($request->input('clear_upload'), FILTER_VALIDATE_BOOLEAN)) {
            $payload['clear_upload'] = true;
        }

        if ($request->hasFile('icon_file')) {
            $path = $request->file('icon_file')->store('categories/icons', 'public');
            $payload['icon_path'] = rtrim((string) config('app.url'), '/').'/api/media/'.$path;
            $payload['icon'] = null;
        }

        return $payload;
    }
}
