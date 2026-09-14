<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Hub;
use App\Services\ActingHubService;
use App\Services\CapabilitiesMatrixService;
use App\Services\HubService;
use App\Services\WhiteLabelContentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Create/list catalog content on the acting white-label hub (hub switcher).
 * Content is written only to that hub's own database — not the shared catalog.
 */
class HubContentController extends Controller
{
    public function __construct(
        private readonly WhiteLabelContentService $content,
        private readonly HubService $hubs,
        private readonly CapabilitiesMatrixService $matrix,
        private readonly ActingHubService $actingHubs
    ) {}

    /**
     * @deprecated Use GET /acting-hub (hub switcher). Kept for older clients.
     */
    public function targets(Request $request): JsonResponse
    {
        $this->assertCanControl($request);

        return response()->json([
            'hubs' => $this->actingHubs->switcherHubs(),
            'hub_switcher' => $this->actingHubs->switcherPayload($request->user()),
            'message' => 'Use the hub switcher (PUT /acting-hub) instead of passing hub_id on each request.',
        ]);
    }

    public function posts(Request $request): JsonResponse
    {
        $hub = $this->resolveActingWhiteLabel($request);

        return response()->json($this->content->listPosts(
            $hub,
            min(100, max(1, (int) $request->integer('per_page', 50)))
        ));
    }

    public function storePost(Request $request): JsonResponse
    {
        $hub = $this->resolveActingWhiteLabel($request);
        $this->assertActingContentCapability($request, $hub, 'dashboard_manage_posts');

        if ($request->has('tags') && is_string($request->input('tags'))) {
            $decoded = json_decode($request->input('tags'), true);
            $request->merge([
                'tags' => (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) ? $decoded : [],
            ]);
        }
        if ($request->has('is_active') && ! is_bool($request->input('is_active'))) {
            $request->merge([
                'is_active' => filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN),
            ]);
        }

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'type' => ['required', 'string', 'max:100'],
            'category' => ['required', 'string', 'max:100'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:100'],
            'credits_cost' => ['required', 'integer', 'min:1'],
            'is_active' => ['sometimes', 'boolean'],
            'attachment' => ['nullable', 'file', 'max:102400'],
        ]);

        try {
            $post = $this->content->createPost(
                $hub,
                $validated,
                $request->file('attachment')
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Post created on '.$hub->name.' (white-label database).',
            'post' => $post,
            'target_hub' => ['id' => $hub->id, 'name' => $hub->name, 'slug' => $hub->slug],
        ], 201);
    }

    public function types(Request $request): JsonResponse
    {
        $hub = $this->resolveActingWhiteLabel($request);

        return response()->json($this->content->listTypes($hub));
    }

    public function storeType(Request $request): JsonResponse
    {
        $hub = $this->resolveActingWhiteLabel($request);
        $this->assertActingContentCapability($request, $hub, 'dashboard_manage_types');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'slug' => ['nullable', 'string', 'max:100'],
        ]);

        try {
            $type = $this->content->createType($hub, $validated);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Type created on '.$hub->name.'.',
            'type' => $type,
            'target_hub' => ['id' => $hub->id, 'name' => $hub->name, 'slug' => $hub->slug],
        ], 201);
    }

    public function categories(Request $request): JsonResponse
    {
        $hub = $this->resolveActingWhiteLabel($request);

        return response()->json($this->content->listCategories($hub));
    }

    public function storeCategory(Request $request): JsonResponse
    {
        $hub = $this->resolveActingWhiteLabel($request);
        $this->assertActingContentCapability($request, $hub, 'dashboard_manage_categories');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'slug' => ['nullable', 'string', 'max:100'],
        ]);

        try {
            $category = $this->content->createCategory($hub, $validated);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Category created on '.$hub->name.'.',
            'category' => $category,
            'target_hub' => ['id' => $hub->id, 'name' => $hub->name, 'slug' => $hub->slug],
        ], 201);
    }

    public function tags(Request $request): JsonResponse
    {
        $hub = $this->resolveActingWhiteLabel($request);

        return response()->json($this->content->listTags($hub));
    }

    public function storeTag(Request $request): JsonResponse
    {
        $hub = $this->resolveActingWhiteLabel($request);
        $this->assertActingContentCapability($request, $hub, 'dashboard_manage_tags');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
        ]);

        try {
            $tag = $this->content->createTag($hub, $validated);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Tag created on '.$hub->name.'.',
            'tag' => $tag,
            'target_hub' => ['id' => $hub->id, 'name' => $hub->name, 'slug' => $hub->slug],
        ], 201);
    }

    public function bundles(Request $request): JsonResponse
    {
        $hub = $this->resolveActingWhiteLabel($request);

        return response()->json($this->content->listBundles(
            $hub,
            min(100, max(1, (int) $request->integer('per_page', 50)))
        ));
    }

    public function storeBundle(Request $request): JsonResponse
    {
        $hub = $this->resolveActingWhiteLabel($request);
        $this->assertActingContentCapability($request, $hub, 'dashboard_manage_bundles');

        if ($request->has('post_ids') && is_string($request->input('post_ids'))) {
            $decoded = json_decode($request->input('post_ids'), true);
            $request->merge([
                'post_ids' => (json_last_error() === JSON_ERROR_NONE && is_array($decoded))
                    ? array_values(array_map('intval', $decoded))
                    : [],
            ]);
        }
        if ($request->has('is_active') && ! is_bool($request->input('is_active'))) {
            $request->merge([
                'is_active' => filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN),
            ]);
        }

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'credits_cost' => ['required', 'integer', 'min:1'],
            'is_active' => ['sometimes', 'boolean'],
            'post_ids' => ['required', 'array', 'min:1'],
            'post_ids.*' => ['integer'],
        ]);

        try {
            $bundle = $this->content->createBundle($hub, $validated);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Bundle created on '.$hub->name.'.',
            'bundle' => $bundle,
            'target_hub' => ['id' => $hub->id, 'name' => $hub->name, 'slug' => $hub->slug],
        ], 201);
    }

    public function updatePost(Request $request, int $post): JsonResponse
    {
        $hub = $this->resolveActingWhiteLabel($request);
        $this->assertActingContentCapability($request, $hub, 'dashboard_manage_posts');

        if ($request->has('tags') && is_string($request->input('tags'))) {
            $decoded = json_decode($request->input('tags'), true);
            $request->merge([
                'tags' => (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) ? $decoded : [],
            ]);
        }
        if ($request->has('is_active') && ! is_bool($request->input('is_active'))) {
            $request->merge([
                'is_active' => filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN),
            ]);
        }

        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'type' => ['sometimes', 'string', 'max:100'],
            'category' => ['sometimes', 'string', 'max:100'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:100'],
            'credits_cost' => ['sometimes', 'integer', 'min:1'],
            'is_active' => ['sometimes', 'boolean'],
            'attachment' => ['nullable', 'file', 'max:102400'],
        ]);

        try {
            $updated = $this->content->updatePost($hub, $post, $validated, $request->file('attachment'));
        } catch (InvalidArgumentException $e) {
            $code = str_contains(strtolower($e->getMessage()), 'not found') ? 404 : 422;

            return response()->json(['message' => $e->getMessage()], $code);
        }

        return response()->json([
            'message' => 'Post updated on '.$hub->name.'.',
            'post' => $updated,
            'target_hub' => ['id' => $hub->id, 'name' => $hub->name, 'slug' => $hub->slug],
        ]);
    }

    public function destroyPost(Request $request, int $post): JsonResponse
    {
        $hub = $this->resolveActingWhiteLabel($request);
        $this->assertActingContentCapability($request, $hub, 'dashboard_manage_posts');

        try {
            $this->content->deletePost($hub, $post);
        } catch (InvalidArgumentException $e) {
            $code = str_contains(strtolower($e->getMessage()), 'not found') ? 404 : 422;

            return response()->json(['message' => $e->getMessage()], $code);
        }

        return response()->json([
            'message' => 'Post deleted on '.$hub->name.'.',
            'target_hub' => ['id' => $hub->id, 'name' => $hub->name, 'slug' => $hub->slug],
        ]);
    }

    public function updateType(Request $request, int $type): JsonResponse
    {
        $hub = $this->resolveActingWhiteLabel($request);
        $this->assertActingContentCapability($request, $hub, 'dashboard_manage_types');
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'slug' => ['nullable', 'string', 'max:100'],
        ]);

        try {
            $updated = $this->content->updateType($hub, $type, $validated);
        } catch (InvalidArgumentException $e) {
            $code = str_contains(strtolower($e->getMessage()), 'not found') ? 404 : 422;

            return response()->json(['message' => $e->getMessage()], $code);
        }

        return response()->json([
            'message' => 'Type updated on '.$hub->name.'.',
            'type' => $updated,
            'target_hub' => ['id' => $hub->id, 'name' => $hub->name, 'slug' => $hub->slug],
        ]);
    }

    public function destroyType(Request $request, int $type): JsonResponse
    {
        $hub = $this->resolveActingWhiteLabel($request);
        $this->assertActingContentCapability($request, $hub, 'dashboard_manage_types');

        try {
            $this->content->deleteType($hub, $type);
        } catch (InvalidArgumentException $e) {
            $code = str_contains(strtolower($e->getMessage()), 'not found') ? 404 : 422;

            return response()->json(['message' => $e->getMessage()], $code);
        }

        return response()->json([
            'message' => 'Type deleted on '.$hub->name.'.',
            'target_hub' => ['id' => $hub->id, 'name' => $hub->name, 'slug' => $hub->slug],
        ]);
    }

    public function updateCategory(Request $request, int $category): JsonResponse
    {
        $hub = $this->resolveActingWhiteLabel($request);
        $this->assertActingContentCapability($request, $hub, 'dashboard_manage_categories');
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'slug' => ['nullable', 'string', 'max:100'],
        ]);

        try {
            $updated = $this->content->updateCategory($hub, $category, $validated);
        } catch (InvalidArgumentException $e) {
            $code = str_contains(strtolower($e->getMessage()), 'not found') ? 404 : 422;

            return response()->json(['message' => $e->getMessage()], $code);
        }

        return response()->json([
            'message' => 'Category updated on '.$hub->name.'.',
            'category' => $updated,
            'target_hub' => ['id' => $hub->id, 'name' => $hub->name, 'slug' => $hub->slug],
        ]);
    }

    public function destroyCategory(Request $request, int $category): JsonResponse
    {
        $hub = $this->resolveActingWhiteLabel($request);
        $this->assertActingContentCapability($request, $hub, 'dashboard_manage_categories');

        try {
            $this->content->deleteCategory($hub, $category);
        } catch (InvalidArgumentException $e) {
            $code = str_contains(strtolower($e->getMessage()), 'not found') ? 404 : 422;

            return response()->json(['message' => $e->getMessage()], $code);
        }

        return response()->json([
            'message' => 'Category deleted on '.$hub->name.'.',
            'target_hub' => ['id' => $hub->id, 'name' => $hub->name, 'slug' => $hub->slug],
        ]);
    }

    public function updateTag(Request $request, int $tag): JsonResponse
    {
        $hub = $this->resolveActingWhiteLabel($request);
        $this->assertActingContentCapability($request, $hub, 'dashboard_manage_tags');
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
        ]);

        try {
            $updated = $this->content->updateTag($hub, $tag, $validated);
        } catch (InvalidArgumentException $e) {
            $code = str_contains(strtolower($e->getMessage()), 'not found') ? 404 : 422;

            return response()->json(['message' => $e->getMessage()], $code);
        }

        return response()->json([
            'message' => 'Tag updated on '.$hub->name.'.',
            'tag' => $updated,
            'target_hub' => ['id' => $hub->id, 'name' => $hub->name, 'slug' => $hub->slug],
        ]);
    }

    public function destroyTag(Request $request, int $tag): JsonResponse
    {
        $hub = $this->resolveActingWhiteLabel($request);
        $this->assertActingContentCapability($request, $hub, 'dashboard_manage_tags');

        try {
            $this->content->deleteTag($hub, $tag);
        } catch (InvalidArgumentException $e) {
            $code = str_contains(strtolower($e->getMessage()), 'not found') ? 404 : 422;

            return response()->json(['message' => $e->getMessage()], $code);
        }

        return response()->json([
            'message' => 'Tag deleted on '.$hub->name.'.',
            'target_hub' => ['id' => $hub->id, 'name' => $hub->name, 'slug' => $hub->slug],
        ]);
    }

    public function updateBundle(Request $request, int $bundle): JsonResponse
    {
        $hub = $this->resolveActingWhiteLabel($request);
        $this->assertActingContentCapability($request, $hub, 'dashboard_manage_bundles');

        if ($request->has('post_ids') && is_string($request->input('post_ids'))) {
            $decoded = json_decode($request->input('post_ids'), true);
            $request->merge([
                'post_ids' => (json_last_error() === JSON_ERROR_NONE && is_array($decoded))
                    ? array_values(array_map('intval', $decoded))
                    : [],
            ]);
        }
        if ($request->has('is_active') && ! is_bool($request->input('is_active'))) {
            $request->merge([
                'is_active' => filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN),
            ]);
        }

        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'credits_cost' => ['sometimes', 'integer', 'min:1'],
            'is_active' => ['sometimes', 'boolean'],
            'post_ids' => ['sometimes', 'array', 'min:1'],
            'post_ids.*' => ['integer'],
        ]);

        try {
            $updated = $this->content->updateBundle($hub, $bundle, $validated);
        } catch (InvalidArgumentException $e) {
            $code = str_contains(strtolower($e->getMessage()), 'not found') ? 404 : 422;

            return response()->json(['message' => $e->getMessage()], $code);
        }

        return response()->json([
            'message' => 'Bundle updated on '.$hub->name.'.',
            'bundle' => $updated,
            'target_hub' => ['id' => $hub->id, 'name' => $hub->name, 'slug' => $hub->slug],
        ]);
    }

    public function destroyBundle(Request $request, int $bundle): JsonResponse
    {
        $hub = $this->resolveActingWhiteLabel($request);
        $this->assertActingContentCapability($request, $hub, 'dashboard_manage_bundles');

        try {
            $this->content->deleteBundle($hub, $bundle);
        } catch (InvalidArgumentException $e) {
            $code = str_contains(strtolower($e->getMessage()), 'not found') ? 404 : 422;

            return response()->json(['message' => $e->getMessage()], $code);
        }

        return response()->json([
            'message' => 'Bundle deleted on '.$hub->name.'.',
            'target_hub' => ['id' => $hub->id, 'name' => $hub->name, 'slug' => $hub->slug],
        ]);
    }

    private function resolveActingWhiteLabel(Request $request): Hub
    {
        $this->assertCanControl($request);

        try {
            return $this->actingHubs->requireActingWhiteLabel($request->user());
        } catch (InvalidArgumentException $e) {
            throw new HttpException(422, $e->getMessage());
        }
    }

    private function assertActingContentCapability(Request $request, Hub $hub, string $capability): void
    {
        $user = $request->user();
        if (! $user || ! $this->matrix->roleCan($hub, (string) $user->role, $capability)) {
            throw new HttpException(
                403,
                'This capability is disabled for your role on '.$hub->name.'.'
            );
        }
    }

    private function assertCanControl(Request $request): void
    {
        $current = $this->hubs->current();
        if (! $current->isShared()) {
            throw new HttpException(403, 'Controlling white-label hubs is only available from the shared hub.');
        }

        $user = $request->user();
        if (! $user || ! $this->actingHubs->canControl($user)) {
            throw new HttpException(
                403,
                'Enable “Control white labelled hubs” in Capabilities to use this.'
            );
        }
    }
}
