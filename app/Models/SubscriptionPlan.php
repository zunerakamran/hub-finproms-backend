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
        'last_updated',
    ];

    protected $appends = [
        'image_url',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'credits' => 'integer',
            'duration_days' => 'integer',
            'is_active' => 'boolean',
            'features' => 'array',
            'benefits' => 'array',
            'last_updated' => 'datetime',
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
