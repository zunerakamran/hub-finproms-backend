<?php

namespace App\Models;

use App\Models\WebsiteCompliance\UsesWcDatabaseContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class SupportTicketAttachment extends Model
{
    use UsesWcDatabaseContext;

    protected $table = 'support_ticket_attachments';

    protected $fillable = [
        'ticket_id',
        'original_name',
        'file_path',
        'file_url',
        'mime_type',
        'size_bytes',
        'sort_order',
        'uploaded_by_user_id',
        'uploaded_by_name',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'sort_order' => 'integer',
            'uploaded_by_user_id' => 'integer',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'ticket_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
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
            'ticket_id' => $this->ticket_id,
            'original_name' => $this->original_name,
            'file_path' => $this->file_path,
            'file_url' => $this->resolvedFileUrl(),
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
            'sort_order' => $this->sort_order,
            'uploaded_by_user_id' => $this->uploaded_by_user_id ? (int) $this->uploaded_by_user_id : null,
            'uploaded_by_name' => $this->uploaded_by_name,
            'created_at' => optional($this->created_at)?->toIso8601String(),
        ];
    }
}
