<?php

namespace App\Support;

/**
 * Editable dashboard sidebar separators + menu labels + layout.
 * Stored on hubs.dashboard_nav (JSON); missing keys fall back to these defaults.
 */
class DashboardNavDefaults
{
    /**
     * Section ids that appear as sidebar separators (reorderable).
     * hub_central / hub_shared / hub_white_label are label variants of "hub" only.
     *
     * @return list<string>
     */
    public static function defaultSectionOrder(): array
    {
        return [
            'account',
            'content',
            'taxonomy',
            'hub',
            'modules',
            'advisors',
            'smc',
            'gc',
            'st',
            'wtl',
            'wc',
            'platform',
        ];
    }

    /**
     * Default path → section id mapping (mirrors frontend DASHBOARD_LINKS).
     *
     * @return array<string, string>
     */
    public static function defaultItemGroups(): array
    {
        return [
            '/my-dashboard/profile' => 'account',
            '/my-dashboard/subscription' => 'account',
            '/my-dashboard/credits' => 'account',
            '/my-dashboard/my-invoices' => 'account',
            '/my-dashboard/purchases' => 'account',
            '/my-dashboard/posts' => 'content',
            '/my-dashboard/central-library' => 'content',
            '/my-dashboard/bundles' => 'content',
            '/my-dashboard/types' => 'content',
            '/my-dashboard/categories' => 'content',
            '/my-dashboard/tags' => 'content',
            '/my-dashboard/taxonomy-add-requests' => 'taxonomy',
            '/my-dashboard/taxonomy-add-requests/new' => 'taxonomy',
            '/my-dashboard/taxonomy-add-requests/queue' => 'taxonomy',
            '/my-dashboard/firms' => 'hub',
            '/my-dashboard/firm-documents' => 'hub',
            '/my-dashboard/plans' => 'hub',
            '/my-dashboard/settings' => 'hub',
            '/my-dashboard/terms' => 'hub',
            '/my-dashboard/role-display-names' => 'hub',
            '/my-dashboard/compliance-status-display-names' => 'hub',
            '/my-dashboard/email-templates' => 'hub',
            '/my-dashboard/bank-transfers' => 'hub',
            '/my-dashboard/activity-logs' => 'hub',
            '/my-dashboard/active-sessions' => 'hub',
            '/my-dashboard/hub-users' => 'hub',
            '/my-dashboard/compliance-audit-trail' => 'hub',
            '/my-dashboard/one-time-invoices' => 'hub',
            '/my-dashboard/modules' => 'modules',
            '/my-dashboard/module-pricing' => 'modules',
            '/my-dashboard/module-invoices' => 'modules',
            '/my-dashboard/advisors' => 'advisors',
            '/my-dashboard/payment-card' => 'advisors',
            '/my-dashboard/advisor-renewal' => 'advisors',
            '/my-dashboard/subscriber-credits' => 'advisors',
            '/my-dashboard/advisor-invoices' => 'advisors',
            '/my-dashboard/social-media-compliance' => 'smc',
            '/my-dashboard/social-media-compliance/new' => 'smc',
            '/my-dashboard/social-media-compliance/queue' => 'smc',
            '/my-dashboard/social-media-compliance/reports' => 'smc',
            '/my-dashboard/general-compliance' => 'gc',
            '/my-dashboard/general-compliance/new' => 'gc',
            '/my-dashboard/general-compliance/content-types' => 'gc',
            '/my-dashboard/general-compliance/queue' => 'gc',
            '/my-dashboard/general-compliance/reports' => 'gc',
            '/my-dashboard/support-tickets' => 'st',
            '/my-dashboard/support-tickets/new' => 'st',
            '/my-dashboard/support-tickets/queue' => 'st',
            '/my-dashboard/website-compliance/request-site' => 'wtl',
            '/my-dashboard/website-compliance/my-sites' => 'wtl',
            '/my-dashboard/website-compliance/go-live' => 'wtl',
            '/my-dashboard/website-compliance/deployments' => 'wtl',
            '/my-dashboard/website-compliance/content-editor' => 'wc',
            '/my-dashboard/website-compliance/my-requests' => 'wc',
            '/my-dashboard/website-compliance/publish-live' => 'wc',
            '/my-dashboard/website-compliance/assign' => 'wc',
            '/my-dashboard/website-compliance/review' => 'wc',
            '/my-dashboard/website-compliance/history' => 'wc',
            '/my-dashboard/website-compliance/reports' => 'wc',
            '/my-dashboard/payment-methods' => 'platform',
            '/my-dashboard/users' => 'platform',
            '/my-dashboard/hubs' => 'platform',
            '/my-dashboard/checklist' => 'platform',
            '/my-dashboard/capabilities' => 'platform',
            '/my-dashboard/hub-backups' => 'platform',
        ];
    }

    /**
     * Default menu item order (paths).
     *
     * @return list<string>
     */
    public static function defaultItemOrder(): array
    {
        return array_keys(self::defaultItemGroups());
    }

    /**
     * @return array{
     *   sections: array<string, string>,
     *   items: array<string, string>,
     *   section_order: list<string>,
     *   item_groups: array<string, string>,
     *   item_order: list<string>
     * }
     */
    public static function all(): array
    {
        return [
            'sections' => [
                'dashboard' => 'Dashboard',
                'account' => 'Account',
                'content' => 'SM Template',
                'taxonomy' => 'Taxonomy requests',
                'hub' => 'Hub',
                'hub_central' => 'Central Hub',
                'hub_shared' => 'Shared hub',
                'hub_white_label' => 'White-labelled hub',
                'modules' => 'Modules',
                'advisors' => 'Advisors & billing',
                'smc' => 'Social Media Compliance',
                'gc' => 'General Compliance',
                'st' => 'Support Tickets',
                'wtl' => 'Website Template Library',
                'wc' => 'Website Content Pre Approval',
                'platform' => 'Platform',
            ],
            'items' => [
                '/my-dashboard' => 'Overview',
                '/my-dashboard/profile' => 'Update profile',
                '/my-dashboard/subscription' => 'Subscription',
                '/my-dashboard/credits' => 'Credits',
                '/my-dashboard/my-invoices' => 'My invoices',
                '/my-dashboard/purchases' => 'Purchases',
                '/my-dashboard/posts' => 'Posts / reels',
                '/my-dashboard/central-library' => 'Central library',
                '/my-dashboard/bundles' => 'Bundles',
                '/my-dashboard/types' => 'Types',
                '/my-dashboard/categories' => 'Categories',
                '/my-dashboard/tags' => 'Tags',
                '/my-dashboard/taxonomy-add-requests' => 'Taxonomy requests',
                '/my-dashboard/taxonomy-add-requests/new' => 'Request taxonomy',
                '/my-dashboard/taxonomy-add-requests/queue' => 'Taxonomy queue',
                '/my-dashboard/firms' => 'Firms',
                '/my-dashboard/firm-documents' => 'Firm documents',
                '/my-dashboard/plans' => 'Subscriptions',
                '/my-dashboard/settings' => 'Settings',
                '/my-dashboard/terms' => 'Terms & Conditions',
                '/my-dashboard/role-display-names' => 'Manage roles',
                '/my-dashboard/compliance-status-display-names' => 'Workflows status title',
                '/my-dashboard/email-templates' => 'Email templates',
                '/my-dashboard/bank-transfers' => 'Bank transfers',
                '/my-dashboard/activity-logs' => 'Activity logs',
                '/my-dashboard/active-sessions' => 'Active sessions',
                '/my-dashboard/hub-users' => 'Users',
                '/my-dashboard/compliance-audit-trail' => 'Audit trail',
                '/my-dashboard/one-time-invoices' => 'One-time invoices',
                '/my-dashboard/modules' => 'Modules',
                '/my-dashboard/module-pricing' => 'Module prices',
                '/my-dashboard/module-invoices' => 'Module invoices',
                '/my-dashboard/advisors' => 'Import Users',
                '/my-dashboard/payment-card' => 'Payment card',
                '/my-dashboard/advisor-renewal' => 'Billing renew day',
                '/my-dashboard/subscriber-credits' => 'Subscriber credits',
                '/my-dashboard/advisor-invoices' => 'Advisor invoices',
                '/my-dashboard/social-media-compliance' => 'My requests',
                '/my-dashboard/social-media-compliance/new' => 'New request',
                '/my-dashboard/social-media-compliance/queue' => 'All requests',
                '/my-dashboard/social-media-compliance/reports' => 'Reports',
                '/my-dashboard/general-compliance' => 'My requests',
                '/my-dashboard/general-compliance/new' => 'New request',
                '/my-dashboard/general-compliance/content-types' => 'Content types',
                '/my-dashboard/general-compliance/queue' => 'All requests',
                '/my-dashboard/general-compliance/reports' => 'Reports',
                '/my-dashboard/support-tickets' => 'My tickets',
                '/my-dashboard/support-tickets/new' => 'New ticket',
                '/my-dashboard/support-tickets/queue' => 'All tickets',
                '/my-dashboard/website-compliance/request-site' => 'Request a site',
                '/my-dashboard/website-compliance/my-sites' => 'My sites',
                '/my-dashboard/website-compliance/go-live' => 'Request go-live',
                '/my-dashboard/website-compliance/deployments' => 'Site operations',
                '/my-dashboard/website-compliance/content-editor' => 'Content editor',
                '/my-dashboard/website-compliance/my-requests' => 'My change requests',
                '/my-dashboard/website-compliance/publish-live' => 'Publish content',
                '/my-dashboard/website-compliance/assign' => 'Assign requests',
                '/my-dashboard/website-compliance/review' => 'Review queue',
                '/my-dashboard/website-compliance/history' => 'Request history',
                '/my-dashboard/website-compliance/reports' => 'Reports',
                '/my-dashboard/payment-methods' => 'Payment methods',
                '/my-dashboard/users' => 'Users & roles',
                '/my-dashboard/hubs' => 'Hubs',
                '/my-dashboard/checklist' => 'Functionalities',
                '/my-dashboard/capabilities' => 'Capabilities',
                '/my-dashboard/hub-backups' => 'Hub backups',
            ],
            'section_order' => self::defaultSectionOrder(),
            'item_groups' => self::defaultItemGroups(),
            'item_order' => self::defaultItemOrder(),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $stored
     * @return array{
     *   sections: array<string, string>,
     *   items: array<string, string>,
     *   section_order: list<string>,
     *   item_groups: array<string, string>,
     *   item_order: list<string>
     * }
     */
    public static function resolve(?array $stored): array
    {
        $stored = self::remapLegacyPaths($stored);
        $defaults = self::all();
        $resolved = [
            'sections' => [],
            'items' => [],
            'section_order' => [],
            'item_groups' => [],
            'item_order' => [],
        ];

        $storedSections = is_array($stored['sections'] ?? null) ? $stored['sections'] : [];
        $storedOrder = is_array($stored['section_order'] ?? null) ? $stored['section_order'] : null;
        $customIds = self::extractCustomSectionIds($storedSections, $storedOrder);

        foreach (['sections', 'items'] as $bucket) {
            $sectionStored = is_array($stored[$bucket] ?? null) ? $stored[$bucket] : [];
            foreach ($defaults[$bucket] as $key => $default) {
                $value = $sectionStored[$key] ?? null;
                if ($value === null || $value === '') {
                    $resolved[$bucket][$key] = $default;
                } else {
                    $resolved[$bucket][$key] = mb_substr(trim((string) $value), 0, 120);
                }
            }
        }

        foreach ($customIds as $customId) {
            $label = trim((string) ($storedSections[$customId] ?? ''));
            $resolved['sections'][$customId] = $label !== ''
                ? mb_substr($label, 0, 120)
                : 'Custom section';
        }

        $resolved['section_order'] = self::normalizeSectionOrder($storedOrder, $customIds);
        $resolved['item_groups'] = self::normalizeItemGroups(
            is_array($stored['item_groups'] ?? null) ? $stored['item_groups'] : null,
            $customIds
        );
        $resolved['item_order'] = self::normalizeItemOrder(
            is_array($stored['item_order'] ?? null) ? $stored['item_order'] : null,
            $resolved['item_groups']
        );

        return $resolved;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{
     *   sections: array<string, string>,
     *   items: array<string, string>,
     *   section_order: list<string>,
     *   item_groups: array<string, string>,
     *   item_order: list<string>
     * }
     */
    public static function sanitize(array $input): array
    {
        $input = self::remapLegacyPaths($input) ?? [];
        $defaults = self::all();
        $clean = [
            'sections' => [],
            'items' => [],
            'section_order' => [],
            'item_groups' => [],
            'item_order' => [],
        ];

        $inputSections = is_array($input['sections'] ?? null) ? $input['sections'] : [];
        $inputOrder = is_array($input['section_order'] ?? null) ? $input['section_order'] : null;
        $customIds = self::extractCustomSectionIds($inputSections, $inputOrder);

        foreach (['sections', 'items'] as $bucket) {
            $bucketInput = is_array($input[$bucket] ?? null) ? $input[$bucket] : [];
            foreach (array_keys($defaults[$bucket]) as $key) {
                if (! array_key_exists($key, $bucketInput)) {
                    continue;
                }
                $clean[$bucket][$key] = mb_substr(trim((string) $bucketInput[$key]), 0, 120);
            }
        }

        foreach ($customIds as $customId) {
            $label = trim((string) ($inputSections[$customId] ?? ''));
            $clean['sections'][$customId] = $label !== ''
                ? mb_substr($label, 0, 120)
                : 'Custom section';
        }

        if (array_key_exists('section_order', $input) && is_array($input['section_order'])) {
            $clean['section_order'] = self::normalizeSectionOrder($input['section_order'], $customIds);
        }
        if (array_key_exists('item_groups', $input) && is_array($input['item_groups'])) {
            $clean['item_groups'] = self::normalizeItemGroups($input['item_groups'], $customIds);
        }
        if (array_key_exists('item_order', $input) && is_array($input['item_order'])) {
            $groups = $clean['item_groups'] !== []
                ? $clean['item_groups']
                : self::defaultItemGroups();
            $clean['item_order'] = self::normalizeItemOrder($input['item_order'], $groups);
        }

        return $clean;
    }

    /**
     * Built-in separator ids that always appear in section_order.
     *
     * @return list<string>
     */
    public static function builtInSectionIds(): array
    {
        return self::defaultSectionOrder();
    }

    /**
     * Label-only keys that are not independent separators.
     *
     * @return list<string>
     */
    public static function labelOnlySectionIds(): array
    {
        return ['dashboard', 'hub_central', 'hub_shared', 'hub_white_label'];
    }

    public static function isCustomSectionId(string $id): bool
    {
        return (bool) preg_match('/^custom_[a-z0-9_]{1,40}$/', $id);
    }

    public static function isAssignableSectionId(string $id, ?array $extraCustom = null): bool
    {
        if (in_array($id, self::builtInSectionIds(), true)) {
            return true;
        }
        if (self::isCustomSectionId($id)) {
            return true;
        }
        if (is_array($extraCustom) && in_array($id, $extraCustom, true) && self::isCustomSectionId($id)) {
            return true;
        }

        return false;
    }

    /**
     * Rename legacy nav paths so stored hub settings keep custom labels/order.
     *
     * @param  array<string, mixed>|null  $nav
     * @return array<string, mixed>|null
     */
    public static function remapLegacyPaths(?array $nav): ?array
    {
        if ($nav === null) {
            return null;
        }

        $map = [
            '/my-dashboard/invoices' => '/my-dashboard/my-invoices',
        ];

        foreach (['items', 'item_groups'] as $bucket) {
            if (! is_array($nav[$bucket] ?? null)) {
                continue;
            }
            $next = [];
            foreach ($nav[$bucket] as $path => $value) {
                $path = is_string($path) ? ($map[$path] ?? $path) : $path;
                $next[$path] = $value;
            }
            $nav[$bucket] = $next;
        }

        if (is_array($nav['item_order'] ?? null)) {
            $nav['item_order'] = array_values(array_map(
                fn ($path) => is_string($path) ? ($map[$path] ?? $path) : $path,
                $nav['item_order']
            ));
        }

        // Move taxonomy request menus out of SM Template into their own separator.
        $taxonomyPaths = [
            '/my-dashboard/taxonomy-add-requests',
            '/my-dashboard/taxonomy-add-requests/new',
            '/my-dashboard/taxonomy-add-requests/queue',
        ];
        if (is_array($nav['item_groups'] ?? null)) {
            foreach ($taxonomyPaths as $path) {
                if (($nav['item_groups'][$path] ?? null) === 'content') {
                    $nav['item_groups'][$path] = 'taxonomy';
                }
            }
        }
        if (is_array($nav['section_order'] ?? null)
            && ! in_array('taxonomy', $nav['section_order'], true)
        ) {
            $order = $nav['section_order'];
            $contentIdx = array_search('content', $order, true);
            if ($contentIdx === false) {
                $order[] = 'taxonomy';
            } else {
                array_splice($order, $contentIdx + 1, 0, ['taxonomy']);
            }
            $nav['section_order'] = $order;
        }

        return $nav;
    }

    /**
     * @param  array<string, mixed>|null  $sections
     * @param  list<mixed>|null  $sectionOrder
     * @return list<string>
     */
    public static function extractCustomSectionIds(?array $sections, ?array $sectionOrder): array
    {
        $ids = [];
        foreach (is_array($sectionOrder) ? $sectionOrder : [] as $id) {
            $id = is_string($id) ? trim($id) : '';
            if (self::isCustomSectionId($id) && ! in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }
        foreach (is_array($sections) ? array_keys($sections) : [] as $id) {
            $id = is_string($id) ? trim($id) : '';
            if (self::isCustomSectionId($id) && ! in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * @param  list<mixed>|null  $incoming
     * @param  list<string>  $customIds
     * @return list<string>
     */
    public static function normalizeSectionOrder(?array $incoming, array $customIds = []): array
    {
        $defaults = self::defaultSectionOrder();
        $allowed = array_fill_keys(array_merge($defaults, $customIds), true);
        if ($incoming === null || $incoming === []) {
            return array_values(array_unique([...$defaults, ...$customIds]));
        }

        $order = [];
        foreach ($incoming as $id) {
            $id = is_string($id) ? trim($id) : '';
            if ($id === '' || ! isset($allowed[$id]) || in_array($id, $order, true)) {
                continue;
            }
            $order[] = $id;
        }
        foreach ($defaults as $id) {
            if (! in_array($id, $order, true)) {
                $order[] = $id;
            }
        }
        foreach ($customIds as $id) {
            if (! in_array($id, $order, true)) {
                $order[] = $id;
            }
        }

        return $order;
    }

    /**
     * @param  array<string, mixed>|null  $incoming
     * @param  list<string>  $customIds
     * @return array<string, string>
     */
    public static function normalizeItemGroups(?array $incoming, array $customIds = []): array
    {
        $defaults = self::defaultItemGroups();
        $allowedSections = array_fill_keys(array_merge(self::defaultSectionOrder(), $customIds), true);
        $resolved = [];

        foreach ($defaults as $path => $defaultGroup) {
            $value = is_array($incoming) && array_key_exists($path, $incoming)
                ? trim((string) $incoming[$path])
                : '';
            if ($value !== '' && isset($allowedSections[$value])) {
                $resolved[$path] = $value;
            } else {
                $resolved[$path] = $defaultGroup;
            }
        }

        return $resolved;
    }

    /**
     * @param  list<mixed>|null  $incoming
     * @param  array<string, string>  $groups
     * @return list<string>
     */
    public static function normalizeItemOrder(?array $incoming, array $groups): array
    {
        $defaults = array_keys($groups);
        if ($incoming === null || $incoming === []) {
            return $defaults;
        }

        $allowed = array_fill_keys($defaults, true);
        $order = [];
        foreach ($incoming as $path) {
            $path = is_string($path) ? trim($path) : '';
            if ($path === '' || ! isset($allowed[$path]) || in_array($path, $order, true)) {
                continue;
            }
            $order[] = $path;
        }
        foreach ($defaults as $path) {
            if (! in_array($path, $order, true)) {
                $order[] = $path;
            }
        }

        return $order;
    }
}
