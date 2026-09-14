<?php

namespace App\Models\WebsiteCompliance;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TemplateRequest extends Model
{
    protected $table = 'wc_template_requests';

    protected $fillable = [
        'advisor_id',
        'requested_by_id',
        'assigned_advisor_id',
        'template_name',
        'request_type',
        'domain_name',
        'logo_url',
        'primary_color',
        'secondary_color',
        'status',
        'rejection_reason',
        'cpanel_domain',
        'cpanel_db_host',
        'cpanel_db_name',
        'cpanel_db_user',
        'cpanel_db_password',
        'cpanel_api_key',
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
