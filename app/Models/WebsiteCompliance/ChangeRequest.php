<?php

namespace App\Models\WebsiteCompliance;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ChangeRequest extends Model
{
    use UsesWcDatabaseContext;

    protected $table = 'wc_change_requests';

    public const STATUS_PENDING = 'pending';

    public const STATUS_UNDER_REVIEW = 'under_review';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_APPROVED_WITH_FEEDBACK = 'approved_with_feedback';

    /**
     * @var list<string>
     */
    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_UNDER_REVIEW,
        self::STATUS_SCHEDULED,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
        self::STATUS_APPROVED_WITH_FEEDBACK,
    ];

    protected $fillable = [
        'section_id',
        'editor_id',
        'approver_id',
        'proposed_content',
        'status',
        'scheduled_at',
        'rejection_reason',
        'current_version',
        'feedback',
    ];

    protected $appends = [
        'section_edits',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'current_version' => 'integer',
    ];

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class, 'section_id');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'editor_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ChangeRequestVersion::class, 'request_id')
            ->orderByDesc('version_number');
    }

    public function currentVersionRow(): HasOne
    {
        return $this->hasOne(ChangeRequestVersion::class, 'request_id')
            ->latestOfMany('version_number');
    }

    /**
     * Decoded proposed_content array for frontend convenience.
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function getSectionEditsAttribute(): ?array
    {
        $raw = $this->resolvedProposedContent();
        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    public function resolvedProposedContent(): ?string
    {
        $this->loadMissing('currentVersionRow');

        $fromVersion = $this->currentVersionRow?->proposed_content;
        if (filled($fromVersion)) {
            return $fromVersion;
        }

        return $this->attributes['proposed_content'] ?? $this->proposed_content;
    }

    /**
     * Section IDs included in the current/previous version's proposed content.
     * Used to restrict resubmit / confirm-feedback edits to that set.
     *
     * @return list<int>
     */
    public function sectionIdsFromProposedContent(): array
    {
        $decoded = json_decode((string) $this->resolvedProposedContent(), true);

        if (is_array($decoded)) {
            $ids = [];
            foreach ($decoded as $item) {
                if (isset($item['section_id'])) {
                    $ids[] = (int) $item['section_id'];
                }
            }

            return array_values(array_unique($ids));
        }

        if ($this->section_id) {
            return [(int) $this->section_id];
        }

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(bool $includeVersions = false): array
    {
        $this->loadMissing([
            'currentVersionRow',
            'editor:id,name,email,firm_id',
            'editor.firm:id,name,is_central,compliance_visible_to_own,compliance_visible_to_central,compliance_visible_to_firm_id',
            'approver:id,name,email',
            'section:id,name,display_name,advisor_id',
        ]);

        if ($includeVersions) {
            $this->loadMissing('versions');
        }

        $proposed = $this->resolvedProposedContent();
        $decoded = json_decode((string) $proposed, true);
        $priorSectionIds = $this->sectionIdsFromProposedContent();
        $locksToPriorVersion = in_array($this->status, [
            self::STATUS_REJECTED,
            self::STATUS_APPROVED_WITH_FEEDBACK,
        ], true);

        $payload = [
            'id' => $this->id,
            'section_id' => $this->section_id,
            'editor_id' => $this->editor_id,
            'approver_id' => $this->approver_id,
            'status' => $this->status,
            'current_version' => (int) ($this->current_version ?: 1),
            'proposed_content' => $proposed,
            'section_edits' => is_array($decoded) ? $decoded : null,
            // When revising a previous version, FE should only show these sections.
            'editable_section_ids' => $locksToPriorVersion ? $priorSectionIds : null,
            'feedback' => $this->feedback,
            'rejection_reason' => $this->rejection_reason,
            'scheduled_at' => optional($this->scheduled_at)?->toIso8601String(),
            'created_at' => optional($this->created_at)?->toIso8601String(),
            'updated_at' => optional($this->updated_at)?->toIso8601String(),
            'editor' => $this->editor ? [
                'id' => $this->editor->id,
                'name' => $this->editor->name,
                'email' => $this->editor->email,
                'firm_id' => $this->editor->firm_id ? (int) $this->editor->firm_id : null,
                'firm' => $this->editor->firm ? [
                    'id' => (int) $this->editor->firm->id,
                    'name' => $this->editor->firm->name,
                    'is_central' => (bool) $this->editor->firm->is_central,
                    'compliance_visibility' => [
                        'visible_to_own' => (bool) $this->editor->firm->compliance_visible_to_own,
                        'visible_to_central' => (bool) $this->editor->firm->compliance_visible_to_central,
                        'visible_to_firm_id' => $this->editor->firm->compliance_visible_to_firm_id
                            ? (int) $this->editor->firm->compliance_visible_to_firm_id
                            : null,
                    ],
                ] : null,
            ] : null,
            'approver' => $this->approver ? [
                'id' => $this->approver->id,
                'name' => $this->approver->name,
                'email' => $this->approver->email,
            ] : null,
            'section' => $this->section ? [
                'id' => $this->section->id,
                'name' => $this->section->name,
                'display_name' => $this->section->display_name,
                'advisor_id' => $this->section->advisor_id,
            ] : null,
        ];

        if ($includeVersions) {
            $payload['versions'] = $this->versions
                ->sortByDesc('version_number')
                ->values()
                ->map(fn (ChangeRequestVersion $version) => $version->toApiArray())
                ->all();
        }

        return $payload;
    }
}
