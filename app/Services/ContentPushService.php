<?php

namespace App\Services;

use App\Models\Bundle;
use App\Models\Category;
use App\Models\ContentPush;
use App\Models\ContentType;
use App\Models\Hub;
use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Push content from the shared hub into a white-label hub's own database.
 */
class ContentPushService
{
    public function __construct(
        private readonly WhiteLabelDatabaseService $remoteDb,
        private readonly HubService $hubs
    ) {}

    /**
     * @param  list<int>  $postIds
     * @param  list<int>  $hubIds
     * @return array{results: list<array<string, mixed>>, pushed: int, failed: int}
     */
    public function pushPosts(array $postIds, array $hubIds, ?User $actor = null): array
    {
        $posts = Post::query()->whereIn('id', $postIds)->get();
        if ($posts->isEmpty()) {
            throw new InvalidArgumentException('No posts found to push.');
        }

        return $this->pushModels('post', $posts->all(), $hubIds, $actor);
    }

    /**
     * @param  list<int>  $ids
     * @param  list<int>  $hubIds
     * @return array{results: list<array<string, mixed>>, pushed: int, failed: int}
     */
    public function pushCategories(array $ids, array $hubIds, ?User $actor = null): array
    {
        $items = Category::query()->whereIn('id', $ids)->get();
        if ($items->isEmpty()) {
            throw new InvalidArgumentException('No categories found to push.');
        }

        return $this->pushModels('category', $items->all(), $hubIds, $actor);
    }

    /**
     * @param  list<int>  $ids
     * @param  list<int>  $hubIds
     * @return array{results: list<array<string, mixed>>, pushed: int, failed: int}
     */
    public function pushContentTypes(array $ids, array $hubIds, ?User $actor = null): array
    {
        $items = ContentType::query()->whereIn('id', $ids)->get();
        if ($items->isEmpty()) {
            throw new InvalidArgumentException('No content types found to push.');
        }

        return $this->pushModels('type', $items->all(), $hubIds, $actor);
    }

    /**
     * @param  list<int>  $ids
     * @param  list<int>  $hubIds
     * @return array{results: list<array<string, mixed>>, pushed: int, failed: int}
     */
    public function pushTags(array $ids, array $hubIds, ?User $actor = null): array
    {
        $items = Tag::query()->whereIn('id', $ids)->get();
        if ($items->isEmpty()) {
            throw new InvalidArgumentException('No tags found to push.');
        }

        return $this->pushModels('tag', $items->all(), $hubIds, $actor);
    }

    /**
     * @param  list<int>  $ids
     * @param  list<int>  $hubIds
     * @return array{results: list<array<string, mixed>>, pushed: int, failed: int}
     */
    public function pushBundles(array $ids, array $hubIds, ?User $actor = null): array
    {
        $items = Bundle::query()->with('posts')->whereIn('id', $ids)->get();
        if ($items->isEmpty()) {
            throw new InvalidArgumentException('No bundles found to push.');
        }

        return $this->pushModels('bundle', $items->all(), $hubIds, $actor);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function eligibleTargetHubs(): array
    {
        return Hub::query()
            ->where('type', Hub::TYPE_WHITE_LABEL)
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(function (Hub $hub) {
                $canReceive = $hub->can('receive_content_from_shared');
                $dbReady = $hub->hasRemoteDatabaseConfigured();

                return [
                    'id' => $hub->id,
                    'name' => $hub->name,
                    'slug' => $hub->slug,
                    'can_receive' => $canReceive,
                    'db_ready' => $dbReady,
                    'eligible' => $canReceive && $dbReady,
                    'frontend_url' => $hub->frontend_url,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  list<object>  $models
     * @param  list<int>  $hubIds
     * @return array{results: list<array<string, mixed>>, pushed: int, failed: int}
     */
    private function pushModels(string $entityType, array $models, array $hubIds, ?User $actor): array
    {
        $sourceHub = $this->hubs->current();
        if (! $sourceHub->isShared()) {
            throw new InvalidArgumentException('Content can only be pushed from the shared hub.');
        }

        $targets = Hub::query()
            ->whereIn('id', $hubIds)
            ->where('type', Hub::TYPE_WHITE_LABEL)
            ->where('is_active', true)
            ->get();

        if ($targets->isEmpty()) {
            throw new InvalidArgumentException('No active white-label hubs selected.');
        }

        $results = [];
        $pushed = 0;
        $failed = 0;

        foreach ($targets as $hub) {
            foreach ($models as $model) {
                $row = $this->pushOneEntity($sourceHub, $hub, $entityType, $model, $actor);
                $results[] = $row;
                if ($row['status'] === 'success') {
                    $pushed++;
                } else {
                    $failed++;
                }
            }
        }

        return [
            'results' => $results,
            'pushed' => $pushed,
            'failed' => $failed,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function pushOneEntity(
        Hub $sourceHub,
        Hub $targetHub,
        string $entityType,
        object $model,
        ?User $actor
    ): array {
        $label = $this->entityLabel($entityType, $model);
        $entityId = (int) ($model->id ?? 0);
        $base = [
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'entity_label' => $label,
            'post_id' => $entityType === 'post' ? $entityId : null,
            'post_title' => $entityType === 'post' ? $label : null,
            'target_hub_id' => $targetHub->id,
            'target_hub_name' => $targetHub->name,
        ];

        if (! $targetHub->can('receive_content_from_shared')) {
            return $this->recordResult($sourceHub, $targetHub, $entityType, $model, $actor, $base, false, null, 'Hub does not allow receiving content from the shared hub.');
        }

        if (! $targetHub->hasRemoteDatabaseConfigured()) {
            return $this->recordResult($sourceHub, $targetHub, $entityType, $model, $actor, $base, false, null, 'Remote database credentials are not configured.');
        }

        try {
            $connection = $this->remoteDb->connect($targetHub);
            $remoteId = match ($entityType) {
                'post' => $this->insertPostOnRemote($connection, $model),
                'category' => $this->upsertCategoryOnRemote($connection, $model),
                'type' => $this->upsertTypeOnRemote($connection, $model),
                'tag' => $this->upsertTagOnRemote($connection, $model),
                'bundle' => $this->insertBundleOnRemote($connection, $model),
                default => throw new InvalidArgumentException('Unknown entity type.'),
            };

            return $this->recordResult(
                $sourceHub,
                $targetHub,
                $entityType,
                $model,
                $actor,
                $base,
                true,
                $remoteId,
                'Pushed successfully (remote #'.$remoteId.').'
            );
        } catch (Throwable $e) {
            return $this->recordResult(
                $sourceHub,
                $targetHub,
                $entityType,
                $model,
                $actor,
                $base,
                false,
                null,
                $e->getMessage()
            );
        } finally {
            $this->remoteDb->disconnect($targetHub);
        }
    }

    /**
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>
     */
    private function recordResult(
        Hub $sourceHub,
        Hub $targetHub,
        string $entityType,
        object $model,
        ?User $actor,
        array $base,
        bool $ok,
        ?int $remoteId,
        string $message
    ): array {
        $entityId = (int) ($model->id ?? 0);

        ContentPush::query()->create([
            'source_hub_id' => $sourceHub->id,
            'target_hub_id' => $targetHub->id,
            'post_id' => $entityType === 'post' ? $entityId : null,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'entity_label' => $this->entityLabel($entityType, $model),
            'pushed_by' => $actor?->id,
            'remote_post_id' => $remoteId,
            'status' => $ok ? 'success' : 'failed',
            'message' => $message,
        ]);

        return array_merge($base, [
            'status' => $ok ? 'success' : 'failed',
            'remote_post_id' => $remoteId,
            'message' => $message,
        ]);
    }

    private function entityLabel(string $entityType, object $model): string
    {
        return match ($entityType) {
            'post' => (string) ($model->title ?? 'Post'),
            'bundle' => (string) ($model->title ?? 'Bundle'),
            default => (string) ($model->name ?? 'Item'),
        };
    }

    private function insertPostOnRemote(string $connection, Post $post): int
    {
        $this->ensureTaxonomyForPost($connection, $post);

        $createdBy = $this->resolveRemoteCreatorId($connection);
        $attachmentPath = $this->remoteAttachmentPath($post);
        $now = now();

        return (int) DB::connection($connection)->table('posts')->insertGetId([
            'created_by' => $createdBy,
            'title' => $post->title,
            'description' => $post->description,
            'type' => $post->type,
            'category' => $post->category,
            'tags' => json_encode(array_values($post->tags ?? [])),
            'credits_cost' => $post->credits_cost,
            'attachment_path' => $attachmentPath,
            'attachment_name' => $post->attachment_name,
            'attachment_mime' => $post->attachment_mime,
            'is_active' => (bool) $post->is_active,
            'views_count' => 0,
            'reach_count' => 0,
            'buy_count' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function upsertCategoryOnRemote(string $connection, Category $category): int
    {
        $existing = DB::connection($connection)->table('categories')->where('name', $category->name)->first();
        if ($existing) {
            DB::connection($connection)->table('categories')->where('id', $existing->id)->update([
                'slug' => $category->slug ?: Str::slug($category->name),
                'updated_at' => now(),
            ]);

            return (int) $existing->id;
        }

        $now = now();

        return (int) DB::connection($connection)->table('categories')->insertGetId([
            'name' => $category->name,
            'slug' => $category->slug ?: Str::slug($category->name),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function upsertTypeOnRemote(string $connection, ContentType $type): int
    {
        $existing = DB::connection($connection)->table('content_types')->where('name', $type->name)->first();
        if ($existing) {
            DB::connection($connection)->table('content_types')->where('id', $existing->id)->update([
                'slug' => $type->slug ?: Str::slug($type->name),
                'updated_at' => now(),
            ]);

            return (int) $existing->id;
        }

        $now = now();

        return (int) DB::connection($connection)->table('content_types')->insertGetId([
            'name' => $type->name,
            'slug' => $type->slug ?: Str::slug($type->name),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function upsertTagOnRemote(string $connection, Tag $tag): int
    {
        $existing = DB::connection($connection)->table('tags')->where('name', $tag->name)->first();
        if ($existing) {
            return (int) $existing->id;
        }

        $now = now();

        return (int) DB::connection($connection)->table('tags')->insertGetId([
            'name' => $tag->name,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function insertBundleOnRemote(string $connection, Bundle $bundle): int
    {
        $remotePostIds = [];
        foreach ($bundle->posts as $post) {
            $remotePostIds[] = $this->insertPostOnRemote($connection, $post);
        }

        if ($remotePostIds === []) {
            throw new InvalidArgumentException('Bundle has no posts to push.');
        }

        $createdBy = $this->resolveRemoteCreatorId($connection);
        $now = now();
        $remoteBundleId = (int) DB::connection($connection)->table('bundles')->insertGetId([
            'created_by' => $createdBy,
            'title' => $bundle->title,
            'description' => $bundle->description,
            'credits_cost' => $bundle->credits_cost,
            'is_active' => (bool) $bundle->is_active,
            'buy_count' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach ($remotePostIds as $index => $remotePostId) {
            DB::connection($connection)->table('bundle_post')->insert([
                'bundle_id' => $remoteBundleId,
                'post_id' => $remotePostId,
                'sort_order' => $index,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return $remoteBundleId;
    }

    private function ensureTaxonomyForPost(string $connection, Post $post): void
    {
        if (filled($post->type)) {
            $type = new ContentType(['name' => $post->type, 'slug' => Str::slug((string) $post->type)]);
            $this->upsertTypeOnRemote($connection, $type);
        }

        if (filled($post->category)) {
            $category = new Category(['name' => $post->category, 'slug' => Str::slug((string) $post->category)]);
            $this->upsertCategoryOnRemote($connection, $category);
        }

        foreach ($post->tags ?? [] as $tagName) {
            $tagName = trim((string) $tagName);
            if ($tagName === '') {
                continue;
            }
            $this->upsertTagOnRemote($connection, new Tag(['name' => $tagName]));
        }
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
            'name' => 'Shared Hub Push',
            'email' => 'shared-push@'.$connection.'.local',
            'password' => bcrypt(Str::random(32)),
            'role' => 'finproms_admin',
            'email_verified_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function remoteAttachmentPath(Post $post): ?string
    {
        if (! filled($post->attachment_path)) {
            return null;
        }

        $path = (string) $post->attachment_path;
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        $relative = Storage::disk('public')->url($path);
        if (str_starts_with($relative, 'http://') || str_starts_with($relative, 'https://')) {
            return $relative;
        }

        return rtrim((string) config('app.url'), '/').'/'.ltrim($relative, '/');
    }
}
