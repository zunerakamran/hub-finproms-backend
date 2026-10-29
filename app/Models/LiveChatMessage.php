<?php

namespace App\Models;

use App\Models\WebsiteCompliance\UsesWcDatabaseContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LiveChatMessage extends Model
{
    use UsesWcDatabaseContext;

    protected $table = 'live_chat_messages';

    public const SIDE_HUB = 'hub';

    public const SIDE_CENTRAL = 'central';

    protected $fillable = [
        'conversation_id',
        'sender_side',
        'sender_user_id',
        'sender_name',
        'body',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(LiveChatConversation::class, 'conversation_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'sender_side' => $this->sender_side,
            'sender_user_id' => $this->sender_user_id,
            'sender_name' => $this->sender_name,
            'body' => $this->body,
            'read_at' => optional($this->read_at)?->toIso8601String(),
            'created_at' => optional($this->created_at)?->toIso8601String(),
        ];
    }
}
