<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Hub extends Model
{
    public const TYPE_SHARED = 'shared';

    public const TYPE_WHITE_LABEL = 'white_label';

    public const GROUP_BEHAVIOUR = 'behaviour';

    public const GROUP_MEMBER = 'member';

    public const GROUP_DASHBOARD = 'dashboard';

    /**
     * @var array<string, string>
     */
    public const CHECKLIST_GROUPS = [
        self::GROUP_BEHAVIOUR => 'Functionalities',
        self::GROUP_MEMBER => 'Member capabilities',
        self::GROUP_DASHBOARD => 'Hub-admin dashboard',
    ];

    /**
     * Groups edited on the Hub checklist screen (Functionalities only).
     *
     * @var list<string>
     */
    public const FUNCTIONALITY_GROUPS = [
        self::GROUP_BEHAVIOUR,
    ];

    /**
     * Groups edited on the Capabilities matrix (user / role capabilities).
     *
     * @var list<string>
     */
    public const CAPABILITY_GROUPS = [
        self::GROUP_MEMBER,
        self::GROUP_DASHBOARD,
    ];

    /**
     * Mutually exclusive checklist pairs.
     * Checking one automatically unchecks its opposite.
     *
     * @var array<string, string>
     */
    public const CHECKLIST_OPPOSITES = [
        'public_subscribe' => 'private_invite_only',
        'private_invite_only' => 'public_subscribe',
        'paid_credits' => 'unlimited_credits',
        'unlimited_credits' => 'paid_credits',
    ];

    /**
     * Dashboard/member capabilities that only apply while the hub is private (invite-only).
     * Shown blurred / inactive on the Capabilities matrix when public subscribe is on.
     *
     * @var list<string>
     */
    public const PRIVATE_CAPABILITY_KEYS = [
        'advisor_excel_import',
        'advisor_discontinue',
        'dashboard_view_advisor_invoices',
        'dashboard_manage_advisor_pricing',
        'dashboard_manage_advisor_renewal',
    ];

    /**
     * Capabilities that only apply while the hub is public (self-serve subscribe).
     * Shown blurred / inactive on the Capabilities matrix when private invite-only is on.
     *
     * @var list<string>
     */
    public const PUBLIC_CAPABILITY_KEYS = [
        'member_view_plans',
        'dashboard_manage_plans',
        'dashboard_bank_transfers',
    ];

    /**
     * Known checklist keys (Functionalities + member + dashboard capabilities).
     *
     * @var array<string, array{label: string, description: string, group: string, default_shared: bool, default_white_label: bool}>
     */
    public const CHECKLIST_DEFINITIONS = [
        // --- Functionalities (hub checklist) ---
        'public_subscribe' => [
            'label' => 'Public subscribe / self-registration',
            'description' => 'Anyone can register and subscribe.',
            'group' => self::GROUP_BEHAVIOUR,
            'default_shared' => true,
            'default_white_label' => false,
        ],
        'private_invite_only' => [
            'label' => 'Private invite-only access',
            'description' => 'Only invited advisors (e.g. Excel import) can access.',
            'group' => self::GROUP_BEHAVIOUR,
            'default_shared' => false,
            'default_white_label' => true,
        ],
        'paid_credits' => [
            'label' => 'Paid credits',
            'description' => 'Users buy/earn credits via subscription or packs.',
            'group' => self::GROUP_BEHAVIOUR,
            'default_shared' => true,
            'default_white_label' => false,
        ],
        'unlimited_credits' => [
            'label' => 'Unlimited credits',
            'description' => 'Advisors have unlimited credits to buy content.',
            'group' => self::GROUP_BEHAVIOUR,
            'default_shared' => false,
            'default_white_label' => true,
        ],
        'one_off_purchase' => [
            'label' => 'One-off purchase (non-subscribers)',
            'description' => 'Non-subscribers can buy individual posts/reels (1 credit = £1).',
            'group' => self::GROUP_BEHAVIOUR,
            'default_shared' => true,
            'default_white_label' => false,
        ],
        'receive_content_from_shared' => [
            'label' => 'Receive content from shared hub',
            'description' => 'Shared hub can push/select posts onto this hub.',
            'group' => self::GROUP_BEHAVIOUR,
            'default_shared' => false,
            'default_white_label' => true,
        ],
        'compliance_check' => [
            'label' => 'Compliance check',
            'description' => 'Posts can go through a compliance flow (future).',
            'group' => self::GROUP_BEHAVIOUR,
            'default_shared' => false,
            'default_white_label' => false,
        ],
        'advisor_subscriber_billing' => [
            'label' => 'Advisor subscriber billing (rate × advisors)',
            'description' => 'After Excel advisor import, bill rate × advisor count. Stripe auto-renews monthly.',
            'group' => self::GROUP_BEHAVIOUR,
            'default_shared' => false,
            'default_white_label' => true,
        ],

        // --- Member capabilities ---
        'member_browse_catalog' => [
            'label' => 'Browse catalog (posts / reels)',
            'description' => 'Members can browse the content catalog.',
            'group' => self::GROUP_MEMBER,
            'default_shared' => true,
            'default_white_label' => true,
        ],
        'member_view_plans' => [
            'label' => 'View plans / subscribe',
            'description' => 'Members can open the plans page and self-serve subscribe (when behaviour allows).',
            'group' => self::GROUP_MEMBER,
            'default_shared' => true,
            'default_white_label' => false,
        ],
        'member_purchase_content' => [
            'label' => 'Purchase / spend credits on content',
            'description' => 'Members can buy posts/reels with credits.',
            'group' => self::GROUP_MEMBER,
            'default_shared' => true,
            'default_white_label' => true,
        ],
        'member_download_content' => [
            'label' => 'Download purchased content',
            'description' => 'Members can download content they have purchased.',
            'group' => self::GROUP_MEMBER,
            'default_shared' => true,
            'default_white_label' => true,
        ],
        'member_view_purchases' => [
            'label' => 'View purchase history',
            'description' => 'Members can open My Purchases.',
            'group' => self::GROUP_MEMBER,
            'default_shared' => true,
            'default_white_label' => true,
        ],
        'member_view_invoices' => [
            'label' => 'View invoices',
            'description' => 'Members can open their personal invoices.',
            'group' => self::GROUP_MEMBER,
            'default_shared' => true,
            'default_white_label' => true,
        ],
        'member_in_app_edit' => [
            'label' => 'In-app post editing',
            'description' => 'Buyers can edit purchased posts in-app (future).',
            'group' => self::GROUP_MEMBER,
            'default_shared' => false,
            'default_white_label' => false,
        ],

        // --- Hub-admin dashboard capabilities ---
        'dashboard_manage_posts' => [
            'label' => 'Manage posts / reels',
            'description' => 'Hub admin can create and edit posts/reels.',
            'group' => self::GROUP_DASHBOARD,
            'default_shared' => true,
            'default_white_label' => true,
        ],
        'dashboard_manage_types' => [
            'label' => 'Manage types',
            'description' => 'Hub admin can manage content types.',
            'group' => self::GROUP_DASHBOARD,
            'default_shared' => true,
            'default_white_label' => true,
        ],
        'dashboard_manage_categories' => [
            'label' => 'Manage categories',
            'description' => 'Hub admin can manage categories.',
            'group' => self::GROUP_DASHBOARD,
            'default_shared' => true,
            'default_white_label' => true,
        ],
        'dashboard_manage_tags' => [
            'label' => 'Manage tags',
            'description' => 'Hub admin can manage tags.',
            'group' => self::GROUP_DASHBOARD,
            'default_shared' => true,
            'default_white_label' => true,
        ],
        'dashboard_manage_plans' => [
            'label' => 'Manage subscription plans',
            'description' => 'Hub admin and Power Admin (when enabled) can manage subscription plans.',
            'group' => self::GROUP_DASHBOARD,
            'default_shared' => true,
            'default_white_label' => false,
        ],
        'dashboard_manage_settings' => [
            'label' => 'Manage settings',
            'description' => 'Hub admin can manage hub settings (e.g. NEW banner).',
            'group' => self::GROUP_DASHBOARD,
            'default_shared' => true,
            'default_white_label' => true,
        ],
        'dashboard_bank_transfers' => [
            'label' => 'Confirm bank transfers',
            'description' => 'Hub admin can confirm pending bank transfers.',
            'group' => self::GROUP_DASHBOARD,
            'default_shared' => true,
            'default_white_label' => false,
        ],
        'advisor_excel_import' => [
            'label' => 'Import advisors (Excel/CSV)',
            'description' => 'Hub admin and Power Admin (when enabled) can import advisors from an Excel/CSV sheet.',
            'group' => self::GROUP_DASHBOARD,
            'default_shared' => false,
            'default_white_label' => true,
        ],
        'advisor_discontinue' => [
            'label' => 'Discontinue advisors',
            'description' => 'End an imported advisor\'s access permanently (until re-imported). Separate from Excel import.',
            'group' => self::GROUP_DASHBOARD,
            'default_shared' => false,
            'default_white_label' => true,
        ],
        'dashboard_view_advisor_invoices' => [
            'label' => 'View advisor billing invoices',
            'description' => 'See invoices for private hub advisor subscriber billing (rate × advisors).',
            'group' => self::GROUP_DASHBOARD,
            'default_shared' => false,
            'default_white_label' => true,
        ],
        'dashboard_view_activity_logs' => [
            'label' => 'View activity logs / report',
            'description' => 'See the audit trail of user activity and the activity report for this hub. Who can open the report is controlled by this capability.',
            'group' => self::GROUP_DASHBOARD,
            'default_shared' => true,
            'default_white_label' => true,
        ],
        'dashboard_manage_advisor_pricing' => [
            'label' => 'Set advisor billing rates / quotas',
            'description' => 'Configure pricing tiers (rate per advisor) used for private hub billing (rate × advisors).',
            'group' => self::GROUP_DASHBOARD,
            'default_shared' => false,
            'default_white_label' => true,
        ],
        'dashboard_manage_advisor_renewal' => [
            'label' => 'Set advisor billing auto-renew date',
            'description' => 'Choose the monthly auto-renew day for private hub advisor billing (Power Admin / FinProms admin).',
            'group' => self::GROUP_DASHBOARD,
            'default_shared' => false,
            'default_white_label' => true,
        ],
        'dashboard_ai_content' => [
            'label' => 'AI content generation',
            'description' => 'Hub admin (typically FinProms admin on shared) can generate AI posts (future).',
            'group' => self::GROUP_DASHBOARD,
            'default_shared' => true,
            'default_white_label' => false,
        ],
        'dashboard_push_content' => [
            'label' => 'Push content to white-label hubs',
            'description' => 'Shared hub admin can select white-label hubs to receive posts (future).',
            'group' => self::GROUP_DASHBOARD,
            'default_shared' => true,
            'default_white_label' => false,
        ],
    ];

    protected $fillable = [
        'name',
        'slug',
        'type',
        'is_active',
        'primary_color',
        'secondary_color',
        'logo_url',
        'checklist',
        'role_capabilities',
        'advisor_billing_renew_day',
        'advisor_stripe_subscription_id',
        'stripe_key',
        'stripe_secret',
        'stripe_webhook_secret',
        'stripe_currency',
    ];

    protected $hidden = [
        'stripe_secret',
        'stripe_webhook_secret',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'checklist' => 'array',
            'role_capabilities' => 'array',
            'advisor_billing_renew_day' => 'integer',
            'stripe_secret' => 'encrypted',
            'stripe_webhook_secret' => 'encrypted',
        ];
    }

    public function advisorBillingRenewDay(): int
    {
        $day = (int) ($this->advisor_billing_renew_day ?: 1);

        return max(1, min(28, $day));
    }

    public function hasStripeSecret(): bool
    {
        return filled($this->stripe_secret);
    }

    /**
     * Safe Stripe config for Power Admin UI (secrets masked).
     *
     * @return array<string, mixed>
     */
    public function stripeConfigForAdmin(): array
    {
        return [
            'key' => $this->stripe_key,
            'secret_set' => filled($this->stripe_secret),
            'webhook_secret_set' => filled($this->stripe_webhook_secret),
            'currency' => $this->stripe_currency ?: null,
        ];
    }

    public function isShared(): bool
    {
        return $this->type === self::TYPE_SHARED;
    }

    public function isPrivateInviteOnly(): bool
    {
        return (bool) ($this->resolvedChecklist()['private_invite_only'] ?? false);
    }

    public function isPublicSubscribe(): bool
    {
        $checklist = $this->resolvedChecklist();

        return (bool) ($checklist['public_subscribe'] ?? false)
            && ! (bool) ($checklist['private_invite_only'] ?? false);
    }

    public static function isPrivateCapability(string $key): bool
    {
        return in_array($key, self::PRIVATE_CAPABILITY_KEYS, true);
    }

    public static function isPublicCapability(string $key): bool
    {
        return in_array($key, self::PUBLIC_CAPABILITY_KEYS, true);
    }

    /**
     * @return array<string, bool>
     */
    public static function defaultChecklist(string $type = self::TYPE_WHITE_LABEL): array
    {
        $key = $type === self::TYPE_SHARED ? 'default_shared' : 'default_white_label';
        $defaults = [];

        foreach (self::CHECKLIST_DEFINITIONS as $flag => $meta) {
            $defaults[$flag] = (bool) $meta[$key];
        }

        return $defaults;
    }

    /**
     * @return array<string, bool>
     */
    public function resolvedChecklist(): array
    {
        $defaults = self::defaultChecklist($this->type);
        $stored = is_array($this->checklist) ? $this->checklist : [];

        $resolved = $defaults;
        foreach (array_keys(self::CHECKLIST_DEFINITIONS) as $flag) {
            if (array_key_exists($flag, $stored)) {
                $resolved[$flag] = filter_var($stored[$flag], FILTER_VALIDATE_BOOLEAN);
            }
        }

        return $resolved;
    }

    public function can(string $flag): bool
    {
        $checklist = $this->resolvedChecklist();

        return (bool) ($checklist[$flag] ?? false);
    }

    public static function isFunctionalityKey(string $key): bool
    {
        $group = self::CHECKLIST_DEFINITIONS[$key]['group'] ?? null;

        return in_array($group, self::FUNCTIONALITY_GROUPS, true);
    }

    public static function isCapabilityKey(string $key): bool
    {
        $group = self::CHECKLIST_DEFINITIONS[$key]['group'] ?? null;

        return in_array($group, self::CAPABILITY_GROUPS, true);
    }

    /**
     * Admin hub-checklist payload: Functionalities only (not user capabilities).
     *
     * @return list<array{key: string, label: string, description: string, group: string, group_label: string, enabled: bool, exclusive_with: ?string}>
     */
    public function checklistForAdmin(): array
    {
        $resolved = $this->resolvedChecklist();
        $items = [];

        foreach (self::CHECKLIST_DEFINITIONS as $key => $meta) {
            $group = $meta['group'] ?? self::GROUP_BEHAVIOUR;
            if (! in_array($group, self::FUNCTIONALITY_GROUPS, true)) {
                continue;
            }
            $items[] = [
                'key' => $key,
                'label' => $meta['label'],
                'description' => $meta['description'],
                'group' => $group,
                'group_label' => self::CHECKLIST_GROUPS[$group] ?? $group,
                'enabled' => (bool) ($resolved[$key] ?? false),
                'exclusive_with' => self::CHECKLIST_OPPOSITES[$key] ?? null,
            ];
        }

        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        $checklist = $this->resolvedChecklist();
        $registrationEnabled = (bool) ($checklist['public_subscribe'] ?? false)
            && ! (bool) ($checklist['private_invite_only'] ?? false);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'type' => $this->type,
            'branding' => [
                'primary_color' => $this->primary_color,
                'secondary_color' => $this->secondary_color,
                'logo_url' => $this->logo_url,
            ],
            'checklist' => $checklist,
            // Frontend should hide Sign up when registration_enabled is false.
            'auth' => [
                'registration_enabled' => $registrationEnabled,
                'invite_only' => (bool) ($checklist['private_invite_only'] ?? false),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toAdminArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'type' => $this->type,
            'is_active' => $this->is_active,
            'branding' => [
                'primary_color' => $this->primary_color,
                'secondary_color' => $this->secondary_color,
                'logo_url' => $this->logo_url,
            ],
            'stripe' => $this->stripeConfigForAdmin(),
            'checklist' => $this->checklistForAdmin(),
            'checklist_groups' => array_intersect_key(
                self::CHECKLIST_GROUPS,
                array_flip(self::FUNCTIONALITY_GROUPS)
            ),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
