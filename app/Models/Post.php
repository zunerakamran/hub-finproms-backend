<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Post extends Model
{
    /** @var array<string, string>|null */
    protected static ?array $typeSlugMap = null;

    protected $fillable = [
        'created_by',
        'title',
        'description',
        'type',
        'category',
        'tags',
        'credits_cost',
        'attachment_path',
        'attachment_name',
        'attachment_mime',
        'is_active',
        'views_count',
        'reach_count',
        'buy_count',
    ];

    protected $appends = [
        'attachment_url',
        'cover_url',
        'last_updated',
        'content_type',
        'is_reel',
        'is_new',
    ];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'credits_cost' => 'integer',
            'is_active' => 'boolean',
            'views_count' => 'integer',
            'reach_count' => 'integer',
            'buy_count' => 'integer',
        ];
    }

    public function getAttachmentUrlAttribute(): ?string
    {
        if (! $this->attachment_path) {
            return null;
        }

        return Storage::disk('public')->url($this->attachment_path);
    }

    public function getCoverUrlAttribute(): ?string
    {
        if (! $this->attachment_path || ! $this->isImageAttachment()) {
            return null;
        }

        return Storage::disk('public')->url($this->attachment_path);
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

    public function reaches(): HasMany
    {
        return $this->hasMany(PostReach::class);
    }

    public function recordView(string $viewerKey): void
    {
        $this->increment('views_count');

        $created = PostReach::query()->firstOrCreate([
            'post_id' => $this->id,
            'viewer_key' => $viewerKey,
        ]);

        if ($created->wasRecentlyCreated) {
            $this->increment('reach_count');
        }

        $this->refresh();
    }
}
