<?php

namespace Database\Seeders;

use App\Models\SubscriptionPlan;
use Illuminate\Database\Seeder;

class SubscriptionPlanSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Retire legacy plan names so only the current tiers remain active.
        SubscriptionPlan::query()
            ->whereIn('name', ['Starter', 'Pro', 'Business'])
            ->update(['is_active' => false]);

        $plans = [
            [
                'name' => 'Basic',
                'description' => 'Essential credits for getting started with Hub Finproms.',
                'overview' => 'A simple monthly plan with enough credits to publish and download core FinProms content.',
                'features' => [
                    '90 credits per month',
                    'Access to shared FinProms library',
                    'Download approved posts and reels',
                    'Email support',
                    'See post reach metrics',
                ],
                'benefits' => [
                    'Affordable entry point for new advisors',
                    'Predictable monthly credit allowance',
                    'No long-term commitment beyond the billing period',
                ],
                'price' => 29.99,
                'credits' => 90,
                'duration_days' => 30,
                'is_active' => true,
                'show_reach' => true,
                'show_views' => false,
                'show_buys' => false,
            ],
            [
                'name' => 'Standard',
                'description' => 'More credits for regular content creators and advisors.',
                'overview' => 'Our most popular plan for advisors who publish FinProms content on a regular schedule.',
                'features' => [
                    '140 credits per month',
                    'Access to shared FinProms library',
                    'Download approved posts and reels',
                    'Priority email support',
                    'Standard compliance-ready templates',
                    'See post reach and view metrics',
                ],
                'benefits' => [
                    'Better value per credit than Basic',
                    'Enough volume for steady social activity',
                    'Ideal balance of price and output',
                ],
                'price' => 54.99,
                'credits' => 140,
                'duration_days' => 30,
                'is_active' => true,
                'show_reach' => true,
                'show_views' => true,
                'show_buys' => false,
            ],
            [
                'name' => 'Premium',
                'description' => 'Highest credit allowance for high-volume teams and advisors.',
                'overview' => 'Maximum monthly credits for hubs and advisors who need frequent content downloads and campaigns.',
                'features' => [
                    '190 credits per month',
                    'Access to shared FinProms library',
                    'Download approved posts and reels',
                    'Priority support',
                    'Premium compliance-ready templates',
                    'Best credit value of all plans',
                    'See reach, views, and buy metrics',
                ],
                'benefits' => [
                    'Highest monthly credit pool',
                    'Lowest effective cost per credit',
                    'Built for busy advisors and growing teams',
                ],
                'price' => 79.99,
                'credits' => 190,
                'duration_days' => 30,
                'is_active' => true,
                'show_reach' => true,
                'show_views' => true,
                'show_buys' => true,
            ],
        ];

        foreach ($plans as $plan) {
            SubscriptionPlan::updateOrCreate(
                ['name' => $plan['name']],
                $plan
            );
        }
    }
}
