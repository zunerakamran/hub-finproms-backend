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
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Push content from the shared hub into a white-labelled hub's own database.
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
     * @return array{results: list<array<string, mixed>>, pushed: int, failed: int, skipped: int}
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
     * @return array{results: list<array<string, mixed>>, pushed: int, failed: int, skipped: int}
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
     * Push one Central category (create/update/rename + icons) to every eligible
     * content hub. Soft-fails per hub so Central CRUD is never blocked.
     *
     * @return array{synced: int, failed: int, skipped: int, results: list<array{hub_id: int, hub_name: string, status: string, message: string}>}
     */
    public function syncCategoryToEligibleHubs(Category $category, ?string $previousName = null): array
    {
        $results = [];
        $synced = 0;
        $failed = 0;
        $skipped = 0;

        $targets = Hub::query()
            ->whereIn('type', [Hub::TYPE_SHARED, Hub::TYPE_WHITE_LABEL])
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        foreach ($targets as $hub) {
            if (! $hub->can('receive_content_from_shared')) {
                $skipped++;
                $results[] = [
                    'hub_id' => $hub->id,
                    'hub_name' => $hub->name,
                    'status' => 'skipped',
                    'message' => 'Receive content from Central Hub is off.',
                ];

                continue;
            }

            if (! $hub->hasRemoteDatabaseConfigured()) {
                $skipped++;
                $results[] = [
                    'hub_id' => $hub->id,
                    'hub_name' => $hub->name,
                    'status' => 'skipped',
                    'message' => 'Remote database not configured.',
                ];

                continue;
            }

            try {
                $connection = $this->remoteDb->connect($hub);
                try {
                    $remoteId = $this->upsertCategoryOnRemote($connection, $category, $previousName);
                    $synced++;
                    $results[] = [
                        'hub_id' => $hub->id,
                        'hub_name' => $hub->name,
                        'status' => 'synced',
                        'message' => 'Category synced (remote #'.$remoteId.').',
                    ];
                } finally {
                    $this->remoteDb->disconnect($hub);
                }
            } catch (Throwable $e) {
                $failed++;
                $results[] = [
                    'hub_id' => $hub->id,
                    'hub_name' => $hub->name,
                    'status' => 'failed',
                    'message' => $e->getMessage(),
                ];
            }
        }

        return [
            'synced' => $synced,
            'failed' => $failed,
            'skipped' => $skipped,
            'results' => $results,
        ];
    }

    /**
     * Remove a Central category from eligible content hubs when unused remotely.
     *
     * @return array{deleted: int, failed: int, skipped: int, results: list<array{hub_id: int, hub_name: string, status: string, message: string}>}
     */
    public function deleteCategoryFromEligibleHubs(string $categoryName): array
    {
        $results = [];
        $deleted = 0;
        $failed = 0;
        $skipped = 0;
        $name = trim($categoryName);

        if ($name === '') {
            return compact('deleted', 'failed', 'skipped') + ['results' => []];
        }

        $targets = Hub::query()
            ->whereIn('type', [Hub::TYPE_SHARED, Hub::TYPE_WHITE_LABEL])
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        foreach ($targets as $hub) {
            if (! $hub->can('receive_content_from_shared') || ! $hub->hasRemoteDatabaseConfigured()) {
                $skipped++;
                $results[] = [
                    'hub_id' => $hub->id,
                    'hub_name' => $hub->name,
                    'status' => 'skipped',
                    'message' => 'Hub not eligible for Central taxonomy sync.',
                ];

                continue;
            }

            try {
                $connection = $this->remoteDb->connect($hub);
                try {
                    $row = DB::connection($connection)->table('categories')->where('name', $name)->first();
                    if (! $row) {
                        $skipped++;
                        $results[] = [
                            'hub_id' => $hub->id,
                            'hub_name' => $hub->name,
                            'status' => 'skipped',
                            'message' => 'Category not present on this hub.',
                        ];

                        continue;
                    }

                    $this->ensurePostCategoriesColumn($connection);
                    $inUse = false;
                    $posts = DB::connection($connection)->table('posts')->get(['categories']);
                    foreach ($posts as $post) {
                        $categories = $post->categories;
                        if (is_string($categories)) {
                            $decoded = json_decode($categories, true);
                            $categories = is_array($decoded) ? $decoded : [];
                        }
                        if (is_array($categories) && in_array($name, $categories, true)) {
                            $inUse = true;
                            break;
                        }
                    }

                    if ($inUse) {
                        $skipped++;
                        $results[] = [
                            'hub_id' => $hub->id,
                            'hub_name' => $hub->name,
                            'status' => 'skipped',
                            'message' => 'Category is used by posts on this hub.',
                        ];

                        continue;
                    }

                    DB::connection($connection)->table('categories')->where('id', $row->id)->delete();
                    $deleted++;
                    $results[] = [
                        'hub_id' => $hub->id,
                        'hub_name' => $hub->name,
                        'status' => 'deleted',
                        'message' => 'Category removed from hub.',
                    ];
                } finally {
                    $this->remoteDb->disconnect($hub);
                }
            } catch (Throwable $e) {
                $failed++;
                $results[] = [
                    'hub_id' => $hub->id,
                    'hub_name' => $hub->name,
                    'status' => 'failed',
                    'message' => $e->getMessage(),
                ];
            }
        }

        return [
            'deleted' => $deleted,
            'failed' => $failed,
            'skipped' => $skipped,
            'results' => $results,
        ];
    }

    /**
     * @param  list<int>  $ids
     * @param  list<int>  $hubIds
     * @return array{results: list<array<string, mixed>>, pushed: int, failed: int, skipped: int}
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
     * @return array{results: list<array<string, mixed>>, pushed: int, failed: int, skipped: int}
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
     * @return array{results: list<array<string, mixed>>, pushed: int, failed: int, skipped: int}
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
            ->whereIn('type', [Hub::TYPE_SHARED, Hub::TYPE_WHITE_LABEL])
            ->where('is_active', true)
            ->orderByRaw('CASE WHEN type = ? THEN 0 ELSE 1 END', [Hub::TYPE_SHARED])
            ->orderBy('name')
            ->get()
            ->map(function (Hub $hub) {
                $canReceive = $hub->can('receive_content_from_shared');
                $dbReady = $hub->hasRemoteDatabaseConfigured();
                $manual = $hub->can('manual_posts');
                $ai = $hub->can('ai_posts');
                $smtl = $hub->hasSocialMediaTemplateLibraryModule();
                $eligible = $canReceive && $dbReady && ($manual || $ai);

                return [
                    'id' => $hub->id,
                    'name' => $hub->name,
                    'slug' => $hub->slug,
                    'type' => $hub->type,
                    'can_receive' => $canReceive,
                    'db_ready' => $dbReady,
                    'manual_posts' => $manual,
                    'ai_posts' => $ai,
                    'smtl_enabled' => $smtl,
                    'eligible' => $eligible,
                    'reason' => $eligible ? null : $this->ineligibleTargetReason(
                        $canReceive,
                        $dbReady,
                        $manual,
                        $ai,
                        $smtl
                    ),
                    'frontend_url' => $hub->frontend_url,
                ];
            })
            ->values()
            ->all();
    }

    private function ineligibleTargetReason(
        bool $canReceive,
        bool $dbReady,
        bool $manual,
        bool $ai,
        bool $smtl
    ): string {
        $parts = [];
        if (! $smtl) {
            $parts[] = 'Social Media Template Library is off';
        }
        if (! $canReceive) {
            $parts[] = 'Receive content from Central Hub is off';
        }
        if (! $dbReady) {
            $parts[] = 'remote database not configured';
        }
        if (! $manual && ! $ai) {
            $parts[] = 'Manual posts and AI posts are both off';
        }

        return $parts !== []
            ? implode('; ', $parts)
            : 'not ready for distribution';
    }

    /**
     * @param  list<object>  $models
     * @param  list<int>  $hubIds
     * @return array{results: list<array<string, mixed>>, pushed: int, failed: int, skipped: int}
     */
    private function pushModels(string $entityType, array $models, array $hubIds, ?User $actor): array
    {
        $sourceHub = $this->hubs->current();
        if (! $sourceHub->isControlPlane()) {
            throw new InvalidArgumentException('Content can only be pushed from the Central Hub Controller.');
        }

        $targets = Hub::query()
            ->whereIn('id', $hubIds)
            ->whereIn('type', [Hub::TYPE_SHARED, Hub::TYPE_WHITE_LABEL])
            ->where('is_active', true)
            ->get();

        if ($targets->isEmpty()) {
            throw new InvalidArgumentException('No active content hubs selected.');
        }

        $results = [];
        $pushed = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($targets as $hub) {
            $connection = null;
            $ownsConnection = false;

            try {
                if ($hub->can('receive_content_from_shared') && $hub->hasRemoteDatabaseConfigured()) {
                    try {
                        $connection = $this->remoteDb->connect($hub);
                        $ownsConnection = true;
                    } catch (Throwable) {
                        // Per-entity push will surface the connection error.
                        $connection = null;
                    }
                }

                foreach ($models as $model) {
                    $row = $this->pushOneEntity(
                        $sourceHub,
                        $hub,
                        $entityType,
                        $model,
                        $actor,
                        $connection
                    );
                    $results[] = $row;
                    if ($row['status'] === 'success') {
                        $pushed++;
                    } elseif ($row['status'] === 'skipped') {
                        $skipped++;
                    } else {
                        $failed++;
                    }
                }
            } finally {
                if ($ownsConnection) {
                    $this->remoteDb->disconnect($hub);
                }
            }
        }

        return [
            'results' => $results,
            'pushed' => $pushed,
            'failed' => $failed,
            'skipped' => $skipped,
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
        ?User $actor,
        ?string $connection = null
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
            return $this->recordResult($sourceHub, $targetHub, $entityType, $model, $actor, $base, 'failed', null, 'Hub does not allow receiving content from Central.');
        }

        if (! $targetHub->hasRemoteDatabaseConfigured()) {
            return $this->recordResult($sourceHub, $targetHub, $entityType, $model, $actor, $base, 'failed', null, 'Remote database credentials are not configured.');
        }

        if ($entityType === 'post' && $model instanceof Post) {
            // Distribute live library posts only. Archived = retired from the
            // distribute pool (still kept in the Central library list).
            if ($model->archived_at !== null) {
                return $this->recordResult(
                    $sourceHub,
                    $targetHub,
                    $entityType,
                    $model,
                    $actor,
                    $base,
                    'failed',
                    null,
                    'This post is archived and cannot be distributed. Use a non-archived library post.'
                );
            }

            if ($model->isAiSource()) {
                if (! $targetHub->can('ai_posts')) {
                    return $this->recordResult(
                        $sourceHub,
                        $targetHub,
                        $entityType,
                        $model,
                        $actor,
                        $base,
                        'failed',
                        null,
                        'Target hub only accepts manual posts (AI posts functionality is off).'
                    );
                }
            } elseif (! $targetHub->can('manual_posts')) {
                return $this->recordResult(
                    $sourceHub,
                    $targetHub,
                    $entityType,
                    $model,
                    $actor,
                    $base,
                    'failed',
                    null,
                    'Target hub only accepts AI posts (Manual posts functionality is off).'
                );
            }
        }

        $ownsConnection = false;

        try {
            if ($connection === null) {
                $connection = $this->remoteDb->connect($targetHub);
                $ownsConnection = true;
            }

            if ($entityType === 'post' && $model instanceof Post) {
                $priorRemoteId = $this->existingSuccessfulRemotePostId($entityId, (int) $targetHub->id);
                if ($priorRemoteId !== null) {
                    $stillThere = DB::connection($connection)
                        ->table('posts')
                        ->where('id', $priorRemoteId)
                        ->exists();
                    if ($stillThere) {
                        return $this->recordResult(
                            $sourceHub,
                            $targetHub,
                            $entityType,
                            $model,
                            $actor,
                            $base,
                            'skipped',
                            $priorRemoteId,
                            'Already on this hub (remote #'.$priorRemoteId.'). Skipped duplicate insert.'
                        );
                    }
                }
            }

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
                'success',
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
                'failed',
                null,
                $e->getMessage()
            );
        } finally {
            if ($ownsConnection) {
                $this->remoteDb->disconnect($targetHub);
            }
        }
    }

    private function existingSuccessfulRemotePostId(int $postId, int $targetHubId): ?int
    {
        $remoteId = ContentPush::query()
            ->where('post_id', $postId)
            ->where('target_hub_id', $targetHubId)
            ->where('entity_type', 'post')
            ->where('status', 'success')
            ->whereNotNull('remote_post_id')
            ->orderByDesc('id')
            ->value('remote_post_id');

        return $remoteId !== null ? (int) $remoteId : null;
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
        string $status,
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
            'status' => $status,
            'message' => $message,
        ]);

        return array_merge($base, [
            'status' => $status,
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
        $this->ensurePostCategoriesColumn($connection);

        $createdBy = $this->resolveRemoteCreatorId($connection);
        $attachmentPath = $this->remoteAttachmentPath($post);
        $now = now();

        $insert = [
            'created_by' => $createdBy,
            'title' => $post->title,
            'description' => $post->description,
            'type' => $post->type,
            'categories' => json_encode(array_values($post->categories ?? [])),
            'tags' => json_encode(array_values($post->tags ?? [])),
            'credits_cost' => $post->credits_cost,
            'attachment_path' => $attachmentPath,
            'attachment_name' => $post->attachment_name,
            'attachment_mime' => $post->attachment_mime,
            'is_active' => true,
            'views_count' => 0,
            'reach_count' => 0,
            'buy_count' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        if (Schema::connection($connection)->hasColumn('posts', 'creation_source')) {
            $insert['creation_source'] = $post->creation_source ?: Post::SOURCE_MANUAL;
        }
        // Distributed copies are live on the target hub (not archived).
        if (Schema::connection($connection)->hasColumn('posts', 'archived_at')) {
            $insert['archived_at'] = null;
            if (Schema::connection($connection)->hasColumn('posts', 'archive_remarks')) {
                $insert['archive_remarks'] = null;
            }
            if (Schema::connection($connection)->hasColumn('posts', 'archived_by')) {
                $insert['archived_by'] = null;
            }
        }

        if (Schema::connection($connection)->hasColumn('posts', 'canva_link')) {
            $insert['canva_link'] = $post->canva_link;
        } elseif (filled($post->canva_link)) {
            Schema::connection($connection)->table('posts', function (Blueprint $table) {
                $table->string('canva_link', 2048)->nullable();
            });
            $insert['canva_link'] = $post->canva_link;
        }

        return (int) DB::connection($connection)->table('posts')->insertGetId($insert);
    }

    /**
     * Upsert a category on a remote hub. When $previousName differs from the
     * current name, rename the remote row and rewrite post category lists.
     */
    private function upsertCategoryOnRemote(
        string $connection,
        Category $category,
        ?string $previousName = null
    ): int {
        $this->ensureRemoteCategoryIconColumns($connection);
        $this->ensurePostCategoriesColumn($connection);

        $newName = trim((string) $category->name);
        $previousName = filled($previousName) ? trim($previousName) : null;
        $renaming = $previousName !== null && $previousName !== '' && $previousName !== $newName;

        $iconPath = null;
        if (filled($category->icon_path)) {
            $iconPath = $category->iconPublicUrl() ?: (string) $category->icon_path;
        }

        $updates = [
            'name' => $newName,
            'slug' => $category->slug ?: Str::slug($newName),
            'updated_at' => now(),
        ];
        $schema = Schema::connection($connection);
        if ($schema->hasColumn('categories', 'icon')) {
            $updates['icon'] = filled($category->icon) ? (string) $category->icon : null;
        }
        if ($schema->hasColumn('categories', 'icon_path')) {
            $updates['icon_path'] = $iconPath;
        }

        $byNewName = DB::connection($connection)->table('categories')->where('name', $newName)->first();
        $byPrevious = $renaming
            ? DB::connection($connection)->table('categories')->where('name', $previousName)->first()
            : null;

        if ($renaming && $byPrevious && $byNewName && (int) $byPrevious->id !== (int) $byNewName->id) {
            // Target name already exists: refresh icons on that row, move posts, drop old name.
            $mergeUpdates = [
                'slug' => $updates['slug'],
                'updated_at' => $updates['updated_at'],
            ];
            if ($schema->hasColumn('categories', 'icon')) {
                $mergeUpdates['icon'] = $updates['icon'] ?? null;
            }
            if ($schema->hasColumn('categories', 'icon_path')) {
                $mergeUpdates['icon_path'] = $updates['icon_path'] ?? null;
            }
            DB::connection($connection)->table('categories')->where('id', $byNewName->id)->update($mergeUpdates);
            $this->rewriteRemotePostCategoryName($connection, $previousName, $newName);
            DB::connection($connection)->table('categories')->where('id', $byPrevious->id)->delete();

            return (int) $byNewName->id;
        }

        if ($renaming && $byPrevious) {
            DB::connection($connection)->table('categories')->where('id', $byPrevious->id)->update($updates);
            $this->rewriteRemotePostCategoryName($connection, $previousName, $newName);

            return (int) $byPrevious->id;
        }

        if ($byNewName) {
            DB::connection($connection)->table('categories')->where('id', $byNewName->id)->update($updates);

            return (int) $byNewName->id;
        }

        $now = now();

        return (int) DB::connection($connection)->table('categories')->insertGetId(array_merge($updates, [
            'created_at' => $now,
        ]));
    }

    private function rewriteRemotePostCategoryName(string $connection, string $oldName, string $newName): void
    {
        $posts = DB::connection($connection)->table('posts')->get(['id', 'categories']);
        foreach ($posts as $post) {
            $categories = $post->categories;
            if (is_string($categories)) {
                $decoded = json_decode($categories, true);
                $categories = is_array($decoded) ? $decoded : [];
            }
            if (! is_array($categories) || ! in_array($oldName, $categories, true)) {
                continue;
            }
            $categories = array_values(array_unique(array_map(
                fn ($c) => $c === $oldName ? $newName : $c,
                $categories
            )));
            DB::connection($connection)->table('posts')->where('id', $post->id)->update([
                'categories' => json_encode($categories),
                'updated_at' => now(),
            ]);
        }
    }

    private function ensureRemoteCategoryIconColumns(string $connection): void
    {
        $schema = Schema::connection($connection);
        if (! $schema->hasTable('categories')) {
            return;
        }
        if (! $schema->hasColumn('categories', 'icon')) {
            $schema->table('categories', function (Blueprint $table) {
                $table->string('icon', 64)->nullable();
            });
        }
        if (! $schema->hasColumn('categories', 'icon_path')) {
            $schema->table('categories', function (Blueprint $table) {
                $table->string('icon_path', 1000)->nullable();
            });
        }
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

        foreach ($post->categories ?? [] as $categoryName) {
            $categoryName = trim((string) $categoryName);
            if ($categoryName === '') {
                continue;
            }
            $category = Category::query()->where('name', $categoryName)->first()
                ?: new Category(['name' => $categoryName, 'slug' => Str::slug($categoryName)]);
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

    private function ensurePostCategoriesColumn(string $connection): void
    {
        $schema = Schema::connection($connection);
        if ($schema->hasColumn('posts', 'categories')) {
            return;
        }

        $schema->table('posts', function (Blueprint $table) {
            $table->json('categories')->nullable();
        });

        if ($schema->hasColumn('posts', 'category')) {
            $posts = DB::connection($connection)->table('posts')->select('id', 'category')->get();
            foreach ($posts as $post) {
                $list = filled($post->category) ? [(string) $post->category] : [];
                DB::connection($connection)->table('posts')->where('id', $post->id)->update([
                    'categories' => json_encode(array_values($list)),
                ]);
            }

            $schema->table('posts', function (Blueprint $table) {
                $table->dropColumn('category');
            });
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

    /**
     * Latest successful distribution per target hub for each Central post.
     *
     * @param  list<int>  $postIds
     * @return array<int, list<array{hub_id: int, hub_name: string, hub_type: string, remote_post_id: int|null, distributed_at: string|null}>>
     */
    public function successfulDistributionsForPosts(array $postIds): array
    {
        $postIds = array_values(array_unique(array_map('intval', $postIds)));
        if ($postIds === []) {
            return [];
        }

        $rows = ContentPush::query()
            ->with(['targetHub:id,name,type'])
            ->whereIn('post_id', $postIds)
            ->where('entity_type', 'post')
            ->where('status', 'success')
            ->whereNotNull('remote_post_id')
            ->orderByDesc('id')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $postId = (int) $row->post_id;
            $hubId = (int) $row->target_hub_id;
            if (! isset($out[$postId])) {
                $out[$postId] = [];
            }
            // Keep the latest success per hub only.
            foreach ($out[$postId] as $existing) {
                if ((int) $existing['hub_id'] === $hubId) {
                    continue 2;
                }
            }
            $out[$postId][] = [
                'hub_id' => $hubId,
                'hub_name' => (string) ($row->targetHub?->name ?: 'Hub #'.$hubId),
                'hub_type' => (string) ($row->targetHub?->type ?: ''),
                'remote_post_id' => $row->remote_post_id !== null ? (int) $row->remote_post_id : null,
                'distributed_at' => optional($row->created_at)?->toIso8601String(),
            ];
        }

        return $out;
    }

    /**
     * Push the current Central post fields onto every hub it was successfully
     * distributed to (matched by content_pushes.remote_post_id).
     *
     * @return array{synced: int, failed: int, results: list<array<string, mixed>>}
     */
    public function syncPostToDistributedHubs(Post $post): array
    {
        $distributions = $this->successfulDistributionsForPosts([(int) $post->id])[(int) $post->id] ?? [];
        if ($distributions === []) {
            return ['synced' => 0, 'failed' => 0, 'results' => []];
        }

        $results = [];
        $synced = 0;
        $failed = 0;

        foreach ($distributions as $dist) {
            $hubId = (int) $dist['hub_id'];
            $remotePostId = (int) ($dist['remote_post_id'] ?? 0);
            $hub = Hub::query()->find($hubId);
            $base = [
                'hub_id' => $hubId,
                'hub_name' => $dist['hub_name'],
                'remote_post_id' => $remotePostId ?: null,
            ];

            if (! $hub || ! $hub->isContentHub() || ! $hub->hasRemoteDatabaseConfigured() || $remotePostId < 1) {
                $failed++;
                $results[] = array_merge($base, [
                    'status' => 'failed',
                    'message' => 'Missing hub credentials or remote post id.',
                ]);
                continue;
            }

            try {
                $connection = $this->remoteDb->connect($hub);
                $exists = DB::connection($connection)->table('posts')->where('id', $remotePostId)->exists();
                if (! $exists) {
                    $failed++;
                    $results[] = array_merge($base, [
                        'status' => 'failed',
                        'message' => 'Remote post no longer exists on '.$hub->name.'.',
                    ]);
                    continue;
                }

                $this->ensureTaxonomyForPost($connection, $post);
                $this->ensurePostCategoriesColumn($connection);

                $updates = [
                    'title' => $post->title,
                    'description' => $post->description,
                    'type' => $post->type,
                    'categories' => json_encode(array_values($post->categories ?? [])),
                    'tags' => json_encode(array_values($post->tags ?? [])),
                    'credits_cost' => $post->credits_cost,
                    'attachment_path' => $this->remoteAttachmentPath($post),
                    'attachment_name' => $post->attachment_name,
                    'attachment_mime' => $post->attachment_mime,
                    'is_active' => (bool) $post->is_active,
                    'updated_at' => now(),
                ];

                if (Schema::connection($connection)->hasColumn('posts', 'canva_link')) {
                    $updates['canva_link'] = $post->canva_link;
                }

                DB::connection($connection)->table('posts')->where('id', $remotePostId)->update($updates);

                $synced++;
                $results[] = array_merge($base, [
                    'status' => 'success',
                    'message' => 'Updated on '.$hub->name.'.',
                ]);
            } catch (Throwable $e) {
                $failed++;
                $results[] = array_merge($base, [
                    'status' => 'failed',
                    'message' => $e->getMessage(),
                ]);
            } finally {
                $this->remoteDb->disconnect($hub);
            }
        }

        return [
            'synced' => $synced,
            'failed' => $failed,
            'results' => $results,
        ];
    }
}
