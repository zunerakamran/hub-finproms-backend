<?php

namespace App\Models;

use App\Models\WebsiteCompliance\UsesWcDatabaseContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaxonomyAddRequest extends Model
{
    use UsesWcDatabaseContext;

    protected $table = 'taxonomy_add_requests';

    public const TARGET_CONTENT_TYPE = 'content_type';

    public const TARGET_CATEGORY = 'category';

    public const TARGET_TAG = 'tag';

    public const TARGET_GC_CONTENT_TYPE = 'gc_content_type';

    public const TARGET_FIRM_DOCUMENT_CATEGORY = 'firm_document_category';

    /**
     * @var list<string>
     */
    public const TARGETS = [
        self::TARGET_CONTENT_TYPE,
        self::TARGET_CATEGORY,
        self::TARGET_TAG,
        self::TARGET_GC_CONTENT_TYPE,
        self::TARGET_FIRM_DOCUMENT_CATEGORY,
    ];

    /**
     * @var array<string, string>
     */
    public const TARGET_LABELS = [
        self::TARGET_CONTENT_TYPE => 'Post / reel content type',
        self::TARGET_CATEGORY => 'Post / reel category',
        self::TARGET_TAG => 'Post / reel tag',
        self::TARGET_GC_CONTENT_TYPE => 'General compliance content type',
        self::TARGET_FIRM_DOCUMENT_CATEGORY => 'Firm document category',
    ];

    /**
     * Manage capability required to review / approve each target.
     *
     * @var array<string, string>
     */
    public const TARGET_MANAGE_CAPABILITIES = [
        self::TARGET_CONTENT_TYPE => 'dashboard_manage_types',
        self::TARGET_CATEGORY => 'dashboard_manage_categories',
        self::TARGET_TAG => 'dashboard_manage_tags',
        self::TARGET_GC_CONTENT_TYPE => 'gc_manage_content_types',
        self::TARGET_FIRM_DOCUMENT_CATEGORY => 'firm_documents_manage_categories',
    ];

    /**
     * Targets that may only be requested / created on Central.
     *
     * @var list<string>
     */
    public const CENTRAL_ONLY_TARGETS = [
        self::TARGET_CONTENT_TYPE,
        self::TARGET_CATEGORY,
        self::TARGET_TAG,
    ];

    public const STATUS_PENDING = 'Pending';

    public const STATUS_APPROVED = 'Approved';

    public const STATUS_REJECTED = 'Rejected';

    /**
     * @var list<string>
     */
    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
    ];

    protected $fillable = [
        'user_id',
        'target',
        'proposed_name',
        'remarks',
        'status',
        'review_note',
        'reviewed_by',
        'reviewed_at',
        'created_entity_id',
    ];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public static function targetLabel(?string $target): string
    {
        if ($target === null || $target === '') {
            return 'Unknown';
        }

        return self::TARGET_LABELS[$target] ?? $target;
    }

    public static function manageCapabilityFor(string $target): ?string
    {
        return self::TARGET_MANAGE_CAPABILITIES[$target] ?? null;
    }

    public static function isCentralOnlyTarget(string $target): bool
    {
        return in_array($target, self::CENTRAL_ONLY_TARGETS, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        try {
            $this->loadMissing([
                'user:id,name,email,role',
                'reviewedByUser:id,name,email,role',
            ]);
        } catch (\Throwable) {
            try {
                $this->loadMissing(['user:id,name,email,role']);
            } catch (\Throwable) {
                $this->unsetRelation('user');
            }
            $this->unsetRelation('reviewedByUser');
        }

        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'target' => $this->target,
            'target_label' => self::targetLabel($this->target),
            'proposed_name' => $this->proposed_name,
            'remarks' => $this->remarks,
            'status' => $this->status ?: self::STATUS_PENDING,
            'review_note' => $this->review_note,
            'reviewed_by' => $this->reviewed_by,
            'reviewed_at' => optional($this->reviewed_at)?->toIso8601String(),
            'created_entity_id' => $this->created_entity_id,
            'submitter' => $this->user ? [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
                'role' => $this->user->role,
            ] : null,
            'reviewed_by_user' => $this->reviewedByUser ? [
                'id' => $this->reviewedByUser->id,
                'name' => $this->reviewedByUser->name,
                'email' => $this->reviewedByUser->email,
                'role' => $this->reviewedByUser->role,
            ] : null,
            'created_at' => optional($this->created_at)?->toIso8601String(),
            'updated_at' => optional($this->updated_at)?->toIso8601String(),
        ];
    }
}
