<?php

namespace App\Support;

/**
 * Editable dashboard sidebar separators + menu labels.
 * Stored on hubs.dashboard_nav (JSON); missing keys fall back to these defaults.
 */
class DashboardNavDefaults
{
    /**
     * @return array{sections: array<string, string>, items: array<string, string>}
     */
    public static function all(): array
    {
        return [
            'sections' => [
                'dashboard' => 'Dashboard',
                'account' => 'Account',
                'content' => 'SM Template',
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
                '/my-dashboard/invoices' => 'Invoices',
                '/my-dashboard/purchases' => 'Purchases',
                '/my-dashboard/posts' => 'Posts / reels',
                '/my-dashboard/central-library' => 'Central library',
                '/my-dashboard/bundles' => 'Bundles',
                '/my-dashboard/types' => 'Types',
                '/my-dashboard/categories' => 'Categories',
                '/my-dashboard/tags' => 'Tags',
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
            ],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $stored
     * @return array{sections: array<string, string>, items: array<string, string>}
     */
    public static function resolve(?array $stored): array
    {
        $defaults = self::all();
        $resolved = ['sections' => [], 'items' => []];

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

        return $resolved;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{sections: array<string, string>, items: array<string, string>}
     */
    public static function sanitize(array $input): array
    {
        $defaults = self::all();
        $clean = ['sections' => [], 'items' => []];

        foreach (['sections', 'items'] as $bucket) {
            $bucketInput = is_array($input[$bucket] ?? null) ? $input[$bucket] : [];
            foreach (array_keys($defaults[$bucket]) as $key) {
                if (! array_key_exists($key, $bucketInput)) {
                    continue;
                }
                $clean[$bucket][$key] = mb_substr(trim((string) $bucketInput[$key]), 0, 120);
            }
        }

        return $clean;
    }
}
