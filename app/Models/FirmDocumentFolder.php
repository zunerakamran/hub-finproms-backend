<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FirmDocumentFolder extends Model
{
    protected $fillable = [
        'firm_id',
        'parent_id',
        'name',
        'created_by',
    ];

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('name')->orderBy('id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(FirmDocument::class, 'folder_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(bool $withChildren = false): array
    {
        $payload = [
            'id' => $this->id,
            'firm_id' => (int) $this->firm_id,
            'parent_id' => $this->parent_id ? (int) $this->parent_id : null,
            'name' => $this->name,
            'created_by' => $this->created_by ? (int) $this->created_by : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];

        if ($withChildren && $this->relationLoaded('children')) {
            $payload['children'] = $this->children
                ->map(fn (self $child) => $child->toApiArray(true))
                ->values()
                ->all();
        }

        return $payload;
    }
}
