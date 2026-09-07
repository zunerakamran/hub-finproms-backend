<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Post extends Model
{
    protected $fillable = [
        'created_by',
        'title',
        'description',
        'category',
        'tags',
        'credits_cost',
        'attachment_path',
        'attachment_name',
        'attachment_mime',
        'is_active',
    ];

    protected $appends = [
        'attachment_url',
        'last_updated',
    ];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'credits_cost' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function getAttachmentUrlAttribute(): ?string
    {
        if (! $this->attachment_path) {
            return null;
        }

        return Storage::disk('public')->url($this->attachment_path);
    }

    public function getLastUpdatedAttribute(): string
    {
        return $this->updated_at?->toIso8601String() ?? '';
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(PostPurchase::class);
    }
}
