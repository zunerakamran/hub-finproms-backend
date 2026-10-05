<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HubBackup extends Model
{
    public const LOCATION_LOCAL = 'local';

    public const LOCATION_CENTRAL = 'central';

    public const TRIGGER_SCHEDULE = 'schedule';

    public const TRIGGER_MANUAL = 'manual';

    public const TRIGGER_RECEIVE = 'receive';

    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'hub_id',
        'hub_slug',
        'location',
        'triggered_by',
        'status',
        'filename',
        'disk_path',
        'size_bytes',
        'checksum',
        'includes_database',
        'includes_files',
        'error_message',
        'created_by_user_id',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'includes_database' => 'boolean',
            'includes_files' => 'boolean',
            'size_bytes' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    public function hub(): BelongsTo
    {
        return $this->belongsTo(Hub::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function absolutePath(): ?string
    {
        if (! $this->disk_path) {
            return null;
        }

        return storage_path('app/'.$this->disk_path);
    }

    public function toAdminArray(): array
    {
        return [
            'id' => $this->id,
            'hub_id' => $this->hub_id,
            'hub_slug' => $this->hub_slug,
            'location' => $this->location,
            'triggered_by' => $this->triggered_by,
            'status' => $this->status,
            'filename' => $this->filename,
            'size_bytes' => $this->size_bytes,
            'checksum' => $this->checksum,
            'includes_database' => $this->includes_database,
            'includes_files' => $this->includes_files,
            'error_message' => $this->error_message,
            'created_by_user_id' => $this->created_by_user_id,
            'completed_at' => $this->completed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'downloadable' => $this->status === self::STATUS_COMPLETED && $this->disk_path,
        ];
    }
}
