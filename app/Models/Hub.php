<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Hub extends Model
{
    public const TYPE_SHARED = 'shared';

    public const TYPE_WHITE_LABEL = 'white_label';

    public const GROUP_BEHAVIOUR = 'behaviour';

    public const GROUP_MODULES = 'modules';

    public const GROUP_MEMBER = 'member';

    public const GROUP_GENERAL = 'general';

    /** @deprecated Prefer the split dashboard_* groups; kept for legacy comparisons. */
    public const GROUP_DASHBOARD = 'dashboard';

    public const GROUP_DASHBOARD_CONTENT = 'dashboard_content';

    public const GROUP_DASHBOARD_FIRMS = 'dashboard_firms';

    public const GROUP_DASHBOARD_ADVISORS = 'dashboard_advisors';

    public const GROUP_DASHBOARD_HUB = 'dashboard_hub';

    public const GROUP_ADMIN_EMAILS = 'admin_emails';

    public const GROUP_SOCIAL_MEDIA_COMPLIANCE = 'social_media_compliance';

    public const GROUP_GENERAL_COMPLIANCE = 'general_compliance';

    public const GROUP_WEBSITE_COMPLIANCE = 'website_compliance';

    public const GROUP_WEBSITE_TEMPLATE_LIBRARY = 'website_template_library';

    /**
     * Default display labels for compliance statuses (snake_case keys).
     * Used by SMC / GC / WC — DB may store Title Case (SMC/GC) or snake_case (WC).
     *
     * @var array<string, string>
     */
    public const COMPLIANCE_STATUS_LABELS = [
        'pending' => 'Pending',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'approved_with_feedback' => 'Approved with Feedback',
        'under_review' => 'Under review',
        'scheduled' => 'Scheduled',
        'deployed' => 'Deployed',
    ];

    /**
     * @var array<string, string>
     */
    public const CHECKLIST_GROUPS = [
        self::GROUP_BEHAVIOUR => 'Functionalities',
        self::GROUP_MODULES => 'Modules',
        self::GROUP_MEMBER => 'Member catalog',
        self::GROUP_GENERAL => 'Member personal dashboard',
        self::GROUP_DASHBOARD_CONTENT => 'Content catalog',
        self::GROUP_DASHBOARD_FIRMS => 'Firms',
        self::GROUP_DASHBOARD_ADVISORS => 'Advisors & private billing',
        self::GROUP_DASHBOARD_HUB => 'Hub operations',
        self::GROUP_DASHBOARD => 'Hub-admin dashboard',
        self::GROUP_ADMIN_EMAILS => 'Admin emails',
        self::GROUP_SOCIAL_MEDIA_COMPLIANCE => 'Social Media Pre Approval',
        self::GROUP_GENERAL_COMPLIANCE => 'Generic Content Pre Approval',
        self::GROUP_WEBSITE_TEMPLATE_LIBRARY => 'Website Template Library',
        self::GROUP_WEBSITE_COMPLIANCE => 'Website Content Pre Approval',
    ];

    /**
     * Display order for Capabilities matrix sections (Power Admin column is prepended in the service).
     *
     * @var list<string>
     */
    public const MATRIX_GROUP_ORDER = [
        self::GROUP_MEMBER,
        self::GROUP_GENERAL,
        self::GROUP_DASHBOARD_CONTENT,
        self::GROUP_DASHBOARD_FIRMS,
        self::GROUP_DASHBOARD_ADVISORS,
        self::GROUP_DASHBOARD_HUB,
        self::GROUP_ADMIN_EMAILS,
        self::GROUP_SOCIAL_MEDIA_COMPLIANCE,
        self::GROUP_GENERAL_COMPLIANCE,
        self::GROUP_WEBSITE_TEMPLATE_LIBRARY,
        self::GROUP_WEBSITE_COMPLIANCE,
    ];

    /**
     * Hub-admin dashboard capability groups (formerly a single mixed "dashboard" bucket).
     *
     * @var list<string>
     */
    public const DASHBOARD_CAPABILITY_GROUPS = [
        self::GROUP_DASHBOARD_CONTENT,
        self::GROUP_DASHBOARD_FIRMS,
        self::GROUP_DASHBOARD_ADVISORS,
        self::GROUP_DASHBOARD_HUB,
        self::GROUP_DASHBOARD, // legacy keys if any remain
    ];

    /**
     * Groups edited on the Hub checklist screen (Functionalities + Modules).
     *
     * @var list<string>
     */
    public const FUNCTIONALITY_GROUPS = [
        self::GROUP_BEHAVIOUR,
        self::GROUP_MODULES,
    ];

    /**
     * Groups edited on the Capabilities matrix (user / role capabilities).
     *
     * @var list<string>
     */
    public const CAPABILITY_GROUPS = [
        self::GROUP_MEMBER,
        self::GROUP_GENERAL,
        self::GROUP_DASHBOARD_CONTENT,
        self::GROUP_DASHBOARD_FIRMS,
        self::GROUP_DASHBOARD_ADVISORS,
        self::GROUP_DASHBOARD_HUB,
        self::GROUP_ADMIN_EMAILS,
        self::GROUP_SOCIAL_MEDIA_COMPLIANCE,
        self::GROUP_GENERAL_COMPLIANCE,
        self::GROUP_WEBSITE_TEMPLATE_LIBRARY,
        self::GROUP_WEBSITE_COMPLIANCE,
    ];

    public static function isDashboardCapabilityGroup(?string $group): bool
    {
        return in_array($group, self::DASHBOARD_CAPABILITY_GROUPS, true);
    }

    /**
     * All known module keys (including both Shared Hub and White Label Hub bases).
     *
     * @var list<string>
     */
    public const MODULE_KEYS = [
        'module_shared_hub',
        'module_white_label_hub',
        'module_social_media_template_library',
        'module_social_media_compliance',
        'module_website_template_library',
        'module_website_compliance',
        'module_general_compliance',
    ];

    /**
     * Product modules shown on the Modules page after the hub-type base module.
     *
     * @var list<string>
     */
    public const PRODUCT_MODULE_KEYS = [
        'module_social_media_template_library',
        'module_social_media_compliance',
        'module_website_template_library',
        'module_website_compliance',
        'module_general_compliance',
    ];

    /**
     * Modules that cannot be toggled — state is derived from hub type.
     *
     * @var list<string>
     */
    public const LOCKED_MODULE_KEYS = [
        'module_shared_hub',
        'module_white_label_hub',
    ];

    /**
     * Module dependency graph.
     * `__base__` resolves to Shared Hub or White Label Hub for the current hub.
     *
     * 1 base → no deps
     * 2 (SM template library) → 1
     * 3 (SM pre-approval) → 1 + 2
     * 4 (Website template library) → 1
     * 5 (Website content pre-approval) → 1 + 4
     * 6 (Generic content pre-approval) → 1
     *
     * @var array<string, list<string>>
     */
    public const MODULE_DEPENDENCIES = [
        'module_shared_hub' => [],
        'module_white_label_hub' => [],
        'module_social_media_template_library' => ['__base__'],
        'module_social_media_compliance' => ['__base__', 'module_social_media_template_library'],
        'module_website_template_library' => ['__base__'],
        'module_website_compliance' => ['__base__', 'module_website_template_library'],
        'module_general_compliance' => ['__base__'],
    ];

    /**
     * Base packaging module key for this hub type (slot #1 on the Modules page).
     */
    public function baseModuleKey(): string
    {
        return $this->isShared() ? 'module_shared_hub' : 'module_white_label_hub';
    }

    /**
     * Ordered module keys for the Modules page (exactly 6 for this hub).
     *
     * @return list<string>
     */
    public function moduleKeysForPage(): array
    {
        return array_values(array_merge([$this->baseModuleKey()], self::PRODUCT_MODULE_KEYS));
    }

    /**
     * Resolve dependency keys for a module on this hub (__base__ → shared/white-label key).
     *
     * @return list<string>
     */
    public function moduleDependenciesFor(string $key): array
    {
        $deps = self::MODULE_DEPENDENCIES[$key] ?? [];
        $base = $this->baseModuleKey();

        return array_values(array_map(
            fn (string $dep) => $dep === '__base__' ? $base : $dep,
            $deps
        ));
    }

    /**
     * Force locked base modules and cascade-disable dependents when parents are off.
     *
     * @param  array<string, bool>  $checklist
     * @return array<string, bool>
     */
    public function applyModuleDependencies(array $checklist): array
    {
        return self::applyModuleDependencyRules($checklist, $this->type);
    }

    /**
     * @param  array<string, bool>  $checklist
     * @return array<string, bool>
     */
    public static function applyModuleDependencyRules(array $checklist, string $type): array
    {
        $isShared = $type === self::TYPE_SHARED;
        $checklist['module_shared_hub'] = $isShared;
        $checklist['module_white_label_hub'] = ! $isShared;
        $base = $isShared ? 'module_shared_hub' : 'module_white_label_hub';

        // Cascade in MODULE_KEYS order so parents are resolved before children.
        foreach (self::MODULE_KEYS as $key) {
            if (self::isLockedModuleKey($key)) {
                continue;
            }
            $deps = self::MODULE_DEPENDENCIES[$key] ?? [];
            foreach ($deps as $dep) {
                $resolvedDep = $dep === '__base__' ? $base : $dep;
                if (! ($checklist[$resolvedDep] ?? false)) {
                    $checklist[$key] = false;
                    break;
                }
            }
        }

        if (! ($checklist['module_social_media_template_library'] ?? false)) {
            foreach (self::SOCIAL_MEDIA_TEMPLATE_LIBRARY_FUNCTIONALITY_KEYS as $funcKey) {
                $checklist[$funcKey] = false;
            }
        }

        return $checklist;
    }

    /**
     * Whether a module is on and all of its dependencies are satisfied.
     */
    public function moduleEffectivelyEnabled(string $key): bool
    {
        $checklist = $this->resolvedChecklist();
        if (! ($checklist[$key] ?? false)) {
            return false;
        }

        foreach ($this->moduleDependenciesFor($key) as $dep) {
            if (! ($checklist[$dep] ?? false)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Functionalities that require Social Media Template Library.
     * Inactive / forced off while that module is disabled.
     *
     * @var list<string>
     */
    public const SOCIAL_MEDIA_TEMPLATE_LIBRARY_FUNCTIONALITY_KEYS = [
        'one_off_purchase',
        'receive_content_from_shared',
    ];

    /**
     * Capabilities that require Social Media Template Library (posts catalog / buying).
     *
     * @var list<string>
     */
    public const SOCIAL_MEDIA_TEMPLATE_LIBRARY_CAPABILITY_KEYS = [
        'member_browse_catalog',
        'member_purchase_content',
        'member_download_content',
        'member_in_app_edit',
        'general_show_purchases',
        'dashboard_manage_posts',
        'dashboard_manage_bundles',
        'dashboard_manage_types',
        'dashboard_manage_categories',
        'dashboard_manage_tags',
        'dashboard_ai_content',
    ];

    /**
     * Social Media Pre Approval (compliance) capabilities — inactive while the module is off.
     *
     * @var list<string>
     */
    public const SOCIAL_MEDIA_COMPLIANCE_CAPABILITY_KEYS = [
        'smc_submit_request',
        'smc_view_own_requests',
        'smc_assign_requests',
        'smc_review_requests',
        'smc_change_request_status',
        'smc_view_all_requests',
        'smc_view_reports',
    ];

    /**
     * Generic Content Pre Approval capabilities — inactive while the module is off.
     *
     * @var list<string>
     */
    public const GENERAL_COMPLIANCE_CAPABILITY_KEYS = [
        'gc_submit_request',
        'gc_view_own_requests',
        'gc_assign_requests',
        'gc_review_requests',
        'gc_change_request_status',
        'gc_view_all_requests',
        'gc_view_reports',
    ];

    /**
     * Website Template Library (showcase) capabilities — inactive while the module is off.
     *
     * @var list<string>
     */
    public const WEBSITE_TEMPLATE_LIBRARY_CAPABILITY_KEYS = [
        'wc_manage_templates',
        'wc_request_deployments',
        'wc_assign_website_templates',
        'wc_view_all_deployments',
        'wc_deploy_websites',
    ];

    /**
     * Website Content Pre Approval capabilities — inactive while the module is off.
     *
     * @var list<string>
     */
    public const WEBSITE_COMPLIANCE_CAPABILITY_KEYS = [
        'wc_edit_sections',
        'wc_submit_change_requests',
        'wc_assign_change_requests',
        'wc_view_all_change_requests',
        'wc_review_change_requests',
        'wc_change_request_status',
        'wc_manage_deployment_sections',
        'wc_publish_live_content',
        'wc_view_activity_logs',
        'wc_view_platform_report',
    ];

    /**
     * Member personal-dashboard sections (General options).
     *
     * @var list<string>
     */
    public const GENERAL_DASHBOARD_KEYS = [
        'general_show_subscription',
        'general_show_credits',
        'general_show_invoices',
        'general_show_purchases',
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
     * Dashboard/member capabilities that only apply while the hub is white-labelled (invite-only).
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
        'dashboard_manage_subscriber_credits',
    ];

    /**
     * Capabilities that only apply while the hub is shared (self-serve subscribe).
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
            'label' => 'Shared subscribe / self-registration',
            'description' => 'Anyone can register and subscribe on this shared hub.',
            'group' => self::GROUP_BEHAVIOUR,
            'default_shared' => true,
            'default_white_label' => false,
        ],
        'private_invite_only' => [
            'label' => 'White-labelled invite-only access',
            'description' => 'Only invited advisors (e.g. Excel import) can access this white-labelled hub.',
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
            'description' => 'White-labelled hub subscribers get unlimited credits (or set a fixed allotment under Subscriber credits).',
            'group' => self::GROUP_BEHAVIOUR,
            'default_shared' => false,
            'default_white_label' => true,
        ],
        'one_off_purchase' => [
            'label' => 'One-off purchase (non-subscribers)',
            'description' => 'Non-subscribers can buy posts/reels and post bundles without a subscription (1 credit = £1), using credits or an enabled payment method (Stripe / bank transfer).',
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
        'advisor_subscriber_billing' => [
            'label' => 'Advisor subscriber billing (rate × advisors)',
            'description' => 'After Excel advisor import, bill rate × advisor count. Stripe auto-renews monthly.',
            'group' => self::GROUP_BEHAVIOUR,
            'default_shared' => false,
            'default_white_label' => true,
        ],

        // --- Modules (hub Modules page) ---
        'module_shared_hub' => [
            'label' => 'Shared Hub',
            'description' => 'Shared hub product packaging. Always on (and locked) for the shared hub. Other modules depend on this base module.',
            'group' => self::GROUP_MODULES,
            'default_shared' => true,
            'default_white_label' => false,
        ],
        'module_white_label_hub' => [
            'label' => 'White Label Hub',
            'description' => 'White-labelled hub product packaging. Always on (and locked) for white-labelled hubs. Other modules depend on this base module.',
            'group' => self::GROUP_MODULES,
            'default_shared' => false,
            'default_white_label' => true,
        ],
        'module_social_media_template_library' => [
            'label' => 'Social Media Template Library',
            'description' => 'Enable the posts / reels catalog, purchasing, and content-management tools. Requires Shared / White Label Hub. Related functionalities and capabilities stay inactive while this module is off.',
            'group' => self::GROUP_MODULES,
            'default_shared' => true,
            'default_white_label' => true,
        ],
        'module_social_media_compliance' => [
            'label' => 'Social Media Pre Approval Workflow',
            'description' => 'Enable social media post pre-approval / compliance workflow. Requires Shared / White Label Hub and Social Media Template Library.',
            'group' => self::GROUP_MODULES,
            'default_shared' => false,
            'default_white_label' => false,
        ],
        'module_website_template_library' => [
            'label' => 'Website Template Library',
            'description' => 'Enable the website showcase template library, deployment requests, and template assignment. Requires Shared / White Label Hub.',
            'group' => self::GROUP_MODULES,
            'default_shared' => false,
            'default_white_label' => false,
        ],
        'module_website_compliance' => [
            'label' => 'Website Content Pre Approval Workflow',
            'description' => 'Enable website content pre-approval (section editing, change requests, and publish workflow). Requires Shared / White Label Hub and Website Template Library.',
            'group' => self::GROUP_MODULES,
            'default_shared' => false,
            'default_white_label' => false,
        ],
        'module_general_compliance' => [
            'label' => 'Generic Content Pre Approval Workflow',
            'description' => 'Enable generic content pre-approval workflow (free-form description + file attachments). Requires Shared / White Label Hub.',
            'group' => self::GROUP_MODULES,
            'default_shared' => false,
            'default_white_label' => false,
        ],

        // --- Member capabilities ---
        'member_browse_catalog' => [
            'label' => 'Browse catalog (posts / reels)',
            'description' => 'Can browse the content catalog. Configurable per role in the Capabilities matrix (all roles).',
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
        'member_in_app_edit' => [
            'label' => 'In-app post editing',
            'description' => 'Buyers can edit purchased posts in-app (future).',
            'group' => self::GROUP_MEMBER,
            'default_shared' => false,
            'default_white_label' => false,
        ],

        // --- General options (member personal dashboard) ---
        'general_show_subscription' => [
            'label' => 'Show subscription',
            'description' => 'Show the member’s active subscription / plan details on their dashboard.',
            'group' => self::GROUP_GENERAL,
            'default_shared' => true,
            'default_white_label' => true,
        ],
        'general_show_credits' => [
            'label' => 'Show remaining credits',
            'description' => 'Show remaining (or unlimited) credits on the member dashboard.',
            'group' => self::GROUP_GENERAL,
            'default_shared' => true,
            'default_white_label' => true,
        ],
        'general_show_invoices' => [
            'label' => 'Show invoices',
            'description' => 'Show recent personal invoices on the member dashboard.',
            'group' => self::GROUP_GENERAL,
            'default_shared' => true,
            'default_white_label' => true,
        ],
        'general_show_purchases' => [
            'label' => 'Show purchases',
            'description' => 'Show recent content purchases on the member dashboard.',
            'group' => self::GROUP_GENERAL,
            'default_shared' => true,
            'default_white_label' => true,
        ],

        // --- 3. Content catalog ---
        'dashboard_manage_posts' => [
            'label' => 'Manage posts / reels',
            'description' => 'Hub admin can create and edit posts/reels.',
            'group' => self::GROUP_DASHBOARD_CONTENT,
            'default_shared' => true,
            'default_white_label' => true,
        ],
        'dashboard_manage_bundles' => [
            'label' => 'Manage post bundles',
            'description' => 'Create bundles of posts/reels (existing or new), with description and total credits.',
            'group' => self::GROUP_DASHBOARD_CONTENT,
            'default_shared' => true,
            'default_white_label' => true,
        ],
        'dashboard_manage_types' => [
            'label' => 'Manage types',
            'description' => 'Hub admin can manage content types.',
            'group' => self::GROUP_DASHBOARD_CONTENT,
            'default_shared' => true,
            'default_white_label' => true,
        ],
        'dashboard_manage_categories' => [
            'label' => 'Manage categories',
            'description' => 'Hub admin can manage categories.',
            'group' => self::GROUP_DASHBOARD_CONTENT,
            'default_shared' => true,
            'default_white_label' => true,
        ],
        'dashboard_manage_tags' => [
            'label' => 'Manage tags',
            'description' => 'Hub admin can manage tags.',
            'group' => self::GROUP_DASHBOARD_CONTENT,
            'default_shared' => true,
            'default_white_label' => true,
        ],

        // --- 4. Firms ---
        'dashboard_manage_firms' => [
            'label' => 'Manage firms',
            'description' => 'Add/manage firms and set which firm (own, Central/Network, or another) may review, approve, and see reports for each firm’s compliance requests. Shared hub self-registration does not ask for a firm.',
            'group' => self::GROUP_DASHBOARD_FIRMS,
            'default_shared' => true,
            'default_white_label' => true,
        ],

        // --- 5. Advisors & private billing ---
        'advisor_excel_import' => [
            'label' => 'Import advisors (Excel)',
            'description' => 'Hub admin and Power Admin (when enabled) can import advisors from an Excel sheet.',
            'group' => self::GROUP_DASHBOARD_ADVISORS,
            'default_shared' => false,
            'default_white_label' => true,
        ],
        'advisor_discontinue' => [
            'label' => 'Discontinue advisors',
            'description' => 'End an imported advisor\'s access permanently (until re-imported). Separate from Excel import.',
            'group' => self::GROUP_DASHBOARD_ADVISORS,
            'default_shared' => false,
            'default_white_label' => true,
        ],
        'dashboard_view_advisor_invoices' => [
            'label' => 'View advisor billing invoices',
            'description' => 'See invoices for white-labelled hub advisor subscriber billing (rate × advisors).',
            'group' => self::GROUP_DASHBOARD_ADVISORS,
            'default_shared' => false,
            'default_white_label' => true,
        ],
        'dashboard_manage_advisor_pricing' => [
            'label' => 'Set advisor billing rates / quotas',
            'description' => 'Configure pricing tiers (rate per advisor) used for white-labelled hub billing (rate × advisors).',
            'group' => self::GROUP_DASHBOARD_ADVISORS,
            'default_shared' => false,
            'default_white_label' => true,
        ],
        'dashboard_manage_advisor_renewal' => [
            'label' => 'Set advisor billing auto-renew date',
            'description' => 'Choose the monthly auto-renew day for white-labelled hub advisor billing (Power Admin / FinProms admin).',
            'group' => self::GROUP_DASHBOARD_ADVISORS,
            'default_shared' => false,
            'default_white_label' => true,
        ],
        'dashboard_manage_subscriber_credits' => [
            'label' => 'Set subscriber credits (white-labelled hub)',
            'description' => 'Set unlimited or a fixed credit allotment for Excel-imported white-labelled hub subscribers (applied on import and autorenew). White-labelled hub only — inactive while the hub is shared.',
            'group' => self::GROUP_DASHBOARD_ADVISORS,
            'default_shared' => false,
            'default_white_label' => true,
        ],

        // --- 6. Hub operations ---
        'dashboard_manage_plans' => [
            'label' => 'Manage subscription plans',
            'description' => 'Hub admin and Power Admin (when enabled) can manage subscription plans.',
            'group' => self::GROUP_DASHBOARD_HUB,
            'default_shared' => true,
            'default_white_label' => false,
        ],
        'dashboard_manage_settings' => [
            'label' => 'Manage settings',
            'description' => 'Hub admin can manage hub settings: NEW banner, logo, favicon, auth background, color scheme, and application name.',
            'group' => self::GROUP_DASHBOARD_HUB,
            'default_shared' => true,
            'default_white_label' => true,
        ],
        'dashboard_manage_role_display_names' => [
            'label' => 'Set role display names',
            'description' => 'Customize how role names appear in this hub’s UI (shared or white-labelled). Who may edit labels is controlled by this capability.',
            'group' => self::GROUP_DASHBOARD_HUB,
            'default_shared' => true,
            'default_white_label' => true,
        ],
        'dashboard_manage_compliance_status_display_names' => [
            'label' => 'Set compliance status display names',
            'description' => 'Customize how compliance statuses appear (Pending, Approved, Rejected, Approved with Feedback, and related WC statuses) across Social Media, Generic Content, and Website pre-approval workflows.',
            'group' => self::GROUP_DASHBOARD_HUB,
            'default_shared' => true,
            'default_white_label' => true,
        ],
        'dashboard_bank_transfers' => [
            'label' => 'Confirm bank transfers',
            'description' => 'Hub admin can confirm pending bank transfers.',
            'group' => self::GROUP_DASHBOARD_HUB,
            'default_shared' => true,
            'default_white_label' => false,
        ],
        'dashboard_view_activity_logs' => [
            'label' => 'View activity logs / report',
            'description' => 'See the audit trail of user activity and the activity report for this hub. Who can open the report is controlled by this capability.',
            'group' => self::GROUP_DASHBOARD_HUB,
            'default_shared' => true,
            'default_white_label' => true,
        ],
        'dashboard_ai_content' => [
            'label' => 'AI content generation',
            'description' => 'Hub admin (typically FinProms admin on shared) can generate AI posts (future).',
            'group' => self::GROUP_DASHBOARD_HUB,
            'default_shared' => true,
            'default_white_label' => false,
        ],
        'dashboard_control_white_label_hubs' => [
            'label' => 'Control white labelled hubs',
            'description' => 'On the shared hub dashboard, unlocks a hub switcher. Selecting a white-labelled hub shows that hub’s dashboard tools (based on its Capabilities matrix). Creating users / posts / types / categories / tags / bundles while that hub is selected writes only to that hub’s own database — not the shared catalog.',
            'group' => self::GROUP_DASHBOARD_HUB,
            'default_shared' => true,
            'default_white_label' => false,
        ],
        'dashboard_manage_modules' => [
            'label' => 'Manage hub modules',
            'description' => 'Enable or disable product modules for this hub (White Label, Social Media / Website template libraries, and pre-approval workflows). Related capability sections stay blurred while a module is off.',
            'group' => self::GROUP_DASHBOARD_HUB,
            'default_shared' => true,
            'default_white_label' => true,
        ],

        // --- 7. Admin emails ---
        'receive_admin_emails' => [
            'label' => 'Receive admin emails',
            'description' => 'Receive all admin notification emails for this hub (registrations, purchases, payments, advisor events, etc.).',
            'group' => self::GROUP_ADMIN_EMAILS,
            'default_shared' => true,
            'default_white_label' => true,
        ],
        'dashboard_manage_email_templates' => [
            'label' => 'Manage email templates',
            'description' => 'Create and edit transactional email templates for this hub (subject, heading, body). Layout (logo and theme colours) stays branded. Admin recipients are still controlled by “Receive admin emails”.',
            'group' => self::GROUP_ADMIN_EMAILS,
            'default_shared' => true,
            'default_white_label' => true,
        ],

        // --- Social Media Pre Approval (blurred while module_social_media_compliance is off) ---
        'smc_submit_request' => [
            'label' => 'Submit social media compliance requests',
            'description' => 'Submit a purchased post for social media compliance review (and resubmit after rejection / confirm after approved-with-feedback).',
            'group' => self::GROUP_SOCIAL_MEDIA_COMPLIANCE,
            'default_shared' => false,
            'default_white_label' => false,
        ],
        'smc_view_own_requests' => [
            'label' => 'View own social media compliance requests',
            'description' => 'View personal social media compliance request history, detail, and version timeline.',
            'group' => self::GROUP_SOCIAL_MEDIA_COMPLIANCE,
            'default_shared' => false,
            'default_white_label' => false,
        ],
        'smc_assign_requests' => [
            'label' => 'Assign social media compliance requests',
            'description' => 'Assign or unassign social media compliance requests to reviewers.',
            'group' => self::GROUP_SOCIAL_MEDIA_COMPLIANCE,
            'default_shared' => false,
            'default_white_label' => false,
        ],
        'smc_review_requests' => [
            'label' => 'Review social media compliance requests',
            'description' => 'Set status and feedback on social media compliance requests (Pending / Approved / Rejected / Approved with Feedback).',
            'group' => self::GROUP_SOCIAL_MEDIA_COMPLIANCE,
            'default_shared' => false,
            'default_white_label' => false,
        ],
        'smc_change_request_status' => [
            'label' => 'Change social media compliance request status',
            'description' => 'Override a request’s status by creating a new version with an optional comment (typically for managers). Respects firm visibility.',
            'group' => self::GROUP_SOCIAL_MEDIA_COMPLIANCE,
            'default_shared' => false,
            'default_white_label' => false,
        ],
        'smc_view_all_requests' => [
            'label' => 'View all social media compliance requests',
            'description' => 'See the full social media compliance queue for the hub (not only assigned requests).',
            'group' => self::GROUP_SOCIAL_MEDIA_COMPLIANCE,
            'default_shared' => false,
            'default_white_label' => false,
        ],
        'smc_view_reports' => [
            'label' => 'View social media compliance reports & charts',
            'description' => 'Open social media compliance reports, export, approver workload, and advisor comparison charts.',
            'group' => self::GROUP_SOCIAL_MEDIA_COMPLIANCE,
            'default_shared' => false,
            'default_white_label' => false,
        ],

        // --- Generic Content Pre Approval (blurred while module_general_compliance is off) ---
        'gc_submit_request' => [
            'label' => 'Submit general compliance requests',
            'description' => 'Submit a free-form general compliance request with description and file attachments (and resubmit after rejection / confirm after approved-with-feedback).',
            'group' => self::GROUP_GENERAL_COMPLIANCE,
            'default_shared' => false,
            'default_white_label' => false,
        ],
        'gc_view_own_requests' => [
            'label' => 'View own general compliance requests',
            'description' => 'View personal general compliance request history, detail, attachments, and version timeline.',
            'group' => self::GROUP_GENERAL_COMPLIANCE,
            'default_shared' => false,
            'default_white_label' => false,
        ],
        'gc_assign_requests' => [
            'label' => 'Assign general compliance requests',
            'description' => 'Assign or unassign general compliance requests to reviewers.',
            'group' => self::GROUP_GENERAL_COMPLIANCE,
            'default_shared' => false,
            'default_white_label' => false,
        ],
        'gc_review_requests' => [
            'label' => 'Review general compliance requests',
            'description' => 'Set status and feedback on general compliance requests (Pending / Approved / Rejected / Approved with Feedback).',
            'group' => self::GROUP_GENERAL_COMPLIANCE,
            'default_shared' => false,
            'default_white_label' => false,
        ],
        'gc_change_request_status' => [
            'label' => 'Change general compliance request status',
            'description' => 'Override a request’s status by creating a new version with an optional comment (typically for managers). Respects firm visibility.',
            'group' => self::GROUP_GENERAL_COMPLIANCE,
            'default_shared' => false,
            'default_white_label' => false,
        ],
        'gc_view_all_requests' => [
            'label' => 'View all general compliance requests',
            'description' => 'See the full general compliance queue for the hub (not only assigned requests).',
            'group' => self::GROUP_GENERAL_COMPLIANCE,
            'default_shared' => false,
            'default_white_label' => false,
        ],
        'gc_view_reports' => [
            'label' => 'View general compliance reports & charts',
            'description' => 'Open general compliance reports, export, approver workload, and advisor comparison charts.',
            'group' => self::GROUP_GENERAL_COMPLIANCE,
            'default_shared' => false,
            'default_white_label' => false,
        ],

        // --- Website Template Library (blurred while module_website_template_library is off) ---
        'wc_manage_templates' => [
            'label' => 'Manage website templates',
            'description' => 'Create, edit, and delete showcase templates.',
            'group' => self::GROUP_WEBSITE_TEMPLATE_LIBRARY,
            'default_shared' => false,
            'default_white_label' => false,
        ],
        'wc_request_deployments' => [
            'label' => 'Request website deployments',
            'description' => 'Request a new showcase / advisor site deployment from a template (self-serve).',
            'group' => self::GROUP_WEBSITE_TEMPLATE_LIBRARY,
            'default_shared' => false,
            'default_white_label' => false,
        ],
        'wc_assign_website_templates' => [
            'label' => 'Assign website templates',
            'description' => 'Request showcase site deployments and assign them to advisors for content editing.',
            'group' => self::GROUP_WEBSITE_TEMPLATE_LIBRARY,
            'default_shared' => false,
            'default_white_label' => false,
        ],
        'wc_view_all_deployments' => [
            'label' => 'View all website deployments',
            'description' => 'See all deployment requests (list only; does not allow requesting or deploying).',
            'group' => self::GROUP_WEBSITE_TEMPLATE_LIBRARY,
            'default_shared' => false,
            'default_white_label' => false,
        ],
        'wc_deploy_websites' => [
            'label' => 'Deploy websites to cPanel',
            'description' => 'Deploy or update advisor sites on cPanel with domain and database credentials.',
            'group' => self::GROUP_WEBSITE_TEMPLATE_LIBRARY,
            'default_shared' => false,
            'default_white_label' => false,
        ],

        // --- Website Content Pre Approval (blurred while module_website_compliance is off) ---
        'wc_edit_sections' => [
            'label' => 'Edit website sections',
            'description' => 'Lock and edit website section content on a deployed template.',
            'group' => self::GROUP_WEBSITE_COMPLIANCE,
            'default_shared' => false,
            'default_white_label' => false,
        ],
        'wc_submit_change_requests' => [
            'label' => 'Submit website change requests',
            'description' => 'Submit section edits for approver review before they go live.',
            'group' => self::GROUP_WEBSITE_COMPLIANCE,
            'default_shared' => false,
            'default_white_label' => false,
        ],
        'wc_assign_change_requests' => [
            'label' => 'Assign website change requests',
            'description' => 'Assign pending website change requests to an approver.',
            'group' => self::GROUP_WEBSITE_COMPLIANCE,
            'default_shared' => false,
            'default_white_label' => false,
        ],
        'wc_view_all_change_requests' => [
            'label' => 'View all website change requests',
            'description' => 'See every website change request across the hub (not only requests assigned to you). Leave off for Approvers so they only see work they picked.',
            'group' => self::GROUP_WEBSITE_COMPLIANCE,
            'default_shared' => false,
            'default_white_label' => false,
        ],
        'wc_review_change_requests' => [
            'label' => 'Review website change requests',
            'description' => 'Approve, reject, or schedule submitted website content changes.',
            'group' => self::GROUP_WEBSITE_COMPLIANCE,
            'default_shared' => false,
            'default_white_label' => false,
        ],
        'wc_change_request_status' => [
            'label' => 'Change website compliance request status',
            'description' => 'Override a change request’s status by creating a new version with an optional comment (typically for managers). Not allowed once content is scheduled or published. Respects firm visibility.',
            'group' => self::GROUP_WEBSITE_COMPLIANCE,
            'default_shared' => false,
            'default_white_label' => false,
        ],
        'wc_manage_deployment_sections' => [
            'label' => 'Manage deployment section visibility',
            'description' => 'Show or hide sections on a deployed site.',
            'group' => self::GROUP_WEBSITE_COMPLIANCE,
            'default_shared' => false,
            'default_white_label' => false,
        ],
        'wc_publish_live_content' => [
            'label' => 'Publish live website content',
            'description' => 'Edit and publish live site content without approver review.',
            'group' => self::GROUP_WEBSITE_COMPLIANCE,
            'default_shared' => false,
            'default_white_label' => false,
        ],
        'wc_view_activity_logs' => [
            'label' => 'View website compliance activity logs',
            'description' => 'View website compliance audit and activity logs.',
            'group' => self::GROUP_WEBSITE_COMPLIANCE,
            'default_shared' => false,
            'default_white_label' => false,
        ],
        'wc_view_platform_report' => [
            'label' => 'View website compliance platform report',
            'description' => 'View and refresh the website compliance platform summary report.',
            'group' => self::GROUP_WEBSITE_COMPLIANCE,
            'default_shared' => false,
            'default_white_label' => false,
        ],
    ];

    /**
     * Dashboard content tools that follow the acting white-labelled hub context
     * when Control white labelled hubs is enabled.
     *
     * @var list<string>
     */
    public const ACTING_HUB_CONTENT_CAPABILITIES = [
        'dashboard_manage_posts',
        'dashboard_manage_bundles',
        'dashboard_manage_types',
        'dashboard_manage_categories',
        'dashboard_manage_tags',
    ];

    protected $fillable = [
        'name',
        'slug',
        'type',
        'is_active',
        'primary_color',
        'secondary_color',
        'logo_url',
        'white_logo_url',
        'favicon_url',
        'auth_bg_image_url',
        'from_email',
        'frontend_url',
        'api_url',
        'deploy_notes',
        'db_driver',
        'db_host',
        'db_port',
        'db_database',
        'db_username',
        'db_password',
        'checklist',
        'role_capabilities',
        'role_display_names',
        'compliance_status_display_names',
        'email_templates',
        'advisor_billing_renew_day',
        'subscriber_credits',
        'advisor_stripe_subscription_id',
        'stripe_key',
        'stripe_secret',
        'stripe_webhook_secret',
        'stripe_currency',
    ];

    protected $hidden = [
        'stripe_secret',
        'stripe_webhook_secret',
        'db_password',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'checklist' => 'array',
            'role_capabilities' => 'array',
            'role_display_names' => 'array',
            'compliance_status_display_names' => 'array',
            'email_templates' => 'array',
            'advisor_billing_renew_day' => 'integer',
            'subscriber_credits' => 'integer',
            'db_port' => 'integer',
            'stripe_secret' => \App\Casts\SafeEncrypted::class,
            'stripe_webhook_secret' => \App\Casts\SafeEncrypted::class,
            'db_password' => \App\Casts\SafeEncrypted::class,
        ];
    }

    public function advisorBillingRenewDay(): int
    {
        $day = (int) ($this->advisor_billing_renew_day ?: 1);

        return max(1, min(28, $day));
    }

    /**
     * White-labelled hub Excel subscribers: null subscriber_credits = unlimited.
     */
    public function givesUnlimitedSubscriberCredits(): bool
    {
        return $this->subscriber_credits === null;
    }

    /**
     * Credits granted to each active advisor on import and each monthly autorenew.
     * Null when unlimited.
     */
    public function subscriberCreditsPerPeriod(): ?int
    {
        if ($this->givesUnlimitedSubscriberCredits()) {
            return null;
        }

        return max(0, (int) $this->subscriber_credits);
    }

    /**
     * @return array{unlimited: bool, credits: ?int}
     */
    public function subscriberCreditsConfig(): array
    {
        return [
            'unlimited' => $this->givesUnlimitedSubscriberCredits(),
            'credits' => $this->subscriberCreditsPerPeriod(),
        ];
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

    public function isWhiteLabel(): bool
    {
        return $this->type === self::TYPE_WHITE_LABEL;
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

    public static function isSocialMediaComplianceCapability(string $key): bool
    {
        return in_array($key, self::SOCIAL_MEDIA_COMPLIANCE_CAPABILITY_KEYS, true)
            || ((self::CHECKLIST_DEFINITIONS[$key]['group'] ?? null) === self::GROUP_SOCIAL_MEDIA_COMPLIANCE);
    }

    public static function isGeneralComplianceCapability(string $key): bool
    {
        return in_array($key, self::GENERAL_COMPLIANCE_CAPABILITY_KEYS, true)
            || ((self::CHECKLIST_DEFINITIONS[$key]['group'] ?? null) === self::GROUP_GENERAL_COMPLIANCE);
    }

    public static function isWebsiteTemplateLibraryCapability(string $key): bool
    {
        return in_array($key, self::WEBSITE_TEMPLATE_LIBRARY_CAPABILITY_KEYS, true)
            || ((self::CHECKLIST_DEFINITIONS[$key]['group'] ?? null) === self::GROUP_WEBSITE_TEMPLATE_LIBRARY);
    }

    public static function isWebsiteComplianceCapability(string $key): bool
    {
        return in_array($key, self::WEBSITE_COMPLIANCE_CAPABILITY_KEYS, true)
            || ((self::CHECKLIST_DEFINITIONS[$key]['group'] ?? null) === self::GROUP_WEBSITE_COMPLIANCE);
    }

    public static function isSocialMediaTemplateLibraryCapability(string $key): bool
    {
        return in_array($key, self::SOCIAL_MEDIA_TEMPLATE_LIBRARY_CAPABILITY_KEYS, true);
    }

    public static function isSocialMediaTemplateLibraryFunctionality(string $key): bool
    {
        return in_array($key, self::SOCIAL_MEDIA_TEMPLATE_LIBRARY_FUNCTIONALITY_KEYS, true);
    }

    public static function isLockedModuleKey(string $key): bool
    {
        return in_array($key, self::LOCKED_MODULE_KEYS, true);
    }

    public static function isModuleKey(string $key): bool
    {
        return in_array($key, self::MODULE_KEYS, true)
            || ((self::CHECKLIST_DEFINITIONS[$key]['group'] ?? null) === self::GROUP_MODULES);
    }

    public function hasWhiteLabelHubModule(): bool
    {
        return $this->moduleEffectivelyEnabled('module_white_label_hub');
    }

    public function hasSharedHubModule(): bool
    {
        return $this->moduleEffectivelyEnabled('module_shared_hub');
    }

    public function hasBaseHubModule(): bool
    {
        return $this->moduleEffectivelyEnabled($this->baseModuleKey());
    }

    public function hasSocialMediaTemplateLibraryModule(): bool
    {
        return $this->moduleEffectivelyEnabled('module_social_media_template_library');
    }

    public function hasSocialMediaComplianceModule(): bool
    {
        return $this->moduleEffectivelyEnabled('module_social_media_compliance');
    }

    public function hasGeneralComplianceModule(): bool
    {
        return $this->moduleEffectivelyEnabled('module_general_compliance');
    }

    public function hasWebsiteTemplateLibraryModule(): bool
    {
        return $this->moduleEffectivelyEnabled('module_website_template_library');
    }

    public function hasWebsiteComplianceModule(): bool
    {
        return $this->moduleEffectivelyEnabled('module_website_compliance');
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

        $defaults['module_shared_hub'] = $type === self::TYPE_SHARED;
        $defaults['module_white_label_hub'] = $type === self::TYPE_WHITE_LABEL;

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

        return $this->applyModuleDependencies($resolved);
    }

    public function can(string $flag): bool
    {
        $checklist = $this->resolvedChecklist();

        if ($flag === 'module_shared_hub') {
            return $this->isShared();
        }
        if ($flag === 'module_white_label_hub') {
            return $this->isWhiteLabel();
        }

        if (self::isModuleKey($flag)) {
            return $this->moduleEffectivelyEnabled($flag);
        }

        $enabled = (bool) ($checklist[$flag] ?? false);
        if (! $enabled) {
            return false;
        }

        // Posts-library related functionalities stay off while the library module is off.
        if (self::isSocialMediaTemplateLibraryFunctionality($flag)
            && ! $this->moduleEffectivelyEnabled('module_social_media_template_library')
        ) {
            return false;
        }

        return true;
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
     * @return list<array{key: string, label: string, description: string, group: string, group_label: string, enabled: bool, exclusive_with: ?string, requires_module: ?string, inactive: bool, locked: bool}>
     */
    public function checklistForAdmin(): array
    {
        $resolved = $this->resolvedChecklist();
        $smtlOn = (bool) ($resolved['module_social_media_template_library'] ?? false);
        $items = [];

        foreach (self::CHECKLIST_DEFINITIONS as $key => $meta) {
            $group = $meta['group'] ?? self::GROUP_BEHAVIOUR;
            if (! in_array($group, self::FUNCTIONALITY_GROUPS, true)) {
                continue;
            }

            $requiresModule = null;
            $inactive = false;
            $locked = self::isLockedModuleKey($key);

            if (self::isSocialMediaTemplateLibraryFunctionality($key)) {
                $requiresModule = 'module_social_media_template_library';
                $inactive = ! $smtlOn;
            }

            if ($key === 'module_white_label_hub' || $key === 'module_shared_hub') {
                $locked = true;
            }

            // Only show the base module that applies to this hub on the checklist screen.
            if ($group === self::GROUP_MODULES
                && self::isLockedModuleKey($key)
                && $key !== $this->baseModuleKey()
            ) {
                continue;
            }

            $items[] = [
                'key' => $key,
                'label' => $meta['label'],
                'description' => $meta['description'],
                'group' => $group,
                'group_label' => self::CHECKLIST_GROUPS[$group] ?? $group,
                'enabled' => $inactive ? false : (bool) ($resolved[$key] ?? false),
                'exclusive_with' => self::CHECKLIST_OPPOSITES[$key] ?? null,
                'requires_module' => $requiresModule,
                'inactive' => $inactive,
                'locked' => $locked,
            ];
        }

        return $items;
    }

    /**
     * Public URL for the hub logo (uploaded storage path or legacy external URL).
     */
    public function logoPublicUrl(): ?string
    {
        return $this->publicAssetUrl($this->logo_url);
    }

    /**
     * Public URL for the white-on-dark hub logo variant.
     */
    public function whiteLogoPublicUrl(): ?string
    {
        return $this->publicAssetUrl($this->white_logo_url);
    }

    /**
     * Public URL for the hub favicon (uploaded storage path or external URL).
     */
    public function faviconPublicUrl(): ?string
    {
        return $this->publicAssetUrl($this->favicon_url);
    }

    /**
     * Public URL for the login/register background image.
     */
    public function authBgImagePublicUrl(): ?string
    {
        return $this->publicAssetUrl($this->auth_bg_image_url);
    }

    /**
     * Resolve a stored asset path or absolute/external URL to a public URL.
     */
    private function publicAssetUrl(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        if (str_starts_with($value, 'http://')
            || str_starts_with($value, 'https://')
            || str_starts_with($value, '/')) {
            return $value;
        }

        return Storage::disk('public')->url($value);
    }

    /**
     * Public frontend base URL for this hub's deploy.
     * Prefers the hub registry value; falls back to app.frontend_url.
     */
    public function frontendBaseUrl(): string
    {
        if (filled($this->frontend_url)) {
            return rtrim((string) $this->frontend_url, '/');
        }

        return rtrim((string) config('app.frontend_url', 'http://localhost:5173'), '/');
    }

    /**
     * Whether remote DB credentials are complete enough for content push.
     */
    public function hasRemoteDatabaseConfigured(): bool
    {
        return filled($this->db_host)
            && filled($this->db_database)
            && filled($this->db_username)
            && filled($this->db_password);
    }

    /**
     * Safe remote DB config for Power Admin UI (password masked).
     *
     * @return array<string, mixed>
     */
    public function remoteDatabaseForAdmin(): array
    {
        return [
            'driver' => $this->db_driver ?: 'mysql',
            'host' => $this->db_host,
            'port' => $this->db_port,
            'database' => $this->db_database,
            'username' => $this->db_username,
            'password_set' => filled($this->db_password),
        ];
    }

    /**
     * Deploy wiring stored on the hub registry (Power Admin).
     *
     * @return array{
     *   frontend_url: ?string,
     *   api_url: ?string,
     *   deploy_notes: ?string,
     *   database: array<string, mixed>,
     *   hub_slug_env: string,
     *   ready: bool,
     *   status: string,
     *   status_label: string,
     *   checklist: list<array{key: string, label: string, done: bool, required: bool}>,
     *   env_snippet: string
     * }
     */
    public function deployWiringForAdmin(): array
    {
        $hasFrontend = filled($this->frontend_url);
        $hasApi = filled($this->api_url);
        $hasNotes = filled($this->deploy_notes);
        $hasDb = $this->hasRemoteDatabaseConfigured();
        $isActive = (bool) $this->is_active;
        $database = $this->remoteDatabaseForAdmin();
        $needsRemoteDb = ! $this->isShared();

        $checklist = [
            [
                'key' => 'active',
                'label' => 'Hub is active in the registry',
                'done' => $isActive,
                'required' => true,
            ],
            [
                'key' => 'frontend_url',
                'label' => 'Frontend URL recorded',
                'done' => $hasFrontend,
                'required' => true,
            ],
            [
                'key' => 'remote_db',
                'label' => $needsRemoteDb
                    ? 'White-labelled database credentials recorded (own DB)'
                    : 'Shared hub uses its own .env database (not stored here)',
                'done' => $needsRemoteDb ? $hasDb : true,
                'required' => $needsRemoteDb,
            ],
            [
                'key' => 'api_url',
                'label' => 'API URL recorded (optional)',
                'done' => $hasApi,
                'required' => false,
            ],
            [
                'key' => 'hub_slug',
                'label' => 'White-labelled backend uses HUB_SLUG='.$this->slug,
                'done' => true,
                'required' => true,
            ],
            [
                'key' => 'own_db_env',
                'label' => 'White-labelled .env points at its OWN database (not shared)',
                'done' => true,
                'required' => $needsRemoteDb,
            ],
            [
                'key' => 'deploy_notes',
                'label' => 'Deploy notes added (optional)',
                'done' => $hasNotes,
                'required' => false,
            ],
        ];

        $ready = $isActive && $hasFrontend && (! $needsRemoteDb || $hasDb);
        $status = $ready ? 'ready' : 'needs_wiring';
        $statusLabel = $ready ? 'Deploy wiring ready' : 'Needs deploy wiring';

        $apiLine = $hasApi
            ? 'APP_URL='.rtrim((string) $this->api_url, '/')
            : 'APP_URL=https://api.example.com';
        $frontendLine = $hasFrontend
            ? 'FRONTEND_URL='.rtrim((string) $this->frontend_url, '/')
            : 'FRONTEND_URL=https://example.com';

        $dbHost = $database['host'] ?: '127.0.0.1';
        $dbPort = $database['port'] ?: 3306;
        $dbName = $database['database'] ?: 'hub_white_label';
        $dbUser = $database['username'] ?: 'hub_user';

        $envSnippet = implode("\n", [
            '# White-labelled deploy — same codebase, OWN database (not the shared hub DB)',
            'HUB_SLUG='.$this->slug,
            $frontendLine,
            $apiLine,
            'DB_CONNECTION='.($database['driver'] ?: 'mysql'),
            'DB_HOST='.$dbHost,
            'DB_PORT='.$dbPort,
            'DB_DATABASE='.$dbName,
            'DB_USERNAME='.$dbUser,
            'DB_PASSWORD=********',
        ]);

        return [
            'frontend_url' => $this->frontend_url,
            'api_url' => $this->api_url,
            'deploy_notes' => $this->deploy_notes,
            'database' => $database,
            'hub_slug_env' => $this->slug,
            'ready' => $ready,
            'status' => $status,
            'status_label' => $statusLabel,
            'checklist' => $checklist,
            'env_snippet' => $envSnippet,
        ];
    }

    /**
     * Absolute logo URL suitable for emails and white-labelled sync
     * (files live on the control-plane deploy).
     */
    public function logoAbsoluteUrl(): ?string
    {
        return $this->absoluteAssetUrl($this->logoPublicUrl());
    }

    /**
     * Absolute white logo URL for dark UI surfaces and white-labelled sync.
     */
    public function whiteLogoAbsoluteUrl(): ?string
    {
        return $this->absoluteAssetUrl($this->whiteLogoPublicUrl());
    }

    /**
     * Absolute favicon URL for emails and white-labelled sync.
     */
    public function faviconAbsoluteUrl(): ?string
    {
        return $this->absoluteAssetUrl($this->faviconPublicUrl());
    }

    /**
     * Absolute auth background image URL for white-labelled sync.
     */
    public function authBgImageAbsoluteUrl(): ?string
    {
        return $this->absoluteAssetUrl($this->authBgImagePublicUrl());
    }

    /**
     * Ensure a public asset URL is host-absolute (http/https).
     */
    private function absoluteAssetUrl(?string $url): ?string
    {
        if (! $url) {
            return null;
        }

        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        return rtrim((string) config('app.url'), '/').'/'.ltrim($url, '/');
    }

    /**
     * From address for transactional mail; falls back to mail config when unset.
     */
    public function mailFromAddress(): string
    {
        if (filled($this->from_email)) {
            return (string) $this->from_email;
        }

        return (string) config('mail.from.address', 'hello@example.com');
    }

    /**
     * Tenant branding used by the frontend for this hub.
     *
     * @return array{
     *   application_name: string,
     *   logo_url: ?string,
     *   white_logo_url: ?string,
     *   favicon_url: ?string,
     *   auth_bg_image_url: ?string,
     *   from_email: ?string,
     *   primary_color: ?string,
     *   secondary_color: ?string,
     *   color_scheme: array{primary: ?string, secondary: ?string}
     * }
     */
    public function brandingPayload(): array
    {
        return [
            'application_name' => $this->name,
            // Absolute URLs so login/signup (and emails) always resolve the
            // control-plane or local media host correctly.
            'logo_url' => $this->logoAbsoluteUrl(),
            'white_logo_url' => $this->whiteLogoAbsoluteUrl(),
            'favicon_url' => $this->faviconAbsoluteUrl(),
            'auth_bg_image_url' => $this->authBgImageAbsoluteUrl(),
            'from_email' => $this->from_email,
            'primary_color' => $this->primary_color,
            'secondary_color' => $this->secondary_color,
            'color_scheme' => [
                'primary' => $this->primary_color,
                'secondary' => $this->secondary_color,
            ],
        ];
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
            // Public site root for WC previews / placeholders (hub deploy wiring).
            'frontend_url' => $this->frontendBaseUrl(),
            'branding' => $this->brandingPayload(),
            'checklist' => $checklist,
            // Frontend should use these labels wherever roles are shown (falls back to defaults).
            'role_labels' => $this->resolvedRoleLabels(),
            // Compliance status wording (Pending / Approved / …) for SMC, GC, and WC.
            'compliance_status_labels' => $this->resolvedComplianceStatusLabels(),
            // Frontend should hide Sign up when registration_enabled is false.
            'auth' => [
                'registration_enabled' => $registrationEnabled,
                'invite_only' => (bool) ($checklist['private_invite_only'] ?? false),
            ],
        ];
    }

    /**
     * Display labels for each role key on this hub (custom overrides + defaults).
     *
     * @return array<string, string>
     */
    public function resolvedRoleLabels(): array
    {
        $overrides = is_array($this->role_display_names) ? $this->role_display_names : [];
        $labels = [];
        foreach (User::ROLE_LABELS as $key => $default) {
            $custom = isset($overrides[$key]) ? trim((string) $overrides[$key]) : '';
            $labels[$key] = $custom !== '' ? $custom : $default;
        }

        return $labels;
    }

    public function roleLabel(string $role): string
    {
        $labels = $this->resolvedRoleLabels();

        return $labels[$role] ?? (User::ROLE_LABELS[$role] ?? $role);
    }

    /**
     * Normalize any stored status string to a snake_case map key.
     */
    public static function normalizeComplianceStatusKey(string $status): string
    {
        $raw = trim($status);
        if ($raw === '') {
            return 'pending';
        }

        $lower = strtolower($raw);
        $snake = preg_replace('/[\s\-]+/', '_', $lower) ?? $lower;
        $snake = preg_replace('/_+/', '_', $snake) ?? $snake;

        return $snake;
    }

    /**
     * Display labels for compliance statuses on this hub.
     *
     * @return array<string, string>
     */
    public function resolvedComplianceStatusLabels(): array
    {
        $overrides = is_array($this->compliance_status_display_names)
            ? $this->compliance_status_display_names
            : [];
        $labels = [];
        foreach (self::COMPLIANCE_STATUS_LABELS as $key => $default) {
            $custom = isset($overrides[$key]) ? trim((string) $overrides[$key]) : '';
            $labels[$key] = $custom !== '' ? $custom : $default;
        }

        return $labels;
    }

    public function complianceStatusLabel(string $status): string
    {
        $key = self::normalizeComplianceStatusKey($status);
        $labels = $this->resolvedComplianceStatusLabels();

        return $labels[$key] ?? (self::COMPLIANCE_STATUS_LABELS[$key] ?? $status);
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
            'branding' => $this->brandingPayload(),
            'deploy' => $this->deployWiringForAdmin(),
            'stripe' => $this->stripeConfigForAdmin(),
            'subscriber_credits' => $this->subscriberCreditsConfig(),
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
