<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FirmDocument extends Model
{
    protected $fillable = [
        'firm_id',
        'title',
        'description',
        'uploaded_by',
        'archived_at',
        'archived_by',
    ];

    protected function casts(): array
    {
        return [
            'archived_at' => 'datetime',
        ];
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function archiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'archived_by');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(FirmDocumentAttachment::class)->orderBy('sort_order')->orderBy('id');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'firm_id' => (int) $this->firm_id,
            'title' => $this->title,
            'description' => $this->description,
            'uploaded_by' => $this->uploaded_by ? (int) $this->uploaded_by : null,
            'uploader' => $this->uploader ? [
                'id' => (int) $this->uploader->id,
                'name' => (string) $this->uploader->name,
                'email' => (string) $this->uploader->email,
            ] : null,
            'archived_at' => $this->archived_at?->toIso8601String(),
            'archived_by' => $this->archived_by ? (int) $this->archived_by : null,
            'is_archived' => $this->isArchived(),
            'attachments' => $this->relationLoaded('attachments')
                ? $this->attachments->map(fn (FirmDocumentAttachment $a) => $a->toApiArray())->values()->all()
                : [],
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
