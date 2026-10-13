<?php

namespace App\Models;

use App\Models\WebsiteCompliance\UsesWcDatabaseContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SecurityIncident extends Model
{
    use UsesWcDatabaseContext;

    protected $table = 'security_incidents';

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
        'title',
        'description',
        'category',
        'severity',
        'status',
        'declared_by_user_id',
        'declared_by_name',
        'resolved_by_user_id',
        'resolved_by_name',
        'resolution_summary',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'resolved_at' => 'datetime',
        ];
    }

    public function hub(): BelongsTo
    {
        return $this->belongsTo(Hub::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(SecurityEvent::class, 'security_incident_id');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(SecurityIncidentNote::class, 'security_incident_id')->orderBy('created_at');
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(bool $includeDetails = false): array
    {
        $data = [
            'id' => $this->id,
            'hub_id' => $this->hub_id,
            'title' => $this->title,
            'description' => $this->description,
            'category' => $this->category,
            'category_label' => SecurityEvent::CATEGORY_LABELS[$this->category] ?? $this->category,
            'severity' => $this->severity,
            'status' => $this->status,
            'declared_by' => [
                'user_id' => $this->declared_by_user_id,
                'name' => $this->declared_by_name,
            ],
            'resolved_by' => [
                'user_id' => $this->resolved_by_user_id,
                'name' => $this->resolved_by_name,
            ],
            'resolution_summary' => $this->resolution_summary,
            'resolved_at' => optional($this->resolved_at)?->toIso8601String(),
            'events_count' => $this->relationLoaded('events')
                ? $this->events->count()
                : ($this->events_count ?? null),
            'created_at' => optional($this->created_at)?->toIso8601String(),
            'updated_at' => optional($this->updated_at)?->toIso8601String(),
        ];

        if ($includeDetails) {
            $data['events'] = $this->relationLoaded('events')
                ? $this->events->map(fn (SecurityEvent $e) => $e->toApiArray())->values()->all()
                : [];
            $data['investigation_notes'] = $this->relationLoaded('notes')
                ? $this->notes->map(fn (SecurityIncidentNote $n) => $n->toApiArray())->values()->all()
                : [];
        }

        return $data;
    }
}
