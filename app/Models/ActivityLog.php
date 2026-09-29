<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'hub_id',
        'user_id',
        'user_name',
        'user_email',
        'user_role',
        'action',
        'description',
        'subject_type',
        'subject_id',
        'method',
        'path',
        'route_name',
        'ip_address',
        'user_agent',
        'status_code',
        'properties',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'properties' => 'array',
            'created_at' => 'datetime',
            'status_code' => 'integer',
            'subject_id' => 'integer',
        ];
    }

    public function hub(): BelongsTo
    {
        return $this->belongsTo(Hub::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
