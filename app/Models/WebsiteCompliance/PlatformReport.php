<?php

namespace App\Models\WebsiteCompliance;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformReport extends Model
{
    use UsesWcDatabaseContext;

    protected $table = 'wc_platform_reports';

    protected $fillable = [
        'templates_total',
        'templates_active',
        'templates_inactive',
        'users_total',
        'advisors_count',
        'approvers_count',
        'managers_count',
        'client_admins_count',
        'power_admins_count',
        'template_requests_total',
        'template_requests_pending',
        'template_requests_deployed',
        'template_requests_rejected',
        'template_requests_advisor_website',
        'template_requests_hub_main_website',
        'template_requests_by_template',
        'change_requests_total',
        'change_requests_pending',
        'change_requests_under_review',
        'change_requests_scheduled',
        'change_requests_approved',
        'change_requests_rejected',
        'change_requests_approved_with_feedback',
        'generated_by',
        'generated_at',
    ];

    protected $casts = [
        'template_requests_by_template' => 'array',
        'generated_at' => 'datetime',
    ];

    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    /**
     * @return array<string, mixed>
     */
    public function toSummaryPayload(): array
    {
        $generator = null;
        try {
            $generator = $this->generator;
        } catch (\Throwable) {
            $generator = null;
        }

        return [
            'id' => $this->id,
            'generated_at' => optional($this->generated_at)->toIso8601String(),
            'generated_by' => $generator ? [
                'id' => $generator->id,
                'name' => $generator->name,
            ] : null,
            'templates' => [
                'total' => (int) $this->templates_total,
                'active' => (int) $this->templates_active,
                'inactive' => (int) $this->templates_inactive,
            ],
            'users' => [
                'total' => (int) $this->users_total,
                'by_role' => [
                    'power_admin' => (int) $this->power_admins_count,
                    'manager' => (int) $this->managers_count,
                    'client_admin' => (int) $this->client_admins_count,
                    'approver' => (int) $this->approvers_count,
                    'advisor' => (int) $this->advisors_count,
                ],
            ],
            'template_requests' => [
                'total' => (int) $this->template_requests_total,
                'by_status' => [
                    'pending' => (int) $this->template_requests_pending,
                    'deployed' => (int) $this->template_requests_deployed,
                    'rejected' => (int) $this->template_requests_rejected,
                ],
                'by_type' => [
                    'advisor_website' => (int) $this->template_requests_advisor_website,
                    'hub_main_website' => (int) $this->template_requests_hub_main_website,
                ],
                'by_template' => $this->template_requests_by_template ?? [],
            ],
            'change_requests' => [
                'total' => (int) $this->change_requests_total,
                'by_status' => [
                    'pending' => (int) $this->change_requests_pending,
                    'under_review' => (int) $this->change_requests_under_review,
                    'scheduled' => (int) $this->change_requests_scheduled,
                    'approved' => (int) $this->change_requests_approved,
                    'rejected' => (int) $this->change_requests_rejected,
                    'approved_with_feedback' => (int) ($this->change_requests_approved_with_feedback ?? 0),
                ],
            ],
        ];
    }
}
