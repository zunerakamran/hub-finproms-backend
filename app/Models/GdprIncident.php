<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GdprIncident extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_INVESTIGATING = 'investigating';

    public const STATUS_CONTAINED = 'contained';

    public const STATUS_CLOSED = 'closed';

    public const SEVERITY_UNKNOWN = 'unknown';

    public const SEVERITY_LOW = 'low';

    public const SEVERITY_MEDIUM = 'medium';

    public const SEVERITY_HIGH = 'high';

    public const SEVERITY_CRITICAL = 'critical';

    protected $fillable = [
        'reported_by_user_id',
        'title',
        'summary',
        'severity',
        'status',
        'discovered_at',
        'occurred_at',
        'ico_notified',
        'ico_notified_at',
        'individuals_notified',
        'individuals_notified_at',
        'affected_estimate',
        'actions_taken',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'discovered_at' => 'datetime',
            'occurred_at' => 'datetime',
            'ico_notified' => 'boolean',
            'ico_notified_at' => 'datetime',
            'individuals_notified' => 'boolean',
            'individuals_notified_at' => 'datetime',
            'affected_estimate' => 'integer',
        ];
    }

    public function reportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by_user_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        $this->loadMissing('reportedBy:id,name,email,role');

        return [
            'id' => $this->id,
            'title' => $this->title,
            'summary' => $this->summary,
            'severity' => $this->severity,
            'status' => $this->status,
            'discovered_at' => $this->discovered_at?->toIso8601String(),
            'occurred_at' => $this->occurred_at?->toIso8601String(),
            'ico_notified' => (bool) $this->ico_notified,
            'ico_notified_at' => $this->ico_notified_at?->toIso8601String(),
            'individuals_notified' => (bool) $this->individuals_notified,
            'individuals_notified_at' => $this->individuals_notified_at?->toIso8601String(),
            'affected_estimate' => $this->affected_estimate,
            'actions_taken' => $this->actions_taken,
            'notes' => $this->notes,
            'reported_by' => $this->reportedBy ? [
                'id' => $this->reportedBy->id,
                'name' => $this->reportedBy->name,
                'email' => $this->reportedBy->email,
                'role' => $this->reportedBy->role,
            ] : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
