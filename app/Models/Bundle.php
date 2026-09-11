<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Bundle extends Model
{
    protected $fillable = [
        'created_by',
        'title',
        'description',
        'credits_cost',
        'is_active',
        'buy_count',
    ];

    protected $appends = [
        'posts_count',
    ];

    protected function casts(): array
    {
        return [
            'credits_cost' => 'integer',
            'is_active' => 'boolean',
            'buy_count' => 'integer',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(Post::class, 'bundle_post')
            ->withPivot('sort_order')
            ->withTimestamps()
            ->orderByPivot('sort_order');
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(BundlePurchase::class);
    }

    public function getPostsCountAttribute(): int
    {
        if (array_key_exists('posts_count', $this->attributes)) {
            return (int) $this->attributes['posts_count'];
        }

        if ($this->relationLoaded('posts')) {
            return $this->posts->count();
        }

        return $this->posts()->count();
    }

    /**
     * Sync posts onto the bundle preserving order.
     *
     * @param  list<int>  $postIds
     */
    public function syncOrderedPosts(array $postIds): void
    {
        $sync = [];
        foreach (array_values($postIds) as $index => $postId) {
            $sync[(int) $postId] = ['sort_order' => $index];
        }

        $this->posts()->sync($sync);
    }
}
