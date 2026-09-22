<?php

namespace App\Models;

use App\Models\WebsiteCompliance\UsesWcDatabaseContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class GeneralComplianceRequest extends Model
{
    use UsesWcDatabaseContext;

    protected $table = 'general_compliance_requests';

    public const STATUS_PENDING = 'Pending';

    public const STATUS_APPROVED = 'Approved';

    public const STATUS_REJECTED = 'Rejected';

    public const STATUS_APPROVED_WITH_FEEDBACK = 'Approved with Feedback';

    /**
     * @var list<string>
     */
    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
        self::STATUS_APPROVED_WITH_FEEDBACK,
    ];

    protected $fillable = [
        'user_id',
        'name',
        'current_version',
        'submission_date',
        'assigned_to',
        'assigned_date',
        'assigned_by',
    ];

    protected function casts(): array
    {
        return [
            'current_version' => 'integer',
            'submission_date' => 'datetime',
            'assigned_date' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(GeneralComplianceRequestVersion::class, 'request_id')
            ->orderByDesc('version_number');
    }

    public function currentVersionRow(): HasOne
    {
        return $this->hasOne(GeneralComplianceRequestVersion::class, 'request_id')
            ->latestOfMany('version_number');
    }

    public function currentStatus(): ?string
    {
        return $this->currentVersionRow?->status;
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(bool $includeVersions = false): array
    {
        $this->loadMissing([
            'currentVersionRow.attachments',
            'assignee:id,name,email',
            'user:id,name,email,firm_id',
            'user.firm:id,name,is_central,compliance_visible_to_own,compliance_visible_to_central,compliance_visible_to_firm_id',
        ]);

        $version = $this->currentVersionRow;
        $payload = [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'name' => $this->name,
            'current_version' => $this->current_version,
            'submission_date' => optional($this->submission_date)?->toIso8601String(),
            'assigned_to' => $this->assigned_to,
            'assigned_date' => optional($this->assigned_date)?->toIso8601String(),
            'assigned_by' => $this->assigned_by,
            'assignee' => $this->assignee ? [
                'id' => $this->assignee->id,
                'name' => $this->assignee->name,
                'email' => $this->assignee->email,
            ] : null,
            'submitter' => $this->user ? [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
                'firm_id' => $this->user->firm_id ? (int) $this->user->firm_id : null,
                'firm' => $this->user->firm ? [
                    'id' => (int) $this->user->firm->id,
                    'name' => $this->user->firm->name,
                    'is_central' => (bool) $this->user->firm->is_central,
                    'compliance_visibility' => [
                        'visible_to_own' => (bool) $this->user->firm->compliance_visible_to_own,
                        'visible_to_central' => (bool) $this->user->firm->compliance_visible_to_central,
                        'visible_to_firm_id' => $this->user->firm->compliance_visible_to_firm_id
                            ? (int) $this->user->firm->compliance_visible_to_firm_id
                            : null,
                    ],
                ] : null,
            ] : null,
            'description' => $version?->description,
            'attachments' => $version
                ? $version->attachments->map(fn (GeneralComplianceRequestAttachment $a) => $a->toApiArray())->values()->all()
                : [],
            'status' => $version?->status ?? self::STATUS_PENDING,
            'feedback' => $version?->feedback,
            'reviewed_by' => $version?->reviewed_by,
            'reviewed_at' => optional($version?->reviewed_at)?->toIso8601String(),
            'created_at' => optional($this->created_at)?->toIso8601String(),
            'updated_at' => optional($this->updated_at)?->toIso8601String(),
        ];

        if ($includeVersions) {
            $this->loadMissing('versions.attachments');
            $payload['versions'] = $this->versions
                ->sortByDesc('version_number')
                ->values()
                ->map(fn (GeneralComplianceRequestVersion $v) => $v->toApiArray())
                ->all();
        }

        return $payload;
    }
}
