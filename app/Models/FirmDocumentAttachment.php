<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class FirmDocumentAttachment extends Model
{
    protected $fillable = [
        'firm_document_id',
        'original_name',
        'file_path',
        'file_url',
        'mime_type',
        'size_bytes',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(FirmDocument::class, 'firm_document_id');
    }

    public function resolvedFileUrl(): ?string
    {
        if (filled($this->file_url)) {
            return $this->file_url;
        }

        if (! filled($this->file_path)) {
            return null;
        }

        if (str_starts_with($this->file_path, 'http://')
            || str_starts_with($this->file_path, 'https://')
            || str_starts_with($this->file_path, '/')) {
            return $this->file_path;
        }

        return Storage::disk('public')->url($this->file_path);
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'firm_document_id' => (int) $this->firm_document_id,
            'original_name' => $this->original_name,
            'file_path' => $this->file_path,
            'file_url' => $this->resolvedFileUrl(),
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
            'sort_order' => $this->sort_order,
        ];
    }
}
