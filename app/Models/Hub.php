<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Hub extends Model
{
    public const TYPE_SHARED = 'shared';

    public const TYPE_WHITE_LABEL = 'white_label';

    public const GROUP_BEHAVIOUR = 'behaviour';

    public const GROUP_MEMBER = 'member';

    public const GROUP_GENERAL = 'general';

    public const GROUP_DASHBOARD = 'dashboard';

    public const GROUP_ADMIN_EMAILS = 'admin_emails';

    /**
     * @var array<string, string>
     */
    public const CHECKLIST_GROUPS = [
        self::GROUP_BEHAVIOUR => 'Functionalities',
        self::GROUP_MEMBER => 'User capabilities',
        self::GROUP_GENERAL => 'General options (dashboard)',
        self::GROUP_DASHBOARD => 'Hub-admin dashboard',
        self::GROUP_ADMIN_EMAILS => 'Admin emails',
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
        self::GROUP_GENERAL,
        self::GROUP_DASHBOARD,
        self::GROUP_ADMIN_EMAILS,
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
        'dashboard_manage_subscriber_credits',
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
            'description' => 'Private-hub subscribers get unlimited credits (or set a fixed allotment under Subscriber credits).',
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

        // --- Hub-admin dashboard capabilities ---
        'dashboard_manage_posts' => [
            'label' => 'Manage posts / reels',
            'description' => 'Hub admin can create and edit posts/reels.',
            'group' => self::GROUP_DASHBOARD,
            'default_shared' => true,
            'default_white_label' => true,
        ],
        'dashboard_manage_bundles' => [
            'label' => 'Manage post bundles',
            'description' => 'Create bundles of posts/reels (existing or new), with description and total credits.',
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
            'description' => 'Hub admin can manage hub settings: NEW banner, logo, color scheme, and application name.',
            'group' => self::GROUP_DASHBOARD,
            'default_shared' => true,
            'default_white_label' => true,
        ],
        'receive_admin_emails' => [
            'label' => 'Receive admin emails',
            'description' => 'Receive all admin notification emails for this hub (registrations, purchases, payments, advisor events, etc.).',
            'group' => self::GROUP_ADMIN_EMAILS,
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
        'dashboard_manage_subscriber_credits' => [
            'label' => 'Set subscriber credits (private hub)',
            'description' => 'Set unlimited or a fixed credit allotment for Excel-imported private-hub subscribers (applied on import and autorenew). Private-hub only — inactive while the hub is public.',
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
            'label' => 'Publish content to white-labelled hubs',
            'description' => 'Unlocks a Hub dropdown on posts / types / categories / tags / bundles (like Capabilities). Creating content for a white-label hub writes only to that hub’s own database — not the shared catalog. Requires the matching Manage capability on that screen.',
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
            'advisor_billing_renew_day' => 'integer',
            'subscriber_credits' => 'integer',
            'db_port' => 'integer',
            'stripe_secret' => 'encrypted',
            'stripe_webhook_secret' => 'encrypted',
            'db_password' => 'encrypted',
        ];
    }

    public function advisorBillingRenewDay(): int
    {
        $day = (int) ($this->advisor_billing_renew_day ?: 1);

        return max(1, min(28, $day));
    }

    /**
     * Private-hub Excel subscribers: null subscriber_credits = unlimited.
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
     * Public URL for the hub logo (uploaded storage path or legacy external URL).
     */
    public function logoPublicUrl(): ?string
    {
        if (! $this->logo_url) {
            return null;
        }

        if (str_starts_with($this->logo_url, 'http://')
            || str_starts_with($this->logo_url, 'https://')
            || str_starts_with($this->logo_url, '/')) {
            return $this->logo_url;
        }

        return Storage::disk('public')->url($this->logo_url);
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
                    ? 'White-label database credentials recorded (own DB)'
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
                'label' => 'White-label backend uses HUB_SLUG='.$this->slug,
                'done' => true,
                'required' => true,
            ],
            [
                'key' => 'own_db_env',
                'label' => 'White-label .env points at its OWN database (not shared)',
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
            '# White-label deploy — same codebase, OWN database (not the shared hub DB)',
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
     * Absolute logo URL suitable for emails (requires a publicly reachable host).
     */
    public function logoAbsoluteUrl(): ?string
    {
        $url = $this->logoPublicUrl();
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
            'logo_url' => $this->logoPublicUrl(),
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
            'branding' => $this->brandingPayload(),
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
