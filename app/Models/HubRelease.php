<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HubRelease extends Model
{
    protected $fillable = [
        'version',
        'backend_version',
        'frontend_version',
        'notes',
        'is_latest',
        'published_by',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'is_latest' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    /**
     * @return array<string, mixed>
     */
    public function toAdminArray(): array
    {
        return [
            'id' => $this->id,
            'version' => $this->version,
            'backend_version' => $this->backend_version ?: $this->version,
            'frontend_version' => $this->frontend_version ?: $this->version,
            'notes' => $this->notes,
            'is_latest' => (bool) $this->is_latest,
            'published_by' => $this->published_by,
            'published_at' => $this->published_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
