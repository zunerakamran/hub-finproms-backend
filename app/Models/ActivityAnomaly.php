<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityAnomaly extends Model
{
    public const SEVERITY_INFO = 'info';

    public const SEVERITY_LOW = 'low';

    public const SEVERITY_MEDIUM = 'medium';

    public const SEVERITY_HIGH = 'high';

    /**
     * @var list<string>
     */
    public const SEVERITIES = [
        self::SEVERITY_INFO,
        self::SEVERITY_LOW,
        self::SEVERITY_MEDIUM,
        self::SEVERITY_HIGH,
    ];

    public const STATUS_OPEN = 'open';

    public const STATUS_REVIEWED = 'reviewed';

    public const STATUS_DISMISSED = 'dismissed';

    public const STATUS_ESCALATED = 'escalated';

    /**
     * @var list<string>
     */
    public const STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_REVIEWED,
        self::STATUS_DISMISSED,
        self::STATUS_ESCALATED,
    ];

    public const TYPE_FAILED_LOGIN_BURST = 'auth.failed_login_burst';

    public const TYPE_NEW_IP_LOGIN = 'auth.new_ip_login';

    public const TYPE_ADMIN_ACTION_BURST = 'admin.action_burst';

    public const TYPE_EXPORT_VOLUME = 'data.export_volume';

    public const TYPE_ACTION_RATE = 'api.action_rate';

    /**
     * @var array<string, string>
     */
    public const TYPE_LABELS = [
        self::TYPE_FAILED_LOGIN_BURST => 'Failed / blocked login burst',
        self::TYPE_NEW_IP_LOGIN => 'Login from uncommon IP',
        self::TYPE_ADMIN_ACTION_BURST => 'Admin action burst',
        self::TYPE_EXPORT_VOLUME => 'Unusual export / report volume',
        self::TYPE_ACTION_RATE => 'Unusual activity rate',
    ];

    protected $fillable = [
        'hub_id',
        'detected_at',
        'window_start',
        'window_end',
        'anomaly_type',
        'severity',
        'title',
        'description',
        'status',
        'actor_user_id',
        'actor_name',
        'actor_email',
        'actor_role',
        'source_ip',
        'related_activity_log_ids',
        'properties',
        'security_event_id',
        'security_incident_id',
        'reviewed_by_user_id',
        'reviewed_by_name',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'detected_at' => 'datetime',
            'window_start' => 'datetime',
            'window_end' => 'datetime',
            'reviewed_at' => 'datetime',
            'related_activity_log_ids' => 'array',
            'properties' => 'array',
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

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(bool $includeDetails = false): array
    {
        $data = [
            'id' => $this->id,
            'hub_id' => $this->hub_id,
            'detected_at' => optional($this->detected_at)?->toIso8601String(),
            'window_start' => optional($this->window_start)?->toIso8601String(),
            'window_end' => optional($this->window_end)?->toIso8601String(),
            'anomaly_type' => $this->anomaly_type,
            'anomaly_type_label' => self::TYPE_LABELS[$this->anomaly_type] ?? $this->anomaly_type,
            'severity' => $this->severity,
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status,
            'actor' => [
                'user_id' => $this->actor_user_id,
                'name' => $this->actor_name,
                'email' => $this->actor_email,
                'role' => $this->actor_role,
            ],
            'source_ip' => $this->source_ip,
            'security_event_id' => $this->security_event_id,
            'security_incident_id' => $this->security_incident_id,
            'reviewed_at' => optional($this->reviewed_at)?->toIso8601String(),
            'reviewed_by_name' => $this->reviewed_by_name,
            'created_at' => optional($this->created_at)?->toIso8601String(),
        ];

        if ($includeDetails) {
            $data['related_activity_log_ids'] = $this->related_activity_log_ids ?? [];
            $data['properties'] = $this->properties ?? [];
            $data['reviewed_by_user_id'] = $this->reviewed_by_user_id;
        }

        return $data;
    }
}
