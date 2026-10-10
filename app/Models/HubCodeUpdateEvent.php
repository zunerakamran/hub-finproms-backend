<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HubCodeUpdateEvent extends Model
{
    public const ACTION_APPLY = 'apply';

    public const ACTION_MARK_MANUAL = 'mark_manual';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'hub_id',
        'hub_slug',
        'hub_name',
        'hub_release_id',
        'version',
        'action',
        'status',
        'message',
        'backend_applied',
        'frontend_applied',
        'migrated',
        'triggered_by',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'backend_applied' => 'boolean',
            'frontend_applied' => 'boolean',
            'migrated' => 'boolean',
            'finished_at' => 'datetime',
        ];
    }

    public function hub(): BelongsTo
    {
        return $this->belongsTo(Hub::class);
    }

    public function release(): BelongsTo
    {
        return $this->belongsTo(HubRelease::class, 'hub_release_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }

    /**
     * @return array<string, mixed>
     */
    public function toAdminArray(): array
    {
        return [
            'id' => $this->id,
            'hub_id' => $this->hub_id,
            'hub_slug' => $this->hub_slug,
            'hub_name' => $this->hub_name,
            'hub_release_id' => $this->hub_release_id,
            'version' => $this->version,
            'action' => $this->action,
            'action_label' => $this->action === self::ACTION_MARK_MANUAL
                ? 'Marked manually'
                : 'Applied release',
            'status' => $this->status,
            'status_label' => $this->status === self::STATUS_SUCCESS ? 'Success' : 'Failed',
            'message' => $this->message,
            'backend_applied' => $this->backend_applied,
            'frontend_applied' => $this->frontend_applied,
            'migrated' => $this->migrated,
            'triggered_by' => $this->triggered_by,
            'triggered_by_name' => $this->actor?->name,
            'finished_at' => $this->finished_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
