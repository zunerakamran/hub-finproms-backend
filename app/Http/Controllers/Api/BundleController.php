<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bundle;
use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BundleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $this->optionalUser($request);
        $isAdmin = $user?->isClientAdmin() ?? false;

        $query = Bundle::query()
            ->with(['creator:id,name'])
            ->withCount('posts')
            ->latest('updated_at');

        if (! $isAdmin) {
            $query->where('is_active', true);
        }

        if ($request->filled('search')) {
            $search = $request->string('search')->toString();
            $query->where(function ($builder) use ($search) {
                $builder->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $bundles = $query->paginate((int) $request->integer('per_page', 12));

        $purchasedIds = [];
        if ($user) {
            $purchasedIds = $user
                ->bundlePurchases()
                ->whereIn('bundle_id', $bundles->getCollection()->pluck('id'))
                ->pluck('bundle_id')
                ->all();
        }

        $bundles->getCollection()->transform(function (Bundle $bundle) use ($purchasedIds) {
            $bundle->setAttribute('is_purchased', in_array($bundle->id, $purchasedIds, true));

            return $bundle;
        });

        return response()->json($bundles);
    }

    public function show(Request $request, Bundle $bundle): JsonResponse
    {
        $user = $this->optionalUser($request);
        $isAdmin = $user?->isClientAdmin() ?? false;

        if (! $bundle->is_active && ! $isAdmin) {
            return response()->json(['message' => 'Bundle not found.'], 404);
        }

        $bundle->load(['creator:id,name', 'posts.creator:id,name']);
        $bundle->loadCount('posts');

        $isPurchased = $user ? $user->hasPurchasedBundle($bundle) : false;
        $bundle->setAttribute('is_purchased', $isPurchased);

        $bundle->posts->each(function (Post $post) use ($user, $isPurchased, $isAdmin) {
            $postPurchased = $isPurchased || ($user?->hasPurchased($post) ?? false);
            $post->setAttribute('is_purchased', $postPurchased);
            if (! $postPurchased && ! $isAdmin) {
                $post->makeHidden(['attachment_path', 'attachment_url', 'attachment_name', 'attachment_mime']);
            }
        });

        return response()->json([
            'bundle' => $bundle,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validateBundle($request);
        $postIds = $this->resolvePostIds($request, $validated);

        $bundle = DB::transaction(function () use ($request, $validated, $postIds) {
            $createdPostIds = $this->createInlinePosts($request, $request->user());
            $allPostIds = array_values(array_unique(array_merge($postIds, $createdPostIds)));

            if ($allPostIds === []) {
                throw ValidationException::withMessages([
                    'post_ids' => ['Add at least one existing post or create a new post for the bundle.'],
                ]);
            }

            $bundle = Bundle::create([
                'created_by' => $request->user()->id,
                'title' => $validated['title'],
                'description' => $validated['description'] ?? null,
                'credits_cost' => $validated['credits_cost'],
                'is_active' => $validated['is_active'] ?? true,
            ]);

            $bundle->syncOrderedPosts($allPostIds);

            return $bundle;
        });

        return response()->json([
            'message' => 'Bundle created successfully.',
            'bundle' => $bundle->fresh()->load(['creator:id,name', 'posts.creator:id,name'])->loadCount('posts'),
        ], 201);
    }

    public function update(Request $request, Bundle $bundle): JsonResponse
    {
        $validated = $this->validateBundle($request, updating: true);

        DB::transaction(function () use ($request, $bundle, $validated) {
            $bundle->fill([
                'title' => $validated['title'] ?? $bundle->title,
                'description' => array_key_exists('description', $validated)
                    ? $validated['description']
                    : $bundle->description,
                'credits_cost' => $validated['credits_cost'] ?? $bundle->credits_cost,
                'is_active' => array_key_exists('is_active', $validated)
                    ? $validated['is_active']
                    : $bundle->is_active,
            ]);
            $bundle->save();

            $hasPostIds = $request->has('post_ids') || array_key_exists('post_ids', $validated);
            $hasNewPosts = $request->has('new_posts');

            if ($hasPostIds || $hasNewPosts) {
                $postIds = $hasPostIds
                    ? $this->resolvePostIds($request, $validated)
                    : $bundle->posts()->pluck('posts.id')->all();
                $createdPostIds = $this->createInlinePosts($request, $request->user());
                $allPostIds = array_values(array_unique(array_merge($postIds, $createdPostIds)));

                if ($allPostIds === []) {
                    throw ValidationException::withMessages([
                        'post_ids' => ['A bundle must include at least one post.'],
                    ]);
                }

                $bundle->syncOrderedPosts($allPostIds);
            }
        });

        return response()->json([
            'message' => 'Bundle updated successfully.',
            'bundle' => $bundle->fresh()->load(['creator:id,name', 'posts.creator:id,name'])->loadCount('posts'),
        ]);
    }

    public function destroy(Bundle $bundle): JsonResponse
    {
        $bundle->delete();

        return response()->json([
            'message' => 'Bundle deleted successfully.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateBundle(Request $request, bool $updating = false): array
    {
        $required = $updating ? 'sometimes' : 'required';

        // FormData sends JSON-encoded arrays as strings — normalize before validate.
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
            'title' => [$required, 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'credits_cost' => [$required, 'integer', 'min:1'],
            'is_active' => ['sometimes', 'boolean'],
            'post_ids' => ['nullable', 'array'],
            'post_ids.*' => ['integer', 'distinct', 'exists:posts,id'],
            'new_posts' => ['nullable', 'array'],
            'new_posts.*.title' => ['required_with:new_posts', 'string', 'max:255'],
            'new_posts.*.description' => ['nullable', 'string'],
            'new_posts.*.type' => ['required_with:new_posts', 'string', 'max:100', Rule::exists('content_types', 'name')],
            'new_posts.*.category' => ['required_with:new_posts', 'string', 'max:100', Rule::exists('categories', 'name')],
            'new_posts.*.tags' => ['nullable'],
            'new_posts.*.credits_cost' => ['nullable', 'integer', 'min:1'],
            'new_posts.*.is_active' => ['sometimes', 'boolean'],
            'new_posts.*.attachment' => [
                'nullable',
                'file',
                'max:102400',
                'mimes:jpg,jpeg,png,gif,webp,pdf,doc,docx,mp4,mov,webm,zip',
            ],
        ]);

        return $validated;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return list<int>
     */
    private function resolvePostIds(Request $request, array $validated): array
    {
        $postIds = $validated['post_ids'] ?? $request->input('post_ids', []);

        if (is_string($postIds)) {
            $decoded = json_decode($postIds, true);
            $postIds = json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : [];
        }

        if (! is_array($postIds)) {
            return [];
        }

        return array_values(array_unique(array_map('intval', $postIds)));
    }

    /**
     * Create posts submitted inline with the bundle form (new_posts[]).
     *
     * @return list<int>
     */
    private function createInlinePosts(Request $request, User $user): array
    {
        $rows = $request->input('new_posts', []);
        if (! is_array($rows) || $rows === []) {
            return [];
        }

        $createdIds = [];
        $allowedTags = Tag::query()->pluck('name')->all();

        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $tags = $this->normalizeTags($row['tags'] ?? []);
            $invalid = array_values(array_diff($tags, $allowedTags));
            if ($invalid !== []) {
                throw ValidationException::withMessages([
                    "new_posts.{$index}.tags" => ['One or more tags are invalid: '.implode(', ', $invalid)],
                ]);
            }

            $attachment = $this->storeNewPostAttachment($request, $index);

            $post = Post::create([
                'created_by' => $user->id,
                'title' => $row['title'],
                'description' => $row['description'] ?? null,
                'type' => $row['type'],
                'category' => $row['category'],
                'tags' => $tags,
                'credits_cost' => (int) ($row['credits_cost'] ?? 1),
                'attachment_path' => $attachment['path'] ?? null,
                'attachment_name' => $attachment['name'] ?? null,
                'attachment_mime' => $attachment['mime'] ?? null,
                'is_active' => array_key_exists('is_active', $row)
                    ? filter_var($row['is_active'], FILTER_VALIDATE_BOOLEAN)
                    : true,
            ]);

            $createdIds[] = $post->id;
        }

        return $createdIds;
    }

    /**
     * @return array{path: string, name: string, mime: string}|array{}
     */
    private function storeNewPostAttachment(Request $request, int $index): array
    {
        $file = $request->file("new_posts.{$index}.attachment");
        if (! $file) {
            return [];
        }

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
}
