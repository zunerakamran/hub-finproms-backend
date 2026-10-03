<?php

namespace App\Models;

use App\Models\WebsiteCompliance\UsesWcDatabaseContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'ticket_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        try {
            $this->loadMissing(['user:id,name,email,role']);
        } catch (\Throwable) {
            // Actor may not exist on this hub DB (e.g. Central Power Admin).
            $this->unsetRelation('user');
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
            'is_status_change' => $this->from_status !== null || $this->to_status !== null,
            'author' => $authorName ? [
                'id' => $this->user?->id,
                'name' => $authorName,
                'email' => $this->user?->email,
                'role' => $this->user?->role,
            ] : null,
            'created_at' => optional($this->created_at)?->toIso8601String(),
            'updated_at' => optional($this->updated_at)?->toIso8601String(),
        ];
    }
}
