<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class SubscriptionPlan extends Model
{
    protected $fillable = [
        'name',
        'description',
        'overview',
        'features',
        'benefits',
        'image_path',
        'price',
        'credits',
        'duration_days',
        'is_active',
        'show_reach',
        'show_views',
        'show_buys',
        'last_updated',
    ];

    protected $appends = [
        'image_url',
        'visible_metrics',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'credits' => 'integer',
            'duration_days' => 'integer',
            'is_active' => 'boolean',
            'show_reach' => 'boolean',
            'show_views' => 'boolean',
            'show_buys' => 'boolean',
            'features' => 'array',
            'benefits' => 'array',
            'last_updated' => 'datetime',
        ];
    }

    /**
     * Which post metrics subscribers on this plan may see.
     *
     * @return array{reach: bool, views: bool, buys: bool}
     */
    public function getVisibleMetricsAttribute(): array
    {
        return [
            'reach' => (bool) $this->show_reach,
            'views' => (bool) $this->show_views,
            'buys' => (bool) $this->show_buys,
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (SubscriptionPlan $plan): void {
            $plan->last_updated = now();
        });

        static::deleting(function (SubscriptionPlan $plan): void {
            if ($plan->image_path) {
                Storage::disk('public')->delete($plan->image_path);
            }
        });
    }

    public function getImageUrlAttribute(): ?string
    {
        if (! $this->image_path) {
            return null;
        }

        return Storage::disk('public')->url($this->image_path);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(UserSubscription::class);
    }
}
