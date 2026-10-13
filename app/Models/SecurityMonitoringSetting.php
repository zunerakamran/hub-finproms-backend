<?php

namespace App\Models;

use App\Models\WebsiteCompliance\UsesWcDatabaseContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SecurityMonitoringSetting extends Model
{
    use UsesWcDatabaseContext;

    protected $table = 'security_monitoring_settings';

    protected $fillable = [
        'hub_id',
        'alert_min_severity',
        'alert_email_enabled',
        'alert_emails',
        'failed_login_threshold',
        'failed_login_window_minutes',
        'last_dependency_scan_at',
        'last_dependency_scan_status',
        'last_dependency_scan_summary',
    ];

    protected function casts(): array
    {
        return [
            'alert_email_enabled' => 'boolean',
            'alert_emails' => 'array',
            'failed_login_threshold' => 'integer',
            'failed_login_window_minutes' => 'integer',
            'last_dependency_scan_at' => 'datetime',
        ];
    }

    public function hub(): BelongsTo
    {
        return $this->belongsTo(Hub::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'hub_id' => $this->hub_id,
            'alert_min_severity' => $this->alert_min_severity,
            'alert_email_enabled' => (bool) $this->alert_email_enabled,
            'alert_emails' => $this->alert_emails ?? [],
            'failed_login_threshold' => (int) $this->failed_login_threshold,
            'failed_login_window_minutes' => (int) $this->failed_login_window_minutes,
            'last_dependency_scan_at' => optional($this->last_dependency_scan_at)?->toIso8601String(),
            'last_dependency_scan_status' => $this->last_dependency_scan_status,
            'last_dependency_scan_summary' => $this->last_dependency_scan_summary,
            'updated_at' => optional($this->updated_at)?->toIso8601String(),
        ];
    }
}
