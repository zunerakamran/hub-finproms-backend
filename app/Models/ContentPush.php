<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentPush extends Model
{
    protected $fillable = [
        'source_hub_id',
        'target_hub_id',
        'post_id',
        'entity_type',
        'entity_id',
        'entity_label',
        'pushed_by',
        'remote_post_id',
        'status',
        'message',
    ];

    protected function casts(): array
    {
        return [
            'remote_post_id' => 'integer',
        ];
    }

    public function sourceHub(): BelongsTo
    {
        return $this->belongsTo(Hub::class, 'source_hub_id');
    }

    public function targetHub(): BelongsTo
    {
        return $this->belongsTo(Hub::class, 'target_hub_id');
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pushed_by');
    }
}
