<?php

namespace App\Services;

use App\Models\Hub;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
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
     */
    public function targetHubsForDropdown(): array
    {
        $shared = $this->hubs->current();
        $items = [];

        if ($shared->isShared()) {
            $items[] = [
                'id' => $shared->id,
                'name' => $shared->name,
                'slug' => $shared->slug,
                'type' => 'shared',
                'eligible' => true,
                'label' => $shared->name.' (shared — this hub)',
            ];
        }

        foreach (
            Hub::query()
                ->where('type', Hub::TYPE_WHITE_LABEL)
                ->where('is_active', true)
                ->orderBy('name')
                ->get() as $hub
        ) {
            $eligible = $hub->can('receive_content_from_shared') && $hub->hasRemoteDatabaseConfigured();
            $items[] = [
                'id' => $hub->id,
                'name' => $hub->name,
                'slug' => $hub->slug,
                'type' => 'white_label',
                'eligible' => $eligible,
                'label' => $hub->name.($eligible ? ' (white-label)' : ' (not ready)'),
            ];
        }

        return $items;
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
            $id = (int) DB::connection($connection)->table('bundles')->insertGetId([
                'created_by' => $this->resolveRemoteCreatorId($connection),
                'title' => $payload['title'],
                'description' => $payload['description'] ?? null,
                'credits_cost' => (int) $payload['credits_cost'],
                'is_active' => (bool) ($payload['is_active'] ?? true),
                'buy_count' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

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
            ];
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
}
