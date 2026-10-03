<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComplianceAuditEvent extends Model
{
    public $timestamps = false;

    public const MODULE_SMC = 'smc';

    public const MODULE_GC = 'gc';

    public const MODULE_WC = 'wc';

    public const EVENT_SUBMITTED = 'submitted';

    public const EVENT_ASSIGNED = 'assigned';

    public const EVENT_UNASSIGNED = 'unassigned';

    public const EVENT_REVIEWED = 'reviewed';

    public const EVENT_RESUBMITTED = 'resubmitted';

    public const EVENT_FEEDBACK_CONFIRMED = 'feedback_confirmed';

    public const EVENT_STATUS_CHANGED = 'status_changed';

    public const EVENT_SCHEDULED = 'scheduled';

    public const EVENT_PUBLISHED = 'published';

    public const EVENT_REJECTED = 'rejected';

    protected $fillable = [
        'hub_id',
        'module',
        'subject_type',
        'subject_id',
        'event_type',
        'description',
        'from_status',
        'to_status',
        'version_number',
        'actor_user_id',
        'actor_name',
        'actor_email',
        'actor_role',
        'related_user_id',
        'related_user_name',
        'related_user_email',
        'related_user_role',
        'metadata',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
            'subject_id' => 'integer',
            'version_number' => 'integer',
            'hub_id' => 'integer',
            'actor_user_id' => 'integer',
            'related_user_id' => 'integer',
        ];
    }

    public function hub(): BelongsTo
    {
        return $this->belongsTo(Hub::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function relatedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'related_user_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'module' => $this->module,
            'subject_id' => $this->subject_id,
            'event_type' => $this->event_type,
            'description' => $this->description,
            'from_status' => $this->from_status,
            'to_status' => $this->to_status,
            'version_number' => $this->version_number,
            'actor' => [
                'id' => $this->actor_user_id,
                'name' => $this->actor_name,
                'email' => $this->actor_email,
                'role' => $this->actor_role,
            ],
            'related_user' => ($this->related_user_id || $this->related_user_name || $this->related_user_email)
                ? [
                    'id' => $this->related_user_id,
                    'name' => $this->related_user_name,
                    'email' => $this->related_user_email,
                    'role' => $this->related_user_role,
                ]
                : null,
            'metadata' => $this->metadata ?: new \stdClass,
            'created_at' => optional($this->created_at)?->toIso8601String(),
        ];
    }
}
