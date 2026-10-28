<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LiveChatConversation extends Model
{
    protected $table = 'live_chat_conversations';

    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSED = 'closed';

    /**
     * @var list<string>
     */
    public const STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_CLOSED,
    ];

    protected $fillable = [
        'hub_user_id',
        'hub_user_name',
        'hub_user_email',
        'central_user_id',
        'central_user_name',
        'central_user_email',
        'status',
        'last_message_at',
        'hub_unread_count',
        'central_unread_count',
    ];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
            'hub_unread_count' => 'integer',
            'central_unread_count' => 'integer',
        ];
    }

    public function messages(): HasMany
    {
        return $this->hasMany(LiveChatMessage::class, 'conversation_id');
    }

    public function hubUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'hub_user_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(bool $includeMessages = false): array
    {
        $last = $this->relationLoaded('messages')
            ? $this->messages->sortByDesc('id')->first()
            : $this->messages()->latest('id')->first();

        $payload = [
            'id' => $this->id,
            'hub_user_id' => $this->hub_user_id,
            'hub_user_name' => $this->hub_user_name,
            'hub_user_email' => $this->hub_user_email,
            'central_user_id' => $this->central_user_id,
            'central_user_name' => $this->central_user_name,
            'central_user_email' => $this->central_user_email,
            'status' => $this->status ?: self::STATUS_OPEN,
            'last_message_at' => optional($this->last_message_at)?->toIso8601String(),
            'hub_unread_count' => (int) $this->hub_unread_count,
            'central_unread_count' => (int) $this->central_unread_count,
            'preview' => $last ? \Illuminate\Support\Str::limit((string) $last->body, 120) : null,
            'created_at' => optional($this->created_at)?->toIso8601String(),
            'updated_at' => optional($this->updated_at)?->toIso8601String(),
        ];

        if ($includeMessages) {
            $messages = $this->relationLoaded('messages')
                ? $this->messages
                : $this->messages()->orderBy('id')->get();
            $payload['messages'] = $messages->map(
                fn (LiveChatMessage $m) => $m->toApiArray()
            )->values()->all();
        }

        return $payload;
    }
}
