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
        'access_uk_only',
        'access_uk_cities_enabled',
        'access_restriction_cities',
        'access_restrict_countries_enabled',
        'access_allow_countries_enabled',
        'access_restricted_countries',
        'access_allowed_countries',
        'access_restrict_ips_enabled',
        'access_allow_ips_enabled',
        'access_restricted_ips',
        'access_allowed_ips',
        // Legacy columns kept for migration compatibility.
        'access_restriction_mode',
        'access_restriction_countries',
        'access_restriction_ips',
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
            'access_uk_only' => 'boolean',
            'access_uk_cities_enabled' => 'boolean',
            'access_restriction_cities' => 'array',
            'access_restrict_countries_enabled' => 'boolean',
            'access_allow_countries_enabled' => 'boolean',
            'access_restricted_countries' => 'array',
            'access_allowed_countries' => 'array',
            'access_restrict_ips_enabled' => 'boolean',
            'access_allow_ips_enabled' => 'boolean',
            'access_restricted_ips' => 'array',
            'access_allowed_ips' => 'array',
            'access_restriction_countries' => 'array',
            'access_restriction_ips' => 'array',
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
        return array_merge([
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
        ], $this->toAccessRestrictionApiArray());
    }

    /**
     * Access-restriction fields only (for the dedicated settings endpoint).
     *
     * @return array<string, mixed>
     */
    public function toAccessRestrictionApiArray(): array
    {
        return [
            'hub_id' => $this->hub_id,
            'uk_only' => (bool) ($this->access_uk_only ?? false),
            'uk_cities_enabled' => (bool) ($this->access_uk_cities_enabled ?? false),
            'uk_cities' => array_values($this->access_restriction_cities ?? []),
            'restrict_countries_enabled' => (bool) ($this->access_restrict_countries_enabled ?? false),
            'restricted_countries' => array_values($this->access_restricted_countries ?? []),
            'allow_countries_enabled' => (bool) ($this->access_allow_countries_enabled ?? false),
            'allowed_countries' => array_values($this->access_allowed_countries ?? []),
            'restrict_ips_enabled' => (bool) ($this->access_restrict_ips_enabled ?? false),
            'restricted_ips' => array_values($this->access_restricted_ips ?? []),
            'allow_ips_enabled' => (bool) ($this->access_allow_ips_enabled ?? false),
            'allowed_ips' => array_values($this->access_allowed_ips ?? []),
            'updated_at' => optional($this->updated_at)?->toIso8601String(),
        ];
    }
}
