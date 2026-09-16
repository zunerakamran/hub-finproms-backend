<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\CreatesOnActingWhiteLabelHub;
use App\Models\Category;
use App\Models\ContentType;
use App\Models\Post;
use App\Models\Setting;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class PostController extends Controller
{
    use CreatesOnActingWhiteLabelHub;
    public function index(Request $request): JsonResponse
    {
        if ($hub = $this->actingWhiteLabelHub($request)) {
            $listed = $this->whiteLabelContent()->listPosts(
                $hub,
                min(100, max(1, (int) $request->integer('per_page', 50)))
            );

            return response()->json([
                'data' => $listed['data'],
                'can_view_catalog' => true,
                'new_banner_days' => Setting::newBannerDays(),
                'visible_metrics' => [],
                'target_hub' => $this->targetHubPayload($hub),
                'acting_on_white_label' => true,
            ]);
        }

        $user = $this->optionalUser($request);
        $metricVisibility = $this->metricVisibilityFor($user);

        $query = Post::query()
            ->with('creator:id,name')
            ->where('is_active', true)
            ->latest('updated_at');

        if ($request->filled('category')) {
            $query->where('category', $request->string('category'));
        }

        if ($request->filled('type')) {
            $type = $request->string('type')->toString();
            $typeNames = ContentType::query()
                ->where(function ($builder) use ($type) {
                    $builder->where('slug', $type)->orWhere('name', $type);
                })
                ->pluck('name');

            if ($typeNames->isNotEmpty()) {
                $query->whereIn('type', $typeNames);
            } else {
                $query->where('type', $type);
            }
        }

        if ($request->filled('tag')) {
            $tag = $request->string('tag')->toString();
            $query->whereJsonContains('tags', $tag);
        }

        if ($request->filled('search')) {
            $search = $request->string('search')->toString();
            $query->where(function ($builder) use ($search) {
                $builder->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $posts = $query->paginate((int) $request->integer('per_page', 12));

        $purchasedIds = [];
        if ($user) {
            $purchasedIds = $user
                ->purchases()
                ->whereIn('post_id', $posts->getCollection()->pluck('id'))
                ->pluck('post_id')
                ->all();
        }

        $isClientAdmin = $user?->isClientAdmin() ?? false;
        $isAuthenticated = $user !== null;
        $canViewCatalog = $isAuthenticated;

        $posts->getCollection()->transform(function (Post $post) use ($purchasedIds, $isClientAdmin, $isAuthenticated, $metricVisibility) {
            $purchased = in_array($post->id, $purchasedIds, true);

            $this->applyPostVisibility($post, $purchased, $isClientAdmin, $isAuthenticated);
            $this->applyMetricVisibility($post, $metricVisibility);

            return $post;
        });

        return response()->json(array_merge($posts->toArray(), [
            'can_view_catalog' => $canViewCatalog,
            'new_banner_days' => Setting::newBannerDays(),
            'visible_metrics' => $metricVisibility,
        ]));
    }

    /**
     * Record reach when posts appear during listing scroll.
     * Accepts one or many post IDs; each viewer is counted once per post.
     */
    public function recordReach(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'post_ids' => ['required', 'array', 'min:1', 'max:50'],
            'post_ids.*' => ['integer', 'distinct', 'exists:posts,id'],
        ]);

        $user = $this->optionalUser($request);
        $viewerKey = $this->viewerKey($request, $user);

        $posts = Post::query()
            ->whereIn('id', $validated['post_ids'])
            ->where('is_active', true)
            ->get();

        $reached = [];
        foreach ($posts as $post) {
            if ($post->recordReach($viewerKey)) {
                $reached[] = $post->id;
            }
        }

        return response()->json([
            'message' => 'Reach recorded.',
            'reached_post_ids' => $reached,
            'processed' => $posts->pluck('id')->values(),
        ]);
    }

    public function show(Request $request, Post $post): JsonResponse
    {
        $user = $this->optionalUser($request);
        $metricVisibility = $this->metricVisibilityFor($user);

        if (! $post->is_active && ! $user?->isClientAdmin()) {
            return response()->json(['message' => 'Post not found.'], 404);
        }

        $post->recordView();
        $post->refresh();

        $post->load('creator:id,name');
        $isPurchased = $user ? $user->hasPurchased($post) : false;
        $isClientAdmin = $user?->isClientAdmin() ?? false;
        $isAuthenticated = $user !== null;
        $canViewCatalog = $isAuthenticated;

        $this->applyPostVisibility($post, $isPurchased, $isClientAdmin, $isAuthenticated);
        $this->applyMetricVisibility($post, $metricVisibility);

        return response()->json([
            'post' => $post,
            'can_view_catalog' => $canViewCatalog,
            'new_banner_days' => Setting::newBannerDays(),
            'visible_metrics' => $metricVisibility,
            'payment_methods' => app(\App\Services\HubService::class)->can('one_off_purchase')
                ? app(\App\Services\ContentPurchaseCheckoutService::class)->availablePaymentMethods()
                : [],
            'one_off_purchase' => app(\App\Services\HubService::class)->can('one_off_purchase'),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if ($hub = $this->actingWhiteLabelHub($request)) {
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

            return $this->createPostOnActingHub($request, $hub, $validated);
        }

        $validated = $this->validatePost($request);

        $attachment = $this->storeAttachment($request);

        $post = Post::create([
            'created_by' => $request->user()->id,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'type' => $validated['type'],
            'category' => $validated['category'],
            'tags' => $this->normalizeTags($validated['tags'] ?? []),
            'credits_cost' => $validated['credits_cost'],
            'attachment_path' => $attachment['path'] ?? null,
            'attachment_name' => $attachment['name'] ?? null,
            'attachment_mime' => $attachment['mime'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return response()->json([
            'message' => 'Post created successfully.',
            'post' => $post->load('creator:id,name'),
        ], 201);
    }

    public function update(Request $request, int $post): JsonResponse
    {
        if ($hub = $this->actingWhiteLabelHub($request)) {
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

            return $this->updatePostOnActingHub($request, $hub, $post, $validated);
        }

        $model = Post::query()->findOrFail($post);
        $validated = $this->validatePost($request, updating: true);

        if ($request->hasFile('attachment')) {
            if ($model->attachment_path) {
                Storage::disk('public')->delete($model->attachment_path);
            }

            $attachment = $this->storeAttachment($request);
            $model->attachment_path = $attachment['path'];
            $model->attachment_name = $attachment['name'];
            $model->attachment_mime = $attachment['mime'];
        }

        $model->fill([
            'title' => $validated['title'] ?? $model->title,
            'description' => array_key_exists('description', $validated) ? $validated['description'] : $model->description,
            'type' => $validated['type'] ?? $model->type,
            'category' => $validated['category'] ?? $model->category,
            'tags' => array_key_exists('tags', $validated) ? $this->normalizeTags($validated['tags']) : $model->tags,
            'credits_cost' => $validated['credits_cost'] ?? $model->credits_cost,
            'is_active' => array_key_exists('is_active', $validated) ? $validated['is_active'] : $model->is_active,
        ]);

        $model->save();

        return response()->json([
            'message' => 'Post updated successfully.',
            'post' => $model->fresh()->load('creator:id,name'),
        ]);
    }

    public function destroy(Request $request, int $post): JsonResponse
    {
        if ($hub = $this->actingWhiteLabelHub($request)) {
            return $this->deletePostOnActingHub($hub, $post);
        }

        $model = Post::query()->findOrFail($post);

        if ($model->attachment_path) {
            Storage::disk('public')->delete($model->attachment_path);
        }

        $model->delete();

        return response()->json([
            'message' => 'Post deleted successfully.',
        ]);
    }

    public function categories(): JsonResponse
    {
        $managed = Category::query()->orderBy('name')->pluck('name');

        if ($managed->isNotEmpty()) {
            return response()->json([
                'categories' => $managed,
            ]);
        }

        $categories = Post::query()
            ->where('is_active', true)
            ->distinct()
            ->orderBy('category')
            ->pluck('category');

        return response()->json([
            'categories' => $categories,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePost(Request $request, bool $updating = false): array
    {
        $required = $updating ? 'sometimes' : 'required';

        $rules = [
            'title' => [$required, 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'type' => [$required, 'string', 'max:100', Rule::exists('content_types', 'name')],
            'category' => [$required, 'string', 'max:100', Rule::exists('categories', 'name')],
            'tags' => ['nullable'],
            'credits_cost' => [$required, 'integer', 'min:1'],
            'is_active' => ['sometimes', 'boolean'],
            'attachment' => [
                $updating ? 'sometimes' : 'nullable',
                'file',
                'max:102400',
                'mimes:jpg,jpeg,png,gif,webp,pdf,doc,docx,mp4,mov,webm,zip',
            ],
        ];

        $validated = $request->validate($rules);

        if (array_key_exists('tags', $validated) || $request->has('tags')) {
            $normalizedTags = $this->normalizeTags($validated['tags'] ?? $request->input('tags'));
            $allowedTags = Tag::query()->pluck('name')->all();
            $invalid = array_values(array_diff($normalizedTags, $allowedTags));

            if ($invalid !== []) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'tags' => ['One or more tags are invalid: '.implode(', ', $invalid)],
                ]);
            }

            $validated['tags'] = $normalizedTags;
        }

        if ($request->has('is_active')) {
            $validated['is_active'] = filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN);
        }

        return $validated;
    }

    /**
     * @return array{path: string, name: string, mime: string}|array{}
     */
    private function storeAttachment(Request $request): array
    {
        if (! $request->hasFile('attachment')) {
            return [];
        }

        $file = $request->file('attachment');
        $path = $file->store('posts', 'public');

        return [
            'path' => $path,
            'name' => $file->getClientOriginalName(),
            'mime' => $file->getClientMimeType(),
        ];
    }

    /**
     * @param  mixed  $tags
     * @return list<string>
     */
    private function normalizeTags(mixed $tags): array
    {
        if (is_string($tags)) {
            $decoded = json_decode($tags, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $tags = $decoded;
            } else {
                $tags = array_map('trim', explode(',', $tags));
            }
        }

        if (! is_array($tags)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($tag) => is_string($tag) ? trim($tag) : null,
            $tags
        )));
    }

    private function optionalUser(Request $request): ?User
    {
        return $request->user() ?? Auth::guard('sanctum')->user();
    }

    private function viewerKey(Request $request, ?User $user): string
    {
        if ($user) {
            return 'user:'.$user->id;
        }

        return 'ip:'.sha1((string) $request->ip());
    }

    /**
     * @return array{reach: bool, views: bool, buys: bool}
     */
    private function metricVisibilityFor(?User $user): array
    {
        if (! $user) {
            return ['reach' => false, 'views' => false, 'buys' => false];
        }

        return $user->contentMetricVisibility();
    }

    /**
     * @param  array{reach: bool, views: bool, buys: bool}  $visibility
     */
    private function applyMetricVisibility(Post $post, array $visibility): Post
    {
        $hidden = [];

        if (! $visibility['reach']) {
            $hidden[] = 'reach_count';
        }

        if (! $visibility['views']) {
            $hidden[] = 'views_count';
        }

        if (! $visibility['buys']) {
            $hidden[] = 'buy_count';
        }

        if ($hidden !== []) {
            $post->makeHidden($hidden);
        }

        $post->setAttribute('visible_metrics', $visibility);

        return $post;
    }

    private function applyPostVisibility(
        Post $post,
        bool $purchased,
        bool $isClientAdmin,
        bool $isAuthenticated
    ): Post {
        // Locked only for guests. Logged-in users (subscribed or not) can browse and buy.
        $contentVisible = $purchased || $isClientAdmin || $isAuthenticated;
        $canAccessAttachment = $purchased || $isClientAdmin;

        $post->setAttribute('is_purchased', $purchased);
        $post->setAttribute('is_locked', ! $isAuthenticated && ! $isClientAdmin);
        $post->setAttribute('content_visible', $contentVisible);

        if (! $canAccessAttachment) {
            $post->makeHidden(['attachment_path', 'attachment_url', 'attachment_name', 'attachment_mime']);
        }

        if (! $contentVisible) {
            $post->makeHidden([
                'description',
                'tags',
                'attachment_path',
                'attachment_url',
                'attachment_name',
                'attachment_mime',
                'cover_url',
                'video_url',
            ]);
            $post->setAttribute('description', null);
            $post->setAttribute('tags', []);
            $post->setAttribute('cover_url', null);
            $post->setAttribute('video_url', null);
        } else {
            // Browse preview: image cover and reel video (download still gated above).
            $post->makeVisible(['cover_url', 'video_url']);
        }

        return $post;
    }
}
