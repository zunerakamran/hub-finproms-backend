<?php

namespace App\Models;

use App\Models\WebsiteCompliance\UsesWcDatabaseContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Post extends Model
{
    use UsesWcDatabaseContext;

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_AI = 'ai';

    public const SOURCE_IMPORT = 'import';

    /** @var array<string, string>|null */
    protected static ?array $typeSlugMap = null;

    protected $fillable = [
        'created_by',
        'title',
        'description',
        'type',
        'categories',
        'tags',
        'credits_cost',
        'attachment_path',
        'attachment_name',
        'attachment_mime',
        'canva_link',
        'is_active',
        'creation_source',
        'archived_at',
        'archive_remarks',
        'archived_by',
        'views_count',
        'reach_count',
        'buy_count',
    ];

    protected $appends = [
        'attachment_url',
        'cover_url',
        'video_url',
        'is_video',
        'last_updated',
        'content_type',
        'is_reel',
        'is_new',
        'category',
        'is_archived',
    ];

    protected function casts(): array
    {
        return [
            'categories' => 'array',
            'tags' => 'array',
            'credits_cost' => 'integer',
            'is_active' => 'boolean',
            'views_count' => 'integer',
            'reach_count' => 'integer',
            'buy_count' => 'integer',
            'archived_at' => 'datetime',
        ];
    }

    public function getIsArchivedAttribute(): bool
    {
        return $this->archived_at !== null;
    }

    public function isAiSource(): bool
    {
        return ($this->creation_source ?? self::SOURCE_MANUAL) === self::SOURCE_AI;
    }

    public function isManualSource(): bool
    {
        return ! $this->isAiSource();
    }

    /**
     * Retire a Central-library post from distribution. Does not delete the row
     * and does not hide it from the library list — only blocks distribute.
     */
    public function archive(string $remarks, ?User $actor = null): void
    {
        $remarks = trim($remarks);
        if ($remarks === '') {
            throw new \InvalidArgumentException('Archive remarks are required.');
        }

        $this->forceFill([
            'archived_at' => now(),
            'archive_remarks' => $remarks,
            'archived_by' => $actor?->id,
        ])->save();
    }

    /**
     * Restore a Central-library post so it can be distributed again.
     */
    public function unarchive(): void
    {
        $this->forceFill([
            'archived_at' => null,
            'archive_remarks' => null,
            'archived_by' => null,
        ])->save();
    }

    public function archiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'archived_by');
    }

    /**
     * Legacy single-category display string (first / joined names).
     */
    public function getCategoryAttribute(): ?string
    {
        $list = array_values(array_filter($this->categories ?? []));

        if ($list === []) {
            return null;
        }

        return implode(', ', $list);
    }

    public function getAttachmentUrlAttribute(): ?string
    {
        if (! $this->attachment_path) {
            return null;
        }

        if ($this->attachmentPathIsAbsolute()) {
            return $this->attachment_path;
        }

        return Storage::disk('public')->url($this->attachment_path);
    }

    public function getCoverUrlAttribute(): ?string
    {
        if (! $this->attachment_path || ! $this->isImageAttachment()) {
            return null;
        }

        if ($this->attachmentPathIsAbsolute()) {
            return $this->attachment_path;
        }

        return Storage::disk('public')->url($this->attachment_path);
    }

    /**
     * Preview URL for video attachments (reels). Visible when browsing,
     * same gating as cover_url — full asset preview stays on attachment_url.
     */
    public function getVideoUrlAttribute(): ?string
    {
        if (! $this->attachment_path || ! $this->isVideoAttachment()) {
            return null;
        }

        if ($this->attachmentPathIsAbsolute()) {
            return $this->attachment_path;
        }

        return Storage::disk('public')->url($this->attachment_path);
    }

    protected function attachmentPathIsAbsolute(): bool
    {
        $path = (string) $this->attachment_path;

        return str_starts_with($path, 'http://') || str_starts_with($path, 'https://');
    }

    public function getIsVideoAttribute(): bool
    {
        return $this->isVideoAttachment();
    }

    public function isImageAttachment(): bool
    {
        $mime = strtolower((string) $this->attachment_mime);

        if (str_starts_with($mime, 'image/')) {
            return true;
        }

        $extension = strtolower(pathinfo((string) $this->attachment_name, PATHINFO_EXTENSION));

        return in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);
    }

    public function isVideoAttachment(): bool
    {
        $mime = strtolower((string) $this->attachment_mime);

        if (str_starts_with($mime, 'video/')) {
            return true;
        }

        $extension = strtolower(pathinfo((string) $this->attachment_name, PATHINFO_EXTENSION));

        return in_array($extension, ['mp4', 'mov', 'webm'], true);
    }

    public function getLastUpdatedAttribute(): string
    {
        return $this->updated_at?->toIso8601String() ?? '';
    }

    /**
     * Slug for the content TYPE (post, reel, …) — separate from category.
     */
    public function getContentTypeAttribute(): string
    {
        $map = static::typeSlugMap();

        if (isset($map[$this->type]) && $map[$this->type] !== '') {
            return $map[$this->type];
        }

        return str((string) $this->type)->slug()->toString() ?: 'post';
    }

    /**
     * @return array<string, string>
     */
    protected static function typeSlugMap(): array
    {
        return static::$typeSlugMap ??= ContentType::query()
            ->pluck('slug', 'name')
            ->map(fn ($slug) => (string) $slug)
            ->all();
    }

    public static function clearTypeSlugMap(): void
    {
        static::$typeSlugMap = null;
    }

    /** @deprecated Use clearTypeSlugMap */
    public static function clearCategorySlugMap(): void
    {
        static::clearTypeSlugMap();
    }

    public function getIsReelAttribute(): bool
    {
        $type = strtolower($this->content_type);

        return in_array($type, ['reel', 'reels'], true);
    }

    public function getIsNewAttribute(): bool
    {
        $days = Setting::newBannerDays();

        if ($days <= 0 || ! $this->created_at) {
            return false;
        }

        return $this->created_at->gte(now()->subDays($days));
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(PostPurchase::class);
    }

    public function socialMediaComplianceRequests(): HasMany
    {
        return $this->hasMany(SocialMediaComplianceRequest::class);
    }

    public function bundles(): BelongsToMany
    {
        return $this->belongsToMany(Bundle::class, 'bundle_post')
            ->withPivot('sort_order')
            ->withTimestamps();
    }

    public function reaches(): HasMany
    {
        return $this->hasMany(PostReach::class);
    }

    /**
     * Reach: unique impression when the post appears during listing scroll.
     */
    public function recordReach(string $viewerKey): bool
    {
        $created = PostReach::query()->firstOrCreate([
            'post_id' => $this->id,
            'viewer_key' => $viewerKey,
        ]);

        if ($created->wasRecentlyCreated) {
            $this->increment('reach_count');

            return true;
        }

        return false;
    }

    /**
     * View: user opened the post detail page.
     */
    public function recordView(): void
    {
        $this->increment('views_count');
    }
}
