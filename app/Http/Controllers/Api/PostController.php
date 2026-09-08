<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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
    public function index(Request $request): JsonResponse
    {
        $user = $this->optionalUser($request);

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

        $posts->getCollection()->transform(function (Post $post) use ($purchasedIds, $isClientAdmin, $isAuthenticated) {
            $purchased = in_array($post->id, $purchasedIds, true);

            return $this->applyPostVisibility($post, $purchased, $isClientAdmin, $isAuthenticated);
        });

        return response()->json(array_merge($posts->toArray(), [
            'can_view_catalog' => $canViewCatalog,
            'new_banner_days' => Setting::newBannerDays(),
        ]));
    }

    public function show(Request $request, Post $post): JsonResponse
    {
        $user = $this->optionalUser($request);

        if (! $post->is_active && ! $user?->isClientAdmin()) {
            return response()->json(['message' => 'Post not found.'], 404);
        }

        $post->recordView($this->viewerKey($request, $user));

        $post->load('creator:id,name');
        $isPurchased = $user ? $user->hasPurchased($post) : false;
        $isClientAdmin = $user?->isClientAdmin() ?? false;
        $isAuthenticated = $user !== null;
        $canViewCatalog = $isAuthenticated;

        $this->applyPostVisibility($post, $isPurchased, $isClientAdmin, $isAuthenticated);

        return response()->json([
            'post' => $post,
            'can_view_catalog' => $canViewCatalog,
            'new_banner_days' => Setting::newBannerDays(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
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

    public function update(Request $request, Post $post): JsonResponse
    {
        $validated = $this->validatePost($request, updating: true);

        if ($request->hasFile('attachment')) {
            if ($post->attachment_path) {
                Storage::disk('public')->delete($post->attachment_path);
            }

            $attachment = $this->storeAttachment($request);
            $post->attachment_path = $attachment['path'];
            $post->attachment_name = $attachment['name'];
            $post->attachment_mime = $attachment['mime'];
        }

        $post->fill([
            'title' => $validated['title'] ?? $post->title,
            'description' => array_key_exists('description', $validated) ? $validated['description'] : $post->description,
            'type' => $validated['type'] ?? $post->type,
            'category' => $validated['category'] ?? $post->category,
            'tags' => array_key_exists('tags', $validated) ? $this->normalizeTags($validated['tags']) : $post->tags,
            'credits_cost' => $validated['credits_cost'] ?? $post->credits_cost,
            'is_active' => array_key_exists('is_active', $validated) ? $validated['is_active'] : $post->is_active,
        ]);

        $post->save();

        return response()->json([
            'message' => 'Post updated successfully.',
            'post' => $post->fresh()->load('creator:id,name'),
        ]);
    }

    public function destroy(Post $post): JsonResponse
    {
        if ($post->attachment_path) {
            Storage::disk('public')->delete($post->attachment_path);
        }

        $post->delete();

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
                'max:20480',
                'mimes:jpg,jpeg,png,gif,webp,pdf,doc,docx,mp4,mov,zip',
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
            ]);
            $post->setAttribute('description', null);
            $post->setAttribute('tags', []);
            $post->setAttribute('cover_url', null);
        } else {
            $post->makeVisible(['cover_url']);
        }

        return $post;
    }
}
