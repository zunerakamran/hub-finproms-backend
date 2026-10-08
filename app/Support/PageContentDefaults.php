<?php

namespace App\Support;

/**
 * Editable public-site copy for Home, Posts/Reels catalog, and post detail.
 * Stored on hubs.page_content (JSON); missing keys fall back to these defaults.
 */
class PageContentDefaults
{
    /**
     * @return array{
     *   home: array<string, string>,
     *   catalog: array<string, string>,
     *   post_detail: array<string, string>
     * }
     */
    public static function all(): array
    {
        return [
            'home' => [
                // Hero (section 1 — already built)
                'title' => '*Transform* your social media in minutes with ready-made *templates*',
                'lead' => 'Discover **fully editable** posts and reels designed to simplify your creative process — compliant content, ready to publish.',
                'cta_browse_posts' => 'Browse posts',
                'cta_browse_reels' => 'Browse reels',
                'cta_get_started' => 'Get started',
                'cta_log_in' => 'Log in',
                'guest_hint' => 'Browse the home showcase freely. Sign in to open posts, bundles, and plans.',
                'catalog_disabled' => 'Catalog browsing is not enabled on this hub.',
                'empty_posts' => 'Posts will appear here once published.',
                // Header nav labels
                'nav_categories' => 'Categories',
                'nav_templates' => 'Website templates',
                'nav_tickets' => 'My tickets',
                'nav_raise_ticket' => 'Create a new ticket',
                // Section 3 — Browse by Categories
                'categories_title' => 'Browse by Categories',
                'categories_lead' => 'Explore content collections available on this hub.',
                'categories_empty' => 'Categories will appear here once published on this hub.',
                'categories_view_all' => 'View all',
                // Section 4 — Website templates (up to 4)
                'templates_title' => 'Website templates',
                'templates_lead' => 'Showcase website templates available on this hub.',
                'templates_empty' => 'Website templates are not available on this hub yet.',
                'templates_cta' => 'View template',
                'templates_view_all' => 'Browse templates',
                // Section 5 — Tickets (3 tabs)
                'tickets_title' => 'Support tickets',
                'tickets_lead' => 'Check ticket status, browse your tickets, or raise a new one.',
                'tickets_tab_overview' => 'Tickets',
                'tickets_tab_mine' => 'My tickets',
                'tickets_tab_raise' => 'Raise a ticket',
                'tickets_overview_body' => 'Track open requests and get help from the support team.',
                'tickets_status_open' => 'Open',
                'tickets_status_progress' => 'In progress',
                'tickets_status_completed' => 'Completed',
                'tickets_browse' => 'Browse tickets',
                'tickets_raise_cta' => 'Create new ticket',
                'tickets_raise_body' => 'Need help? Open a ticket and our team will get back to you.',
                'tickets_empty' => 'You have no tickets yet.',
                'tickets_guest_hint' => 'Sign in to view your tickets and raise a new one.',
                'tickets_disabled' => 'Support tickets are not enabled on this hub.',
                // Section 6 — Documents
                'documents_title' => 'Discover our documents, guides & checklists',
                'documents_lead' => 'Browse firm documents curated for your hub — policies, planners, and ready-to-use resources.',
                'documents_body' => 'Open the document library to read more and download what you need.',
                'documents_cta' => 'Read more',
                'documents_image_1' => '',
                'documents_image_2' => '',
                'documents_image_3' => '',
                'documents_disabled' => 'Documents are not enabled on this hub.',
                // Section 7 — Need assistance
                'assistance_title' => 'Need assistance? Our specialized agents will help you!',
                'assistance_lead' => 'Get in touch with experts via live chat, WhatsApp, or email.',
                'assistance_chat_label' => 'Live chat',
                'assistance_chat_text' => 'Chat with a specialist in real time.',
                'assistance_chat_url' => '',
                'assistance_whatsapp_label' => 'WhatsApp',
                'assistance_whatsapp_text' => 'Message us on WhatsApp for quick help.',
                'assistance_whatsapp_url' => '',
                'assistance_email_label' => 'Email',
                'assistance_email_text' => 'Send us an email and we will respond soon.',
                'assistance_email' => '',
                // Footer
                'footer_tagline' => 'Compliant content, ready to publish',
                'footer_copyright' => '© {year} {brand}. All rights reserved.',
                'footer_powered_by' => 'Powered by Bypass',
            ],
            'catalog' => [
                'eyebrow' => 'Content library',
                'title_posts' => 'Ready-to-post social posts',
                'title_reels' => 'Ready-to-post reels',
                'lead_posts' => 'Browse promo posts, unlock with credits, and preview the assets you need.',
                'lead_reels' => 'Browse short-form reels, unlock with credits, and preview the assets you need.',
                'balance_label' => 'Your balance',
                'credits_available' => 'credits available',
                'top_up' => 'Top up',
                'guest_cta_register' => 'Create an account to preview content and buy with credits.',
                'guest_cta_invite' => 'This hub is invite-only. Sign in with your invited account to continue.',
                'sign_up' => 'Sign up free',
                'sign_in' => 'Sign in',
                'lock_title' => 'Content is locked',
                'lock_body' => 'Log in to preview posts and buy them with credits.',
                'login' => 'Login',
                'search_posts' => 'Search posts by title or description...',
                'search_reels' => 'Search reels by title or description...',
                'search_button' => 'Search',
                'category_label' => 'Category',
                'all_categories' => 'All categories',
                'tag_label' => 'Tag',
                'all_tags' => 'All tags',
                'clear_all' => 'Clear all',
                'reset_filters' => 'Reset filters',
                'empty_title_posts' => 'No posts found',
                'empty_title_reels' => 'No reels found',
                'empty_filtered' => 'Try another category, tag, or clear your search.',
                'empty_body_posts' => 'New posts will appear here once the client admin adds them.',
                'empty_body_reels' => 'New reels will appear here once the client admin adds them.',
                'catalog_unavailable_title' => 'Catalog unavailable',
                'catalog_unavailable_body' => 'Browsing posts is disabled for this hub by Power Admin.',
                'finding_posts' => 'Finding posts...',
                'finding_reels' => 'Finding reels...',
                'no_match_posts' => 'No posts match',
                'no_match_reels' => 'No reels match',
                'results_posts' => '{count} posts found',
                'results_post' => '{count} post found',
                'results_reels' => '{count} reels found',
                'results_reel' => '{count} reel found',
                'locked_excerpt' => 'Log in to preview this item and buy it with credits.',
                'login_to_unlock' => 'Login to unlock →',
                'view_post' => 'View post →',
                'play_reel' => 'Play reel →',
                'buy_post' => 'Buy post →',
                'buy_reel' => 'Buy & play →',
                'badge_owned' => 'Owned',
                'badge_locked' => 'Locked',
                'badge_available' => 'Available',
                'reel_label' => 'Reel',
                'no_description' => 'No description provided.',
            ],
            'post_detail' => [
                'back_posts' => '← Back to posts',
                'back_reels' => '← Back to reels',
                'login_required' => 'Login required',
                'locked_lead' => 'Sign in to preview this post and buy it with credits — no subscription required.',
                'step1_title' => 'Create an account',
                'step1_body' => 'Register or log in to browse the full catalog.',
                'step2_title' => 'Get credits',
                'step2_body' => 'Subscribe for a pack, or top up as you go.',
                'step3_title' => 'Buy this post',
                'step3_body' => 'Spend {credits} credits to unlock and preview the creative asset.',
                'login' => 'Login',
                'sign_up' => 'Sign up free',
                'last_updated' => 'Last updated {date}',
                'no_description' => 'No description provided.',
                'unlocked' => 'Unlocked',
                'download' => 'Preview',
                'no_attachment' => 'No attachment uploaded for this post.',
                'downloads_disabled' => 'Preview is disabled for this hub by Power Admin.',
                'edit_canva' => 'Edit with Canva',
                'buy_intro' => 'Buy this post for {credits} credits.',
                'no_subscription_note' => 'No subscription required.',
                'your_balance' => 'Your balance:',
                'unlimited' => 'Unlimited',
                'credits_suffix' => 'credits',
                'buy_with_credits' => 'Buy with credits',
                'purchasing' => 'Purchasing...',
                'get_more_credits' => 'Get more credits',
                'or_pay_directly' => 'Or pay directly with an enabled payment method:',
                'pay_stripe' => 'Pay with Stripe',
                'pay_bank' => 'Pay by bank transfer',
                'stripe_unavailable' => 'Stripe unavailable',
                'bank_unavailable' => 'Bank transfer unavailable',
                'redirecting_stripe' => 'Redirecting to Stripe...',
                'processing' => 'Processing...',
                'purchasing_disabled' => 'Purchasing content is disabled for this hub by Power Admin.',
                'loading' => 'Loading...',
            ],
        ];
    }

    /**
     * Flatten allowed keys as "section.key" => default value.
     *
     * @return array<string, string>
     */
    public static function flat(): array
    {
        $flat = [];
        foreach (self::all() as $section => $fields) {
            foreach ($fields as $key => $value) {
                $flat["{$section}.{$key}"] = $value;
            }
        }

        return $flat;
    }

    /**
     * Merge stored hub values over defaults; drop unknown keys; stringify values.
     *
     * @param  array<string, mixed>|null  $stored
     * @return array{
     *   home: array<string, string>,
     *   catalog: array<string, string>,
     *   post_detail: array<string, string>
     * }
     */
    public static function resolve(?array $stored): array
    {
        $defaults = self::all();
        $resolved = [];

        foreach ($defaults as $section => $fields) {
            $sectionStored = is_array($stored[$section] ?? null) ? $stored[$section] : [];
            $resolved[$section] = [];
            foreach ($fields as $key => $default) {
                $value = $sectionStored[$key] ?? null;
                if ($value === null || $value === '') {
                    $resolved[$section][$key] = $default;
                } else {
                    $resolved[$section][$key] = mb_substr(trim((string) $value), 0, 2000);
                }
            }
        }

        return $resolved;
    }

    /**
     * Sanitize an incoming settings payload to only known keys (empty string clears to default).
     *
     * @param  array<string, mixed>  $input
     * @return array{
     *   home: array<string, string>,
     *   catalog: array<string, string>,
     *   post_detail: array<string, string>
     * }
     */
    public static function sanitize(array $input): array
    {
        $defaults = self::all();
        $clean = [];

        foreach ($defaults as $section => $fields) {
            $sectionInput = is_array($input[$section] ?? null) ? $input[$section] : [];
            $clean[$section] = [];
            foreach (array_keys($fields) as $key) {
                if (! array_key_exists($key, $sectionInput)) {
                    continue;
                }
                $clean[$section][$key] = mb_substr(trim((string) $sectionInput[$key]), 0, 2000);
            }
        }

        return $clean;
    }
}
