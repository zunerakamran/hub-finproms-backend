<?php

namespace App\Models\WebsiteCompliance;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TemplateRequest extends Model
{
    use UsesWcDatabaseContext;

    protected $table = 'wc_template_requests';

    protected $fillable = [
        'advisor_id',
        'requested_by_id',
        'assigned_advisor_id',
        'template_name',
        'request_type',
        'domain_name',
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
}
