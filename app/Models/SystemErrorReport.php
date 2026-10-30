<?php

namespace App\Models;

use App\Models\WebsiteCompliance\UsesWcDatabaseContext;
use App\Support\DateFormat;
use Illuminate\Database\Eloquent\Model;

class SystemErrorReport extends Model
{
    use UsesWcDatabaseContext;

    protected $table = 'system_error_reports';

    public const SOURCE_FRONTEND = 'frontend';

    public const SOURCE_BACKEND = 'backend';

    public const STATUS_OPEN = 'open';

    public const STATUS_ACKNOWLEDGED = 'acknowledged';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_IGNORED = 'ignored';

    /**
     * @var list<string>
     */
    public const STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_ACKNOWLEDGED,
        self::STATUS_RESOLVED,
        self::STATUS_IGNORED,
    ];

    /**
     * @var list<string>
     */
    public const ACTIVE_STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_ACKNOWLEDGED,
    ];

    protected $fillable = [
        'hub_id',
        'fingerprint',
        'source',
        'level',
        'message',
        'exception_class',
        'stack_trace',
        'page_url',
        'route',
        'http_method',
        'user_id',
        'user_name',
        'user_email',
        'browser_info',
        'environment',
        'occurrence_count',
        'first_seen_at',
        'last_seen_at',
        'status',
        'resolved_by_user_id',
        'resolved_by_name',
        'resolved_at',
        'staff_note',
        'properties',
    ];

    protected function casts(): array
    {
        return [
            'occurrence_count' => 'integer',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'resolved_at' => 'datetime',
            'properties' => 'array',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(bool $includeDetails = false): array
    {
        $row = [
            'id' => $this->id,
            'fingerprint' => $this->fingerprint,
            'source' => $this->source,
            'level' => $this->level,
            'message' => $this->message,
            'exception_class' => $this->exception_class,
            'page_url' => $this->page_url,
            'route' => $this->route,
            'http_method' => $this->http_method,
            'occurrence_count' => (int) $this->occurrence_count,
            'first_seen_at' => DateFormat::iso($this->first_seen_at),
            'last_seen_at' => DateFormat::iso($this->last_seen_at),
            'status' => $this->status,
            'environment' => $this->environment,
            'user' => ($this->user_id || $this->user_name || $this->user_email) ? [
                'id' => $this->user_id,
                'name' => $this->user_name,
                'email' => $this->user_email,
            ] : null,
            'browser_info' => $this->browser_info,
            'resolved_at' => DateFormat::iso($this->resolved_at),
            'resolved_by_name' => $this->resolved_by_name,
            'staff_note' => $this->staff_note,
            'created_at' => DateFormat::iso($this->created_at),
            'updated_at' => DateFormat::iso($this->updated_at),
        ];

        if ($includeDetails) {
            $row['stack_trace'] = $this->stack_trace;
            $row['properties'] = is_array($this->properties) ? $this->properties : [];
        }

        return $row;
    }
}
