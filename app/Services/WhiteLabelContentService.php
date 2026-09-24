<?php

namespace App\Services;

use App\Models\Hub;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Read/write catalog content directly on a white-label hub's own database.
 * These items are NOT stored on the shared hub.
 */
class WhiteLabelContentService
{
    public function __construct(
        private readonly WhiteLabelDatabaseService $remoteDb,
        private readonly HubService $hubs
    ) {}

    public function assertTarget(Hub $hub): void
    {
        if ($hub->isShared()) {
            throw new InvalidArgumentException('Select a white-label hub, not the shared hub.');
        }
        if (! $hub->is_active) {
            throw new InvalidArgumentException('That white-label hub is inactive.');
        }
        if (! $hub->can('receive_content_from_shared')) {
            throw new InvalidArgumentException('That hub does not allow content from the shared hub.');
        }
        $this->remoteDb->assertConfigured($hub);
    }

    /**
     * @return list<array<string, mixed>>
     * @deprecated Use ActingHubService::switcherHubs()
     */
    public function targetHubsForDropdown(): array
    {
        return app(ActingHubService::class)->switcherHubs();
    }

    /**
     * @return array{data: list<array<string, mixed>>}
     */
    public function listPosts(Hub $hub, int $perPage = 50): array
    {
        $connection = $this->connect($hub);
        try {
            $rows = DB::connection($connection)->table('posts')
                ->orderByDesc('updated_at')
                ->limit($perPage)
                ->get();

            return [
                'data' => $rows->map(fn ($row) => $this->mapPostRow($row))->all(),
            ];
        } finally {
            $this->remoteDb->disconnect($hub);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createPost(Hub $hub, array $payload, ?UploadedFile $attachment = null): array
    {
        $connection = $this->connect($hub);
        try {
            $this->ensureNamedType($connection, (string) $payload['type']);
            $this->ensureNamedCategory($connection, (string) $payload['category']);
            foreach ($payload['tags'] ?? [] as $tagName) {
                $this->ensureNamedTag($connection, (string) $tagName);
            }

            $attachmentMeta = $this->storeAttachmentAsPublicUrl($attachment);
            $now = now();
            $id = (int) DB::connection($connection)->table('posts')->insertGetId([
                'created_by' => $this->resolveRemoteCreatorId($connection),
                'title' => $payload['title'],
                'description' => $payload['description'] ?? null,
                'type' => $payload['type'],
                'category' => $payload['category'],
                'tags' => json_encode(array_values($payload['tags'] ?? [])),
                'credits_cost' => (int) $payload['credits_cost'],
                'attachment_path' => $attachmentMeta['path'] ?? null,
                'attachment_name' => $attachmentMeta['name'] ?? null,
                'attachment_mime' => $attachmentMeta['mime'] ?? null,
                'is_active' => (bool) ($payload['is_active'] ?? true),
                'views_count' => 0,
                'reach_count' => 0,
                'buy_count' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $row = DB::connection($connection)->table('posts')->where('id', $id)->first();

            return $this->mapPostRow($row);
        } finally {
            $this->remoteDb->disconnect($hub);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function findPost(Hub $hub, int $postId): array
    {
        $connection = $this->connect($hub);
        try {
            $row = DB::connection($connection)->table('posts')->where('id', $postId)->first();
            if (! $row) {
                throw new InvalidArgumentException('Post not found on this white-label hub.');
            }

            return $this->mapPostRow($row);
        } finally {
            $this->remoteDb->disconnect($hub);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function updatePost(Hub $hub, int $postId, array $payload, ?UploadedFile $attachment = null): array
    {
        $connection = $this->connect($hub);
        try {
            $row = DB::connection($connection)->table('posts')->where('id', $postId)->first();
            if (! $row) {
                throw new InvalidArgumentException('Post not found on this white-label hub.');
            }

            $updates = ['updated_at' => now()];
            foreach (['title', 'description', 'type', 'category'] as $field) {
                if (array_key_exists($field, $payload)) {
                    $updates[$field] = $payload[$field];
                }
            }
            if (array_key_exists('tags', $payload)) {
                $updates['tags'] = json_encode(array_values($payload['tags'] ?? []));
            }
            if (array_key_exists('credits_cost', $payload)) {
                $updates['credits_cost'] = (int) $payload['credits_cost'];
            }
            if (array_key_exists('is_active', $payload)) {
                $updates['is_active'] = (bool) $payload['is_active'];
            }

            if (isset($updates['type'])) {
                $this->ensureNamedType($connection, (string) $updates['type']);
            }
            if (isset($updates['category'])) {
                $this->ensureNamedCategory($connection, (string) $updates['category']);
            }
            foreach ($payload['tags'] ?? [] as $tagName) {
                $this->ensureNamedTag($connection, (string) $tagName);
            }

            if ($attachment) {
                $attachmentMeta = $this->storeAttachmentAsPublicUrl($attachment);
                $updates['attachment_path'] = $attachmentMeta['path'] ?? null;
                $updates['attachment_name'] = $attachmentMeta['name'] ?? null;
                $updates['attachment_mime'] = $attachmentMeta['mime'] ?? null;
            }

            DB::connection($connection)->table('posts')->where('id', $postId)->update($updates);
            $fresh = DB::connection($connection)->table('posts')->where('id', $postId)->first();

            return $this->mapPostRow($fresh);
        } finally {
            $this->remoteDb->disconnect($hub);
        }
    }

    public function deletePost(Hub $hub, int $postId): void
    {
        $connection = $this->connect($hub);
        try {
            $row = DB::connection($connection)->table('posts')->where('id', $postId)->first();
            if (! $row) {
                throw new InvalidArgumentException('Post not found on this white-label hub.');
            }

            DB::connection($connection)->table('bundle_post')->where('post_id', $postId)->delete();
            DB::connection($connection)->table('posts')->where('id', $postId)->delete();
        } finally {
            $this->remoteDb->disconnect($hub);
        }
    }

    /**
     * @return array{types: list<array<string, mixed>>}
     */
    public function listTypes(Hub $hub): array
    {
        $connection = $this->connect($hub);
        try {
            $rows = DB::connection($connection)->table('content_types')->orderBy('name')->get();

            return [
                'types' => $rows->map(fn ($r) => [
                    'id' => $r->id,
                    'name' => $r->name,
                    'slug' => $r->slug,
                    'posts_count' => 0,
                ])->all(),
            ];
        } finally {
            $this->remoteDb->disconnect($hub);
        }
    }

    /**
     * @param  array{name: string, slug?: ?string}  $payload
     * @return array<string, mixed>
     */
    public function createType(Hub $hub, array $payload): array
    {
        $connection = $this->connect($hub);
        try {
            $name = trim($payload['name']);
            $slug = trim((string) ($payload['slug'] ?? '')) ?: Str::slug($name);
            $existing = DB::connection($connection)->table('content_types')->where('name', $name)->first();
            if ($existing) {
                throw new InvalidArgumentException('A type with that name already exists on this hub.');
            }
            $now = now();
            $id = (int) DB::connection($connection)->table('content_types')->insertGetId([
                'name' => $name,
                'slug' => $slug,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return ['id' => $id, 'name' => $name, 'slug' => $slug];
        } finally {
            $this->remoteDb->disconnect($hub);
        }
    }

    /**
     * @param  array{name: string, slug?: ?string}  $payload
     * @return array<string, mixed>
     */
    public function updateType(Hub $hub, int $typeId, array $payload): array
    {
        $connection = $this->connect($hub);
        try {
            $row = DB::connection($connection)->table('content_types')->where('id', $typeId)->first();
            if (! $row) {
                throw new InvalidArgumentException('Type not found on this white-label hub.');
            }

            $oldName = (string) $row->name;
            $newName = trim($payload['name']);
            $slug = trim((string) ($payload['slug'] ?? '')) ?: Str::slug($newName);

            $dup = DB::connection($connection)->table('content_types')
                ->where('name', $newName)
                ->where('id', '!=', $typeId)
                ->exists();
            if ($dup) {
                throw new InvalidArgumentException('A type with that name already exists on this hub.');
            }

            DB::connection($connection)->table('content_types')->where('id', $typeId)->update([
                'name' => $newName,
                'slug' => $slug,
                'updated_at' => now(),
            ]);

            if ($oldName !== $newName) {
                DB::connection($connection)->table('posts')
                    ->where('type', $oldName)
                    ->update(['type' => $newName, 'updated_at' => now()]);
            }

            return ['id' => $typeId, 'name' => $newName, 'slug' => $slug];
        } finally {
            $this->remoteDb->disconnect($hub);
        }
    }

    public function deleteType(Hub $hub, int $typeId): void
    {
        $connection = $this->connect($hub);
        try {
            $row = DB::connection($connection)->table('content_types')->where('id', $typeId)->first();
            if (! $row) {
                throw new InvalidArgumentException('Type not found on this white-label hub.');
            }
            if (DB::connection($connection)->table('posts')->where('type', $row->name)->exists()) {
                throw new InvalidArgumentException('Cannot delete a content type that is used by posts.');
            }
            DB::connection($connection)->table('content_types')->where('id', $typeId)->delete();
        } finally {
            $this->remoteDb->disconnect($hub);
        }
    }

    /**
     * @return array{categories: list<array<string, mixed>>}
     */
    public function listCategories(Hub $hub): array
    {
        $connection = $this->connect($hub);
        try {
            $rows = DB::connection($connection)->table('categories')->orderBy('name')->get();

            return [
                'categories' => $rows->map(fn ($r) => [
                    'id' => $r->id,
                    'name' => $r->name,
                    'slug' => $r->slug,
                    'posts_count' => 0,
                ])->all(),
            ];
        } finally {
            $this->remoteDb->disconnect($hub);
        }
    }

    /**
     * @param  array{name: string, slug?: ?string}  $payload
     * @return array<string, mixed>
     */
    public function createCategory(Hub $hub, array $payload): array
    {
        $connection = $this->connect($hub);
        try {
            $name = trim($payload['name']);
            $slug = trim((string) ($payload['slug'] ?? '')) ?: Str::slug($name);
            if (DB::connection($connection)->table('categories')->where('name', $name)->exists()) {
                throw new InvalidArgumentException('A category with that name already exists on this hub.');
            }
            $now = now();
            $id = (int) DB::connection($connection)->table('categories')->insertGetId([
                'name' => $name,
                'slug' => $slug,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return ['id' => $id, 'name' => $name, 'slug' => $slug];
        } finally {
            $this->remoteDb->disconnect($hub);
        }
    }

    /**
     * @param  array{name: string, slug?: ?string}  $payload
     * @return array<string, mixed>
     */
    public function updateCategory(Hub $hub, int $categoryId, array $payload): array
    {
        $connection = $this->connect($hub);
        try {
            $row = DB::connection($connection)->table('categories')->where('id', $categoryId)->first();
            if (! $row) {
                throw new InvalidArgumentException('Category not found on this white-label hub.');
            }

            $oldName = (string) $row->name;
            $newName = trim($payload['name']);
            $slug = trim((string) ($payload['slug'] ?? '')) ?: Str::slug($newName);

            $dup = DB::connection($connection)->table('categories')
                ->where('name', $newName)
                ->where('id', '!=', $categoryId)
                ->exists();
            if ($dup) {
                throw new InvalidArgumentException('A category with that name already exists on this hub.');
            }

            DB::connection($connection)->table('categories')->where('id', $categoryId)->update([
                'name' => $newName,
                'slug' => $slug,
                'updated_at' => now(),
            ]);

            if ($oldName !== $newName) {
                DB::connection($connection)->table('posts')
                    ->where('category', $oldName)
                    ->update(['category' => $newName, 'updated_at' => now()]);
            }

            return ['id' => $categoryId, 'name' => $newName, 'slug' => $slug];
        } finally {
            $this->remoteDb->disconnect($hub);
        }
    }

    public function deleteCategory(Hub $hub, int $categoryId): void
    {
        $connection = $this->connect($hub);
        try {
            $row = DB::connection($connection)->table('categories')->where('id', $categoryId)->first();
            if (! $row) {
                throw new InvalidArgumentException('Category not found on this white-label hub.');
            }
            if (DB::connection($connection)->table('posts')->where('category', $row->name)->exists()) {
                throw new InvalidArgumentException('Cannot delete a category that is used by posts.');
            }
            DB::connection($connection)->table('categories')->where('id', $categoryId)->delete();
        } finally {
            $this->remoteDb->disconnect($hub);
        }
    }

    /**
     * @return array{tags: list<array<string, mixed>>}
     */
    public function listTags(Hub $hub): array
    {
        $connection = $this->connect($hub);
        try {
            $rows = DB::connection($connection)->table('tags')->orderBy('name')->get();

            return [
                'tags' => $rows->map(fn ($r) => [
                    'id' => $r->id,
                    'name' => $r->name,
                    'posts_count' => 0,
                ])->all(),
            ];
        } finally {
            $this->remoteDb->disconnect($hub);
        }
    }

    /**
     * @param  array{name: string}  $payload
     * @return array<string, mixed>
     */
    public function createTag(Hub $hub, array $payload): array
    {
        $connection = $this->connect($hub);
        try {
            $name = trim($payload['name']);
            if (DB::connection($connection)->table('tags')->where('name', $name)->exists()) {
                throw new InvalidArgumentException('A tag with that name already exists on this hub.');
            }
            $now = now();
            $id = (int) DB::connection($connection)->table('tags')->insertGetId([
                'name' => $name,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return ['id' => $id, 'name' => $name];
        } finally {
            $this->remoteDb->disconnect($hub);
        }
    }

    /**
     * @param  array{name: string}  $payload
     * @return array<string, mixed>
     */
    public function updateTag(Hub $hub, int $tagId, array $payload): array
    {
        $connection = $this->connect($hub);
        try {
            $row = DB::connection($connection)->table('tags')->where('id', $tagId)->first();
            if (! $row) {
                throw new InvalidArgumentException('Tag not found on this white-label hub.');
            }

            $oldName = (string) $row->name;
            $newName = trim($payload['name']);

            $dup = DB::connection($connection)->table('tags')
                ->where('name', $newName)
                ->where('id', '!=', $tagId)
                ->exists();
            if ($dup) {
                throw new InvalidArgumentException('A tag with that name already exists on this hub.');
            }

            DB::connection($connection)->table('tags')->where('id', $tagId)->update([
                'name' => $newName,
                'updated_at' => now(),
            ]);

            if ($oldName !== $newName) {
                $posts = DB::connection($connection)->table('posts')->get(['id', 'tags']);
                foreach ($posts as $post) {
                    $tags = $post->tags;
                    if (is_string($tags)) {
                        $decoded = json_decode($tags, true);
                        $tags = is_array($decoded) ? $decoded : [];
                    }
                    if (! is_array($tags) || ! in_array($oldName, $tags, true)) {
                        continue;
                    }
                    $tags = array_values(array_unique(array_map(
                        fn ($t) => $t === $oldName ? $newName : $t,
                        $tags
                    )));
                    DB::connection($connection)->table('posts')->where('id', $post->id)->update([
                        'tags' => json_encode($tags),
                        'updated_at' => now(),
                    ]);
                }
            }

            return ['id' => $tagId, 'name' => $newName];
        } finally {
            $this->remoteDb->disconnect($hub);
        }
    }

    public function deleteTag(Hub $hub, int $tagId): void
    {
        $connection = $this->connect($hub);
        try {
            $row = DB::connection($connection)->table('tags')->where('id', $tagId)->first();
            if (! $row) {
                throw new InvalidArgumentException('Tag not found on this white-label hub.');
            }

            $name = (string) $row->name;
            $inUse = DB::connection($connection)->table('posts')->get(['tags'])->contains(function ($post) use ($name) {
                $tags = $post->tags;
                if (is_string($tags)) {
                    $decoded = json_decode($tags, true);
                    $tags = is_array($decoded) ? $decoded : [];
                }

                return is_array($tags) && in_array($name, $tags, true);
            });
            if ($inUse) {
                throw new InvalidArgumentException('Cannot delete a tag that is used by posts.');
            }

            DB::connection($connection)->table('tags')->where('id', $tagId)->delete();
        } finally {
            $this->remoteDb->disconnect($hub);
        }
    }

    /**
     * @return array{data: list<array<string, mixed>>}
     */
    public function listBundles(Hub $hub, int $perPage = 50): array
    {
        $connection = $this->connect($hub);
        try {
            $rows = DB::connection($connection)->table('bundles')
                ->orderByDesc('updated_at')
                ->limit($perPage)
                ->get();

            return [
                'data' => $rows->map(function ($r) use ($connection) {
                    $count = DB::connection($connection)->table('bundle_post')
                        ->where('bundle_id', $r->id)
                        ->count();

                    return [
                        'id' => $r->id,
                        'title' => $r->title,
                        'description' => $r->description,
                        'credits_cost' => (int) $r->credits_cost,
                        'is_active' => (bool) $r->is_active,
                        'posts_count' => $count,
                        'image_path' => $r->image_path ?? null,
                        'image_url' => $this->resolveBundleImageUrl($r->image_path ?? null),
                    ];
                })->all(),
            ];
        } finally {
            $this->remoteDb->disconnect($hub);
        }
    }

    /**
     * Create a bundle on the white-label DB. post_ids refer to REMOTE post ids.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createBundle(Hub $hub, array $payload): array
    {
        $connection = $this->connect($hub);
        try {
            $postIds = array_values(array_unique(array_map('intval', $payload['post_ids'] ?? [])));
            if ($postIds === []) {
                throw new InvalidArgumentException('Add at least one post from this white-label hub.');
            }

            $found = DB::connection($connection)->table('posts')->whereIn('id', $postIds)->pluck('id')->all();
            if (count($found) !== count($postIds)) {
                throw new InvalidArgumentException('One or more selected posts do not exist on this white-label hub.');
            }

            $now = now();
            $insert = [
                'created_by' => $this->resolveRemoteCreatorId($connection),
                'title' => $payload['title'],
                'description' => $payload['description'] ?? null,
                'credits_cost' => (int) $payload['credits_cost'],
                'is_active' => (bool) ($payload['is_active'] ?? true),
                'buy_count' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            if (Schema::connection($connection)->hasColumn('bundles', 'image_path')) {
                $insert['image_path'] = $payload['image_path'] ?? null;
            }

            $id = (int) DB::connection($connection)->table('bundles')->insertGetId($insert);

            foreach ($postIds as $index => $postId) {
                DB::connection($connection)->table('bundle_post')->insert([
                    'bundle_id' => $id,
                    'post_id' => $postId,
                    'sort_order' => $index,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            return [
                'id' => $id,
                'title' => $payload['title'],
                'credits_cost' => (int) $payload['credits_cost'],
                'posts_count' => count($postIds),
                'is_active' => (bool) ($payload['is_active'] ?? true),
                'image_path' => $insert['image_path'] ?? null,
                'image_url' => $this->resolveBundleImageUrl($insert['image_path'] ?? null),
            ];
        } finally {
            $this->remoteDb->disconnect($hub);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function updateBundle(Hub $hub, int $bundleId, array $payload): array
    {
        $connection = $this->connect($hub);
        try {
            $row = DB::connection($connection)->table('bundles')->where('id', $bundleId)->first();
            if (! $row) {
                throw new InvalidArgumentException('Bundle not found on this white-label hub.');
            }

            $updates = ['updated_at' => now()];
            foreach (['title', 'description'] as $field) {
                if (array_key_exists($field, $payload)) {
                    $updates[$field] = $payload[$field];
                }
            }
            if (array_key_exists('credits_cost', $payload)) {
                $updates['credits_cost'] = (int) $payload['credits_cost'];
            }
            if (array_key_exists('is_active', $payload)) {
                $updates['is_active'] = (bool) $payload['is_active'];
            }
            if (
                array_key_exists('image_path', $payload)
                && Schema::connection($connection)->hasColumn('bundles', 'image_path')
            ) {
                $updates['image_path'] = $payload['image_path'];
            }

            DB::connection($connection)->table('bundles')->where('id', $bundleId)->update($updates);

            if (array_key_exists('post_ids', $payload)) {
                $postIds = array_values(array_unique(array_map('intval', $payload['post_ids'] ?? [])));
                if ($postIds === []) {
                    throw new InvalidArgumentException('Add at least one post from this white-label hub.');
                }
                $found = DB::connection($connection)->table('posts')->whereIn('id', $postIds)->pluck('id')->all();
                if (count($found) !== count($postIds)) {
                    throw new InvalidArgumentException('One or more selected posts do not exist on this white-label hub.');
                }

                DB::connection($connection)->table('bundle_post')->where('bundle_id', $bundleId)->delete();
                $now = now();
                foreach ($postIds as $index => $postId) {
                    DB::connection($connection)->table('bundle_post')->insert([
                        'bundle_id' => $bundleId,
                        'post_id' => $postId,
                        'sort_order' => $index,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }

            $fresh = DB::connection($connection)->table('bundles')->where('id', $bundleId)->first();
            $count = DB::connection($connection)->table('bundle_post')->where('bundle_id', $bundleId)->count();

            return [
                'id' => $bundleId,
                'title' => $fresh->title,
                'description' => $fresh->description,
                'credits_cost' => (int) $fresh->credits_cost,
                'is_active' => (bool) $fresh->is_active,
                'posts_count' => $count,
                'image_path' => $fresh->image_path ?? null,
                'image_url' => $this->resolveBundleImageUrl($fresh->image_path ?? null),
            ];
        } finally {
            $this->remoteDb->disconnect($hub);
        }
    }

    public function deleteBundle(Hub $hub, int $bundleId): void
    {
        $connection = $this->connect($hub);
        try {
            $row = DB::connection($connection)->table('bundles')->where('id', $bundleId)->first();
            if (! $row) {
                throw new InvalidArgumentException('Bundle not found on this white-label hub.');
            }
            DB::connection($connection)->table('bundle_post')->where('bundle_id', $bundleId)->delete();
            DB::connection($connection)->table('bundles')->where('id', $bundleId)->delete();
        } finally {
            $this->remoteDb->disconnect($hub);
        }
    }

    private function connect(Hub $hub): string
    {
        $this->assertTarget($hub);

        return $this->remoteDb->connect($hub);
    }

    /**
     * @return array<string, mixed>
     */
    private function mapPostRow(object $row): array
    {
        $tags = $row->tags;
        if (is_string($tags)) {
            $decoded = json_decode($tags, true);
            $tags = is_array($decoded) ? $decoded : [];
        }

        $path = $row->attachment_path;
        $url = null;
        if ($path) {
            $url = (str_starts_with((string) $path, 'http://') || str_starts_with((string) $path, 'https://'))
                ? $path
                : Storage::disk('public')->url($path);
        }

        return [
            'id' => $row->id,
            'title' => $row->title,
            'description' => $row->description,
            'type' => $row->type,
            'category' => $row->category,
            'tags' => array_values($tags ?? []),
            'credits_cost' => (int) $row->credits_cost,
            'is_active' => (bool) $row->is_active,
            'attachment_name' => $row->attachment_name,
            'attachment_mime' => $row->attachment_mime,
            'attachment_url' => $url,
            'cover_url' => $url,
            'updated_at' => $row->updated_at,
        ];
    }

    /**
     * @return array{path?: string, name?: string, mime?: string}
     */
    private function storeAttachmentAsPublicUrl(?UploadedFile $file): array
    {
        if (! $file) {
            return [];
        }

        $stored = $file->store('posts', 'public');
        $relative = Storage::disk('public')->url($stored);
        $absolute = (str_starts_with($relative, 'http://') || str_starts_with($relative, 'https://'))
            ? $relative
            : rtrim((string) config('app.url'), '/').'/'.ltrim($relative, '/');

        return [
            'path' => $absolute,
            'name' => $file->getClientOriginalName(),
            'mime' => $file->getClientMimeType(),
        ];
    }

    private function ensureNamedType(string $connection, string $name): void
    {
        if ($name === '') {
            return;
        }
        if (DB::connection($connection)->table('content_types')->where('name', $name)->exists()) {
            return;
        }
        $now = now();
        DB::connection($connection)->table('content_types')->insert([
            'name' => $name,
            'slug' => Str::slug($name) ?: 'type-'.Str::lower(Str::random(4)),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function ensureNamedCategory(string $connection, string $name): void
    {
        if ($name === '') {
            return;
        }
        if (DB::connection($connection)->table('categories')->where('name', $name)->exists()) {
            return;
        }
        $now = now();
        DB::connection($connection)->table('categories')->insert([
            'name' => $name,
            'slug' => Str::slug($name) ?: 'category-'.Str::lower(Str::random(4)),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function ensureNamedTag(string $connection, string $name): void
    {
        $name = trim($name);
        if ($name === '') {
            return;
        }
        if (DB::connection($connection)->table('tags')->where('name', $name)->exists()) {
            return;
        }
        $now = now();
        DB::connection($connection)->table('tags')->insert([
            'name' => $name,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function resolveRemoteCreatorId(string $connection): int
    {
        $existing = DB::connection($connection)->table('users')
            ->whereIn('role', ['power_admin', 'finproms_admin', 'client_admin', 'manager'])
            ->orderBy('id')
            ->value('id');
        if ($existing) {
            return (int) $existing;
        }
        $any = DB::connection($connection)->table('users')->orderBy('id')->value('id');
        if ($any) {
            return (int) $any;
        }
        $now = now();

        return (int) DB::connection($connection)->table('users')->insertGetId([
            'name' => 'Shared Hub Publisher',
            'email' => 'shared-publisher@'.$connection.'.local',
            'password' => bcrypt(Str::random(32)),
            'role' => 'finproms_admin',
            'email_verified_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function resolveBundleImageUrl(?string $path): ?string
    {
        if (! filled($path)) {
            return null;
        }

        if (str_starts_with($path, 'http://')
            || str_starts_with($path, 'https://')
            || str_starts_with($path, '/')) {
            return $path;
        }

        return Storage::disk('public')->url($path);
    }
}
