<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PostReach extends Model
{
    protected $fillable = [
        'post_id',
        'viewer_key',
    ];

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }
}
