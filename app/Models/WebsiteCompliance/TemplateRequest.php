<?php

namespace App\Models\WebsiteCompliance;

use App\Casts\ContentHubSecretEncrypted;
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
        self::STATUS_DEPLOYED, // legacy — treated as staging after migration
    ];

    public const LIVE_STATUSES = [
        self::STATUS_LIVE,
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
        'go_live_notes',
        'live_promoted_at',
        'cpanel_domain',
        'cpanel_db_host',
        'cpanel_db_name',
        'cpanel_db_user',
        'cpanel_db_password',
        'cpanel_api_key',
    ];

    protected $hidden = [
        'cpanel_db_password',
        'cpanel_api_key',
    ];

    protected $appends = [
        'cpanel_db_password_set',
        'cpanel_api_key_set',
        'cpanel_api_key_corrupt',
        'cpanel_db_password_corrupt',
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
        // Must use ContentHubSecretEncrypted: Central remote writes cannot use Central APP_KEY.
        'cpanel_db_password' => ContentHubSecretEncrypted::class,
        'cpanel_api_key' => ContentHubSecretEncrypted::class,
    ];

    public function getCpanelDbPasswordSetAttribute(): bool
    {
        return $this->hasReadableCpanelSecret('cpanel_db_password');
    }

    public function getCpanelApiKeySetAttribute(): bool
    {
        return $this->hasReadableCpanelSecret('cpanel_api_key');
    }

    public function getCpanelApiKeyCorruptAttribute(): bool
    {
        return $this->cpanelSecretDecryptFailed('cpanel_api_key');
    }

    public function getCpanelDbPasswordCorruptAttribute(): bool
    {
        return $this->cpanelSecretDecryptFailed('cpanel_db_password');
    }

    /**
     * True when the decrypted secret is usable (not merely that ciphertext exists in DB).
     */
    public function hasReadableCpanelSecret(string $attribute): bool
    {
        return filled($this->{$attribute});
    }

    /**
     * True when the DB column has a non-empty value (plaintext or ciphertext),
     * even if the current APP_KEY cannot decrypt it.
     */
    public function hasStoredCpanelSecret(string $attribute): bool
    {
        $raw = $this->getAttributes()[$attribute]
            ?? $this->getRawOriginal($attribute)
            ?? null;

        return is_string($raw) ? trim($raw) !== '' : filled($raw);
    }

    /**
     * Ciphertext/envelope is present but cannot be read (wrong APP_KEY from Central remote write, etc.).
     */
    public function cpanelSecretDecryptFailed(string $attribute): bool
    {
        return $this->hasStoredCpanelSecret($attribute) && ! $this->hasReadableCpanelSecret($attribute);
    }

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
            self::STATUS_DEPLOYED, // legacy deployed rows = staging
        ], true);
    }

    public function canRequestGoLive(): bool
    {
        return in_array((string) $this->status, [
            self::STATUS_STAGING,
            self::STATUS_DEPLOYED,
        ], true);
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
