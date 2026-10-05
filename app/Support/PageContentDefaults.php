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
                'title' => '*Transform* your social media in minutes with ready-made *templates*',
                'lead' => 'Discover **fully editable** posts and reels designed to simplify your creative process — compliant content, ready to publish.',
                'cta_browse_posts' => 'Browse posts',
                'cta_browse_reels' => 'Browse reels',
                'cta_get_started' => 'Get started',
                'cta_log_in' => 'Log in',
                'guest_hint' => 'Browse the home showcase freely. Sign in to open posts, bundles, and plans.',
                'catalog_disabled' => 'Catalog browsing is not enabled on this hub.',
                'empty_posts' => 'Posts will appear here once published.',
            ],
            'catalog' => [
                'eyebrow' => 'Content library',
                'title_posts' => 'Ready-to-post social posts',
                'title_reels' => 'Ready-to-post reels',
                'lead_posts' => 'Browse promo posts, unlock with credits, and download the assets you need. 1 credit = £1.',
                'lead_reels' => 'Browse short-form reels, unlock with credits, and download the assets you need. 1 credit = £1.',
                'balance_label' => 'Your balance',
                'credits_available' => 'credits available',
                'top_up' => 'Top up',
                'guest_cta_register' => 'Create an account to preview content and buy with credits.',
                'guest_cta_invite' => 'This hub is invite-only. Sign in with your invited account to continue.',
                'sign_up' => 'Sign up free',
                'sign_in' => 'Sign in',
                'lock_title' => 'Content is locked',
                'lock_body' => 'Log in to preview posts and buy them with credits (1 credit = £1).',
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
                'locked_lead' => 'Sign in to preview this post and buy it with credits — no subscription required. 1 credit = £1.',
                'step1_title' => 'Create an account',
                'step1_body' => 'Register or log in to browse the full catalog.',
                'step2_title' => 'Get credits',
                'step2_body' => 'Subscribe for a pack, or top up as you go (1 credit = £1).',
                'step3_title' => 'Buy this post',
                'step3_body' => 'Spend {credits} credits to download the creative asset.',
                'login' => 'Login',
                'sign_up' => 'Sign up free',
                'last_updated' => 'Last updated {date}',
                'no_description' => 'No description provided.',
                'unlocked' => 'Unlocked',
                'download' => 'Download',
                'no_attachment' => 'No attachment uploaded for this post.',
                'downloads_disabled' => 'Downloads are disabled for this hub by Power Admin.',
                'edit_canva' => 'Edit with Canva',
                'buy_intro' => 'Buy this post for {credits} credits (£{credits}).',
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
