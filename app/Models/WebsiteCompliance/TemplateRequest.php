<?php

namespace App\Models\WebsiteCompliance;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TemplateRequest extends Model
{
    use UsesWcDatabaseContext;

    public const STATUS_PENDING = 'pending';

    public const STATUS_STAGING = 'staging';

    public const STATUS_READY_FOR_LIVE = 'ready_for_live';

    public const STATUS_LIVE = 'live';

    /** @deprecated Prefer STATUS_LIVE — kept for existing rows */
    public const STATUS_DEPLOYED = 'deployed';

    public const STATUS_REJECTED = 'rejected';

    /** Statuses where hub sections / compliance / cPanel sync are active. */
    public const ON_SITE_STATUSES = [
        self::STATUS_STAGING,
        self::STATUS_READY_FOR_LIVE,
        self::STATUS_LIVE,
        self::STATUS_DEPLOYED,
    ];

    public const LIVE_STATUSES = [
        self::STATUS_LIVE,
        self::STATUS_DEPLOYED,
    ];

    protected $table = 'wc_template_requests';

    protected $fillable = [
        'advisor_id',
        'requested_by_id',
        'assigned_advisor_id',
        'template_name',
        'request_type',
        'domain_name',
        'staging_domain',
        'logo_url',
        'white_logo_url',
        'favicon_url',
        'primary_color',
        'secondary_color',
        'services',
        'images',
        'contact_details',
        'policies',
        'selected_pages',
        'page_contents',
        'status',
        'rejection_reason',
        'go_live_requested_at',
        'go_live_requested_by_id',
        'live_promoted_at',
        'cpanel_domain',
        'cpanel_db_host',
        'cpanel_db_name',
        'cpanel_db_user',
        'cpanel_db_password',
        'cpanel_api_key',
    ];

    protected $casts = [
        'services' => 'array',
        'images' => 'array',
        'contact_details' => 'array',
        'policies' => 'array',
        'selected_pages' => 'array',
        'page_contents' => 'array',
        'go_live_requested_at' => 'datetime',
        'live_promoted_at' => 'datetime',
    ];

    public function advisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'advisor_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_id');
    }

    public function assignedAdvisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_advisor_id');
    }

    public function goLiveRequestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'go_live_requested_by_id');
    }

    public function isOnSite(): bool
    {
        return in_array((string) $this->status, self::ON_SITE_STATUSES, true);
    }

    public function isLive(): bool
    {
        return in_array((string) $this->status, self::LIVE_STATUSES, true);
    }

    public function isStagingPhase(): bool
    {
        return in_array((string) $this->status, [
            self::STATUS_STAGING,
            self::STATUS_READY_FOR_LIVE,
        ], true);
    }

    public function canRequestGoLive(): bool
    {
        return (string) $this->status === self::STATUS_STAGING;
    }

    public function canPromoteToLive(): bool
    {
        return (string) $this->status === self::STATUS_READY_FOR_LIVE;
    }

    public function scopeOnSite(Builder $query): Builder
    {
        return $query->whereIn('status', self::ON_SITE_STATUSES);
    }

    public function scopeLive(Builder $query): Builder
    {
        return $query->whereIn('status', self::LIVE_STATUSES);
    }
}
