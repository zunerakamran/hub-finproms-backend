<?php

namespace App\Models;

use App\Models\WebsiteCompliance\UsesWcDatabaseContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserAccessRestrictionSetting extends Model
{
    use UsesWcDatabaseContext;

    protected $table = 'user_access_restriction_settings';

    protected $fillable = [
        'hub_id',
        'user_id',
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
        'access_restrict_vpn_enabled',
        'access_allow_vpn_enabled',
    ];

    protected function casts(): array
    {
        return [
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
            'access_restrict_vpn_enabled' => 'boolean',
            'access_allow_vpn_enabled' => 'boolean',
        ];
    }

    public function hub(): BelongsTo
    {
        return $this->belongsTo(Hub::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function toAccessRestrictionApiArray(): array
    {
        return [
            'hub_id' => $this->hub_id,
            'user_id' => $this->user_id,
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
            'restrict_vpn_enabled' => (bool) ($this->access_restrict_vpn_enabled ?? false),
            'allow_vpn_enabled' => (bool) ($this->access_allow_vpn_enabled ?? false),
            'updated_at' => optional($this->updated_at)?->toIso8601String(),
        ];
    }
}
