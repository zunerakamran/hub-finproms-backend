<?php

namespace App\Models;

use App\Models\WebsiteCompliance\UsesWcDatabaseContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupportTicketComment extends Model
{
    use UsesWcDatabaseContext;

    protected $table = 'support_ticket_comments';

    protected $fillable = [
        'ticket_id',
        'user_id',
        'author_name',
        'body',
        'from_status',
        'to_status',
        'estimated_resolve_at',
    ];

    protected function casts(): array
    {
        return [
            'estimated_resolve_at' => 'datetime',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'ticket_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(SupportTicketAttachment::class, 'comment_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        try {
            $this->loadMissing(['user:id,name,email,role', 'attachments']);
        } catch (\Throwable) {
            // Actor may not exist on this hub DB (e.g. Central Power Admin).
            $this->unsetRelation('user');
            try {
                $this->loadMissing(['attachments']);
            } catch (\Throwable) {
                $this->unsetRelation('attachments');
            }
        }

        $authorName = $this->user?->name ?: $this->author_name;

        return [
            'id' => $this->id,
            'ticket_id' => $this->ticket_id,
            'user_id' => $this->user_id,
            'author_name' => $authorName,
            'body' => $this->body,
            'from_status' => $this->from_status,
            'to_status' => $this->to_status,
            'estimated_resolve_at' => optional($this->estimated_resolve_at)?->toIso8601String(),
            'is_status_change' => $this->from_status !== null || $this->to_status !== null,
            'is_staff_reply' => $this->from_status !== null || $this->to_status !== null
                || $this->estimated_resolve_at !== null,
            'author' => $authorName ? [
                'id' => $this->user?->id,
                'name' => $authorName,
                'email' => $this->user?->email,
                'role' => $this->user?->role,
            ] : null,
            'attachments' => $this->relationLoaded('attachments')
                ? $this->attachments
                    ->map(fn (SupportTicketAttachment $a) => $a->toApiArray())
                    ->values()
                    ->all()
                : [],
            'created_at' => optional($this->created_at)?->toIso8601String(),
            'updated_at' => optional($this->updated_at)?->toIso8601String(),
        ];
    }
}
