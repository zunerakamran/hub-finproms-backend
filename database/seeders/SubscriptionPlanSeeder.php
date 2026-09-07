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
        $plans = [
            [
                'name' => 'Starter',
                'description' => 'Perfect to try Hub Finproms social posts.',
                'price' => 9.99,
                'credits' => 50,
                'duration_days' => 30,
                'is_active' => true,
            ],
            [
                'name' => 'Pro',
                'description' => 'Best value for regular creators and marketers.',
                'price' => 29.99,
                'credits' => 200,
                'duration_days' => 30,
                'is_active' => true,
            ],
            [
                'name' => 'Business',
                'description' => 'High-volume credit pack for teams.',
                'price' => 79.99,
                'credits' => 600,
                'duration_days' => 30,
                'is_active' => true,
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
