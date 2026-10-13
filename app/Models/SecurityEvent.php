<?php

namespace App\Models;

use App\Models\WebsiteCompliance\UsesWcDatabaseContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SecurityEvent extends Model
{
    use UsesWcDatabaseContext;

    protected $table = 'security_events';

    public const CATEGORY_AUTH = 'auth';

    public const CATEGORY_AUTHORIZATION = 'authorization';

    public const CATEGORY_DATA = 'data';

    public const CATEGORY_DEPENDENCY = 'dependency';

    public const CATEGORY_MANUAL = 'manual';

    public const CATEGORY_OTHER = 'other';

    /**
     * @var list<string>
     */
    public const CATEGORIES = [
        self::CATEGORY_AUTH,
        self::CATEGORY_AUTHORIZATION,
        self::CATEGORY_DATA,
        self::CATEGORY_DEPENDENCY,
        self::CATEGORY_MANUAL,
        self::CATEGORY_OTHER,
    ];

    /**
     * @var array<string, string>
     */
    public const CATEGORY_LABELS = [
        self::CATEGORY_AUTH => 'Authentication & access',
        self::CATEGORY_AUTHORIZATION => 'Authorization & API abuse',
        self::CATEGORY_DATA => 'Data & secrets',
        self::CATEGORY_DEPENDENCY => 'Platform / dependency health',
        self::CATEGORY_MANUAL => 'Manual / declared',
        self::CATEGORY_OTHER => 'Other',
    ];

    public const SEVERITY_INFO = 'info';

    public const SEVERITY_LOW = 'low';

    public const SEVERITY_MEDIUM = 'medium';

    public const SEVERITY_HIGH = 'high';

    public const SEVERITY_CRITICAL = 'critical';

    /**
     * @var list<string>
     */
    public const SEVERITIES = [
        self::SEVERITY_INFO,
        self::SEVERITY_LOW,
        self::SEVERITY_MEDIUM,
        self::SEVERITY_HIGH,
        self::SEVERITY_CRITICAL,
    ];

    /**
     * @var array<string, int>
     */
    public const SEVERITY_RANK = [
        self::SEVERITY_INFO => 0,
        self::SEVERITY_LOW => 1,
        self::SEVERITY_MEDIUM => 2,
        self::SEVERITY_HIGH => 3,
        self::SEVERITY_CRITICAL => 4,
    ];

    public const STATUS_OPEN = 'open';

    public const STATUS_ACKNOWLEDGED = 'acknowledged';

    public const STATUS_INVESTIGATING = 'investigating';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_FALSE_POSITIVE = 'false_positive';

    /**
     * @var list<string>
     */
    public const STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_ACKNOWLEDGED,
        self::STATUS_INVESTIGATING,
        self::STATUS_RESOLVED,
        self::STATUS_CLOSED,
        self::STATUS_FALSE_POSITIVE,
    ];

    protected $fillable = [
        'hub_id',
        'occurred_at',
        'detected_at',
        'category',
        'event_type',
        'severity',
        'title',
        'description',
        'status',
        'actor_user_id',
        'actor_name',
        'actor_email',
        'actor_role',
        'source_ip',
        'user_agent',
        'route',
        'http_action',
        'security_incident_id',
        'related_refs',
        'properties',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'detected_at' => 'datetime',
            'related_refs' => 'array',
            'properties' => 'array',
        ];
    }

    public function hub(): BelongsTo
    {
        return $this->belongsTo(Hub::class);
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(SecurityIncident::class, 'security_incident_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(bool $includeDetails = false): array
    {
        $data = [
            'id' => $this->id,
            'hub_id' => $this->hub_id,
            'occurred_at' => optional($this->occurred_at)?->toIso8601String(),
            'detected_at' => optional($this->detected_at)?->toIso8601String(),
            'category' => $this->category,
            'category_label' => self::CATEGORY_LABELS[$this->category] ?? $this->category,
            'event_type' => $this->event_type,
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
            'route' => $this->route,
            'http_action' => $this->http_action,
            'security_incident_id' => $this->security_incident_id,
            'created_at' => optional($this->created_at)?->toIso8601String(),
        ];

        if ($includeDetails) {
            $data['user_agent'] = $this->user_agent;
            $data['related_refs'] = $this->related_refs ?? [];
            $data['properties'] = $this->properties ?? [];
        }

        return $data;
    }
}
