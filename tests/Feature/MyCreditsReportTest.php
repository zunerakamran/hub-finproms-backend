<?php

namespace Tests\Feature;

use App\Models\Bundle;
use App\Models\BundlePurchase;
use App\Models\Hub;
use App\Models\Post;
use App\Models\PostPurchase;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\UserSubscription;
use App\Services\HubService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MyCreditsReportTest extends TestCase
{
    use RefreshDatabase;

    private function createSharedHub(): Hub
    {
        $hub = Hub::query()->create([
            'name' => 'Shared Hub',
            'slug' => 'shared',
            'type' => Hub::TYPE_SHARED,
            'is_active' => true,
            'checklist' => Hub::defaultChecklist(Hub::TYPE_SHARED),
        ]);

        app(HubService::class)->forgetCurrentCache();

        return $hub;
    }

    public function test_my_credits_report_summarises_earned_spent_and_daily_activity(): void
    {
        $this->createSharedHub();

        $user = User::factory()->create([
            'role' => User::ROLE_ADVISOR,
            'credits' => 70,
            'has_unlimited_credits' => false,
        ]);

        $plan = SubscriptionPlan::query()->create([
            'name' => 'Starter',
            'price' => 100,
            'credits' => 100,
            'duration_days' => 30,
            'is_active' => true,
        ]);

        UserSubscription::query()->create([
            'user_id' => $user->id,
            'subscription_plan_id' => $plan->id,
            'credits_granted' => 100,
            'amount_paid' => 100,
            'status' => 'active',
            'payment_status' => 'paid',
            'starts_at' => now()->subDays(2),
            'ends_at' => now()->addDays(28),
        ]);

        $post = Post::query()->create([
            'title' => 'Market Update',
            'type' => 'post',
            'category' => 'General',
            'credits_cost' => 20,
            'is_active' => true,
            'created_by' => $user->id,
        ]);

        PostPurchase::query()->create([
            'user_id' => $user->id,
            'post_id' => $post->id,
            'credits_spent' => 20,
            'purchased_at' => now()->subDay(),
        ]);

        $bundle = Bundle::query()->create([
            'title' => 'Starter Pack',
            'credits_cost' => 10,
            'is_active' => true,
            'created_by' => $user->id,
        ]);

        BundlePurchase::query()->create([
            'user_id' => $user->id,
            'bundle_id' => $bundle->id,
            'credits_spent' => 10,
            'purchased_at' => now(),
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/my-credits')->assertOk();

        $credits = $response->json('credits');
        $this->assertSame(70, (int) $credits['balance']);
        $this->assertFalse((bool) $credits['has_unlimited_credits']);
        $this->assertSame(100, (int) $credits['total_earned']);
        $this->assertSame(30, (int) $credits['total_spent']);
        $this->assertSame(3, (int) $credits['transaction_count']);
        $this->assertTrue((bool) $credits['plans_enabled']);
        $this->assertFalse((bool) $credits['is_white_label']);
        $this->assertGreaterThanOrEqual(2, (int) $credits['days_with_activity']);
        $this->assertCount(3, $credits['ledger']);
        $this->assertNotEmpty($credits['daily']);
    }

    public function test_white_label_credits_report_excludes_plans_and_exposes_allotment(): void
    {
        $hub = Hub::query()->create([
            'name' => 'WL Hub',
            'slug' => 'wl-hub',
            'type' => Hub::TYPE_WHITE_LABEL,
            'is_active' => true,
            'checklist' => Hub::defaultChecklist(Hub::TYPE_WHITE_LABEL),
            'subscriber_credits' => 250,
        ]);
        config(['hub.current_slug' => 'wl-hub']);
        app(HubService::class)->forgetCurrentCache();

        $user = User::factory()->create([
            'role' => User::ROLE_ADVISOR,
            'credits' => 240,
            'has_unlimited_credits' => false,
        ]);

        $plan = SubscriptionPlan::query()->create([
            'name' => 'Should Not Count',
            'price' => 100,
            'credits' => 100,
            'duration_days' => 30,
            'is_active' => true,
        ]);

        UserSubscription::query()->create([
            'user_id' => $user->id,
            'subscription_plan_id' => $plan->id,
            'credits_granted' => 100,
            'amount_paid' => 100,
            'status' => 'active',
            'payment_status' => 'paid',
            'starts_at' => now()->subDays(2),
            'ends_at' => now()->addDays(28),
        ]);

        $post = Post::query()->create([
            'title' => 'WL Post',
            'type' => 'post',
            'category' => 'General',
            'credits_cost' => 10,
            'is_active' => true,
            'created_by' => $user->id,
        ]);

        PostPurchase::query()->create([
            'user_id' => $user->id,
            'post_id' => $post->id,
            'credits_spent' => 10,
            'purchased_at' => now(),
        ]);

        Sanctum::actingAs($user);

        // Ensure hub resolution uses the WL hub created above.
        $this->assertSame(Hub::TYPE_WHITE_LABEL, $hub->type);

        $credits = $this->getJson('/api/my-credits')->assertOk()->json('credits');

        $this->assertTrue((bool) $credits['is_white_label']);
        $this->assertFalse((bool) $credits['plans_enabled']);
        $this->assertSame(0, (int) $credits['total_earned']);
        $this->assertSame(10, (int) $credits['total_spent']);
        $this->assertCount(1, $credits['ledger']);
        $this->assertSame('post_purchase', $credits['ledger'][0]['type']);
        $this->assertFalse((bool) $credits['allotment']['unlimited']);
        $this->assertSame(250, (int) $credits['allotment']['credits']);
    }
}
