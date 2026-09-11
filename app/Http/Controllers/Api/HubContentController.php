<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Hub;
use App\Services\CapabilitiesMatrixService;
use App\Services\HubService;
use App\Services\WhiteLabelContentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Create/list catalog content on a chosen hub.
 * Shared hub → normal local tables (handled by existing controllers).
 * White-label hub → that hub's own remote database only (not stored on shared).
 */
class HubContentController extends Controller
{
    public function __construct(
        private readonly WhiteLabelContentService $content,
        private readonly HubService $hubs,
        private readonly CapabilitiesMatrixService $matrix
    ) {}

    public function targets(Request $request): JsonResponse
    {
        $this->assertCanPublishToWhiteLabels($request);

        return response()->json([
            'hubs' => $this->content->targetHubsForDropdown(),
        ]);
    }

    public function posts(Request $request): JsonResponse
    {
        $hub = $this->resolveWhiteLabelHub($request);

        return response()->json($this->content->listPosts(
            $hub,
            min(100, max(1, (int) $request->integer('per_page', 50)))
        ));
    }

    public function storePost(Request $request): JsonResponse
    {
        $hub = $this->resolveWhiteLabelHub($request);

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
        $hub = $this->resolveWhiteLabelHub($request);

        return response()->json($this->content->listTypes($hub));
    }

    public function storeType(Request $request): JsonResponse
    {
        $hub = $this->resolveWhiteLabelHub($request);
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
        ], 201);
    }

    public function categories(Request $request): JsonResponse
    {
        $hub = $this->resolveWhiteLabelHub($request);

        return response()->json($this->content->listCategories($hub));
    }

    public function storeCategory(Request $request): JsonResponse
    {
        $hub = $this->resolveWhiteLabelHub($request);
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
        ], 201);
    }

    public function tags(Request $request): JsonResponse
    {
        $hub = $this->resolveWhiteLabelHub($request);

        return response()->json($this->content->listTags($hub));
    }

    public function storeTag(Request $request): JsonResponse
    {
        $hub = $this->resolveWhiteLabelHub($request);
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
        ], 201);
    }

    public function bundles(Request $request): JsonResponse
    {
        $hub = $this->resolveWhiteLabelHub($request);

        return response()->json($this->content->listBundles(
            $hub,
            min(100, max(1, (int) $request->integer('per_page', 50)))
        ));
    }

    public function storeBundle(Request $request): JsonResponse
    {
        $hub = $this->resolveWhiteLabelHub($request);

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
        ], 201);
    }

    private function resolveWhiteLabelHub(Request $request): Hub
    {
        $this->assertCanPublishToWhiteLabels($request);

        $validated = $request->validate([
            'hub_id' => ['required', 'integer', 'exists:hubs,id'],
        ]);

        $hub = Hub::query()->findOrFail($validated['hub_id']);
        if ($hub->isShared()) {
            throw new HttpException(422, 'Use the normal create APIs for the shared hub.');
        }

        try {
            $this->content->assertTarget($hub);
        } catch (InvalidArgumentException $e) {
            throw new HttpException(422, $e->getMessage());
        }

        return $hub;
    }

    private function assertCanPublishToWhiteLabels(Request $request): void
    {
        $current = $this->hubs->current();
        if (! $current->isShared()) {
            throw new HttpException(403, 'Publishing to white-label hubs is only available from the shared hub.');
        }

        $user = $request->user();
        if (! $user || ! $this->matrix->roleCan($current, (string) $user->role, 'dashboard_push_content')) {
            throw new HttpException(
                403,
                'Enable “Push added content to white-labelled hubs” in Capabilities to use this.'
            );
        }
    }
}
