<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\Hub;
use App\Services\ActingHubService;
use App\Services\WhiteLabelContentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * When the shared-hub hub switcher is on a white-label hub, manage content there.
 */
trait CreatesOnActingWhiteLabelHub
{
    protected function actingWhiteLabelHub(Request $request): ?Hub
    {
        $user = $request->user() ?? \Illuminate\Support\Facades\Auth::guard('sanctum')->user();
        if (! $user) {
            return null;
        }

        // Public member catalog (shared frontend) must always read the deploy hub DB.
        // Acting-hub scoping is for dashboard management of white-label content only.
        if ($this->isPublicCatalogRead($request)) {
            return null;
        }

        $acting = app(ActingHubService::class);
        if (! $acting->isActingOnWhiteLabel($user)) {
            return null;
        }

        try {
            return $acting->requireActingWhiteLabel($user);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * GET /posts, /bundles, /types, … on the public API — not under client-admin/power-admin.
     */
    protected function isPublicCatalogRead(Request $request): bool
    {
        if (! in_array(strtoupper($request->method()), ['GET', 'HEAD'], true)) {
            return false;
        }

        $path = ltrim($request->path(), '/');
        if (str_starts_with($path, 'api/')) {
            $path = substr($path, 4);
        }

        foreach (['client-admin/', 'power-admin/', 'admin/', 'hub-content/'] as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return false;
            }
        }

        return (bool) preg_match('#^(posts|bundles|types|categories|tags)(/|$)#', $path);
    }

    protected function whiteLabelContent(): WhiteLabelContentService
    {
        return app(WhiteLabelContentService::class);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function createPostOnActingHub(Request $request, Hub $hub, array $payload): JsonResponse
    {
        try {
            $post = $this->whiteLabelContent()->createPost(
                $hub,
                $payload,
                $request->file('attachment')
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Post created on '.$hub->name.' (white-label database).',
            'post' => $post,
            'target_hub' => $this->targetHubPayload($hub),
        ], 201);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function updatePostOnActingHub(Request $request, Hub $hub, int $postId, array $payload): JsonResponse
    {
        try {
            $post = $this->whiteLabelContent()->updatePost(
                $hub,
                $postId,
                $payload,
                $request->file('attachment')
            );
        } catch (InvalidArgumentException $e) {
            return $this->actingHubNotFoundOrValidation($e);
        }

        return response()->json([
            'message' => 'Post updated on '.$hub->name.'.',
            'post' => $post,
            'target_hub' => $this->targetHubPayload($hub),
        ]);
    }

    protected function deletePostOnActingHub(Hub $hub, int $postId): JsonResponse
    {
        try {
            $this->whiteLabelContent()->deletePost($hub, $postId);
        } catch (InvalidArgumentException $e) {
            return $this->actingHubNotFoundOrValidation($e);
        }

        return response()->json([
            'message' => 'Post deleted on '.$hub->name.'.',
            'target_hub' => $this->targetHubPayload($hub),
        ]);
    }

    /**
     * @param  array{name: string, slug?: ?string}  $payload
     */
    protected function createTypeOnActingHub(Hub $hub, array $payload): JsonResponse
    {
        try {
            $type = $this->whiteLabelContent()->createType($hub, $payload);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Type created on '.$hub->name.'.',
            'type' => $type,
            'target_hub' => $this->targetHubPayload($hub),
        ], 201);
    }

    /**
     * @param  array{name: string, slug?: ?string}  $payload
     */
    protected function updateTypeOnActingHub(Hub $hub, int $typeId, array $payload): JsonResponse
    {
        try {
            $type = $this->whiteLabelContent()->updateType($hub, $typeId, $payload);
        } catch (InvalidArgumentException $e) {
            return $this->actingHubNotFoundOrValidation($e);
        }

        return response()->json([
            'message' => 'Type updated on '.$hub->name.'.',
            'type' => $type,
            'target_hub' => $this->targetHubPayload($hub),
        ]);
    }

    protected function deleteTypeOnActingHub(Hub $hub, int $typeId): JsonResponse
    {
        try {
            $this->whiteLabelContent()->deleteType($hub, $typeId);
        } catch (InvalidArgumentException $e) {
            return $this->actingHubNotFoundOrValidation($e);
        }

        return response()->json([
            'message' => 'Type deleted on '.$hub->name.'.',
            'target_hub' => $this->targetHubPayload($hub),
        ]);
    }

    /**
     * @param  array{name: string, slug?: ?string}  $payload
     */
    protected function createCategoryOnActingHub(Hub $hub, array $payload): JsonResponse
    {
        try {
            $category = $this->whiteLabelContent()->createCategory($hub, $payload);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Category created on '.$hub->name.'.',
            'category' => $category,
            'target_hub' => $this->targetHubPayload($hub),
        ], 201);
    }

    /**
     * @param  array{name: string, slug?: ?string}  $payload
     */
    protected function updateCategoryOnActingHub(Hub $hub, int $categoryId, array $payload): JsonResponse
    {
        try {
            $category = $this->whiteLabelContent()->updateCategory($hub, $categoryId, $payload);
        } catch (InvalidArgumentException $e) {
            return $this->actingHubNotFoundOrValidation($e);
        }

        return response()->json([
            'message' => 'Category updated on '.$hub->name.'.',
            'category' => $category,
            'target_hub' => $this->targetHubPayload($hub),
        ]);
    }

    protected function deleteCategoryOnActingHub(Hub $hub, int $categoryId): JsonResponse
    {
        try {
            $this->whiteLabelContent()->deleteCategory($hub, $categoryId);
        } catch (InvalidArgumentException $e) {
            return $this->actingHubNotFoundOrValidation($e);
        }

        return response()->json([
            'message' => 'Category deleted on '.$hub->name.'.',
            'target_hub' => $this->targetHubPayload($hub),
        ]);
    }

    /**
     * @param  array{name: string}  $payload
     */
    protected function createTagOnActingHub(Hub $hub, array $payload): JsonResponse
    {
        try {
            $tag = $this->whiteLabelContent()->createTag($hub, $payload);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Tag created on '.$hub->name.'.',
            'tag' => $tag,
            'target_hub' => $this->targetHubPayload($hub),
        ], 201);
    }

    /**
     * @param  array{name: string}  $payload
     */
    protected function updateTagOnActingHub(Hub $hub, int $tagId, array $payload): JsonResponse
    {
        try {
            $tag = $this->whiteLabelContent()->updateTag($hub, $tagId, $payload);
        } catch (InvalidArgumentException $e) {
            return $this->actingHubNotFoundOrValidation($e);
        }

        return response()->json([
            'message' => 'Tag updated on '.$hub->name.'.',
            'tag' => $tag,
            'target_hub' => $this->targetHubPayload($hub),
        ]);
    }

    protected function deleteTagOnActingHub(Hub $hub, int $tagId): JsonResponse
    {
        try {
            $this->whiteLabelContent()->deleteTag($hub, $tagId);
        } catch (InvalidArgumentException $e) {
            return $this->actingHubNotFoundOrValidation($e);
        }

        return response()->json([
            'message' => 'Tag deleted on '.$hub->name.'.',
            'target_hub' => $this->targetHubPayload($hub),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function createBundleOnActingHub(Hub $hub, array $payload): JsonResponse
    {
        try {
            $bundle = $this->whiteLabelContent()->createBundle($hub, $payload);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Bundle created on '.$hub->name.'.',
            'bundle' => $bundle,
            'target_hub' => $this->targetHubPayload($hub),
        ], 201);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function updateBundleOnActingHub(Hub $hub, int $bundleId, array $payload): JsonResponse
    {
        try {
            $bundle = $this->whiteLabelContent()->updateBundle($hub, $bundleId, $payload);
        } catch (InvalidArgumentException $e) {
            return $this->actingHubNotFoundOrValidation($e);
        }

        return response()->json([
            'message' => 'Bundle updated on '.$hub->name.'.',
            'bundle' => $bundle,
            'target_hub' => $this->targetHubPayload($hub),
        ]);
    }

    protected function deleteBundleOnActingHub(Hub $hub, int $bundleId): JsonResponse
    {
        try {
            $this->whiteLabelContent()->deleteBundle($hub, $bundleId);
        } catch (InvalidArgumentException $e) {
            return $this->actingHubNotFoundOrValidation($e);
        }

        return response()->json([
            'message' => 'Bundle deleted on '.$hub->name.'.',
            'target_hub' => $this->targetHubPayload($hub),
        ]);
    }

    /**
     * @return array{id: int, name: string, slug: string}
     */
    protected function targetHubPayload(Hub $hub): array
    {
        return ['id' => $hub->id, 'name' => $hub->name, 'slug' => $hub->slug];
    }

    protected function actingHubNotFoundOrValidation(InvalidArgumentException $e): JsonResponse
    {
        $message = $e->getMessage();
        $notFound = str_contains(strtolower($message), 'not found');

        return response()->json(['message' => $message], $notFound ? 404 : 422);
    }

    /**
     * Resolve a route id without Eloquent binding so WL remote ids do not 404 on shared DB.
     */
    protected function resolveRouteId(Request $request, string $param, mixed $bound = null): int
    {
        if (is_object($bound) && isset($bound->id)) {
            return (int) $bound->id;
        }
        if (is_numeric($bound)) {
            return (int) $bound;
        }

        $raw = $request->route($param);
        if (is_object($raw) && isset($raw->id)) {
            return (int) $raw->id;
        }
        if (is_numeric($raw)) {
            return (int) $raw;
        }

        throw new NotFoundHttpException('Resource not found.');
    }
}
