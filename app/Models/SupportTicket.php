<?php

namespace App\Models;

use App\Models\WebsiteCompliance\UsesWcDatabaseContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupportTicket extends Model
{
    use UsesWcDatabaseContext;

    protected $table = 'support_tickets';

    public const STATUS_OPEN = 'Open';

    public const STATUS_IN_PROGRESS = 'In Progress';

    public const STATUS_WAITING_ON_USER = 'Waiting on User';

    public const STATUS_RESOLVED = 'Resolved';

    public const STATUS_CLOSED = 'Closed';

    /**
     * @var list<string>
     */
    public const STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_IN_PROGRESS,
        self::STATUS_WAITING_ON_USER,
        self::STATUS_RESOLVED,
        self::STATUS_CLOSED,
    ];

    public const PRIORITY_LOW = 'low';

    public const PRIORITY_MEDIUM = 'medium';

    public const PRIORITY_HIGH = 'high';

    public const PRIORITY_CRITICAL = 'critical';

    /**
     * @var list<string>
     */
    public const PRIORITIES = [
        self::PRIORITY_LOW,
        self::PRIORITY_MEDIUM,
        self::PRIORITY_HIGH,
        self::PRIORITY_CRITICAL,
    ];

    /**
     * @var array<string, string>
     */
    public const PRIORITY_LABELS = [
        self::PRIORITY_LOW => 'Low',
        self::PRIORITY_MEDIUM => 'Medium',
        self::PRIORITY_HIGH => 'High',
        self::PRIORITY_CRITICAL => 'Critical',
    ];

    public const CATEGORY_BUG = 'bug';

    public const CATEGORY_UI = 'ui';

    public const CATEGORY_PERFORMANCE = 'performance';

    public const CATEGORY_DATA = 'data';

    public const CATEGORY_ACCESS = 'access';

    public const CATEGORY_FEATURE = 'feature_request';

    public const CATEGORY_OTHER = 'other';

    /**
     * @var list<string>
     */
    public const CATEGORIES = [
        self::CATEGORY_BUG,
        self::CATEGORY_UI,
        self::CATEGORY_PERFORMANCE,
        self::CATEGORY_DATA,
        self::CATEGORY_ACCESS,
        self::CATEGORY_FEATURE,
        self::CATEGORY_OTHER,
    ];

    /**
     * @var array<string, string>
     */
    public const CATEGORY_LABELS = [
        self::CATEGORY_BUG => 'Bug / error',
        self::CATEGORY_UI => 'UI / display issue',
        self::CATEGORY_PERFORMANCE => 'Performance / slow',
        self::CATEGORY_DATA => 'Incorrect data',
        self::CATEGORY_ACCESS => 'Access / permissions',
        self::CATEGORY_FEATURE => 'Feature request',
        self::CATEGORY_OTHER => 'Other',
    ];

    /**
     * Product areas users can pick when reporting an issue.
     *
     * @var array<string, string>
     */
    public const MODULE_AREAS = [
        'authentication' => 'Login / authentication',
        'dashboard' => 'Dashboard',
        'content_catalog' => 'Content catalog (posts / reels)',
        'subscriptions' => 'Subscriptions & plans',
        'purchases_credits' => 'Purchases & credits',
        'social_media_compliance' => 'Social Media Pre Approval',
        'general_compliance' => 'Generic Content Pre Approval',
        'website_template_library' => 'Website Template Library',
        'website_compliance' => 'Website Content Pre Approval',
        'firm_documents' => 'Firm documents',
        'billing_invoices' => 'Billing / invoices',
        'settings_branding' => 'Settings / branding',
        'users_roles' => 'Users & roles',
        'modules_capabilities' => 'Modules / capabilities',
        'other' => 'Other',
    ];

    protected $fillable = [
        'user_id',
        'subject',
        'module_area',
        'category',
        'priority',
        'description',
        'status',
        'page_url',
        'browser_info',
        'status_note',
        'status_changed_by',
        'status_changed_at',
        'resolved_at',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'status_changed_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function statusChangedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'status_changed_by');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(SupportTicketAttachment::class, 'ticket_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(SupportTicketComment::class, 'ticket_id')
            ->orderBy('created_at')
            ->orderBy('id');
    }

    public static function moduleAreaLabel(?string $key): string
    {
        if ($key === null || $key === '') {
            return 'Other';
        }

        return self::MODULE_AREAS[$key] ?? $key;
    }

    public static function categoryLabel(?string $key): string
    {
        if ($key === null || $key === '') {
            return self::CATEGORY_LABELS[self::CATEGORY_OTHER];
        }

        return self::CATEGORY_LABELS[$key] ?? $key;
    }

    public static function priorityLabel(?string $key): string
    {
        if ($key === null || $key === '') {
            return self::PRIORITY_LABELS[self::PRIORITY_MEDIUM];
        }

        return self::PRIORITY_LABELS[$key] ?? $key;
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(bool $includeDetails = false): array
    {
        $this->loadMissing([
            'user:id,name,email,role',
            'statusChangedByUser:id,name,email,role',
            'attachments',
        ]);

        $payload = [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'subject' => $this->subject,
            'module_area' => $this->module_area,
            'module_area_label' => self::moduleAreaLabel($this->module_area),
            'category' => $this->category,
            'category_label' => self::categoryLabel($this->category),
            'priority' => $this->priority,
            'priority_label' => self::priorityLabel($this->priority),
            'description' => $this->description,
            'status' => $this->status ?: self::STATUS_OPEN,
            'page_url' => $this->page_url,
            'browser_info' => $this->browser_info,
            'status_note' => $this->status_note,
            'status_changed_by' => $this->status_changed_by,
            'status_changed_at' => optional($this->status_changed_at)?->toIso8601String(),
            'resolved_at' => optional($this->resolved_at)?->toIso8601String(),
            'closed_at' => optional($this->closed_at)?->toIso8601String(),
            'attachment_count' => $this->relationLoaded('attachments')
                ? $this->attachments->count()
                : (int) $this->attachments()->count(),
            'attachments' => $this->attachments
                ->map(fn (SupportTicketAttachment $a) => $a->toApiArray())
                ->values()
                ->all(),
            'submitter' => $this->user ? [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
                'role' => $this->user->role,
            ] : null,
            'status_changed_by_user' => $this->statusChangedByUser ? [
                'id' => $this->statusChangedByUser->id,
                'name' => $this->statusChangedByUser->name,
                'email' => $this->statusChangedByUser->email,
                'role' => $this->statusChangedByUser->role,
            ] : null,
            'created_at' => optional($this->created_at)?->toIso8601String(),
            'updated_at' => optional($this->updated_at)?->toIso8601String(),
        ];

        if ($includeDetails) {
            $this->loadMissing(['comments.user:id,name,email,role']);
            $payload['comments'] = $this->comments
                ->map(fn (SupportTicketComment $c) => $c->toApiArray())
                ->values()
                ->all();
        }

        return $payload;
    }
}
