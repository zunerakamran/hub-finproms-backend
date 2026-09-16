<?php

namespace Tests\Feature;

use App\Models\Bundle;
use App\Models\ContentCheckout;
use App\Models\Hub;
use App\Models\Post;
use App\Models\Setting;
use App\Models\User;
use App\Services\HubService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OneOffContentPurchaseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Hub::query()->firstOrCreate(
            ['slug' => 'shared'],
            [
                'name' => 'Shared Hub',
                'type' => Hub::TYPE_SHARED,
                'is_active' => true,
                'checklist' => Hub::defaultChecklist(Hub::TYPE_SHARED),
            ]
        );

        app(HubService::class)->forgetCurrentCache();

        Setting::setValue(Setting::KEY_PAYMENT_BANK_TRANSFER_ENABLED, true);
        Setting::setValue(Setting::KEY_PAYMENT_BANK_TRANSFER_AUTO_CONFIRM, true);
        Setting::setValue(Setting::KEY_PAYMENT_STRIPE_ENABLED, false);
    }

    public function test_public_hub_member_can_buy_bundle_via_bank_transfer_without_subscription(): void
    {
        $creator = User::factory()->create(['role' => User::ROLE_CLIENT_ADMIN]);
        $buyer = User::factory()->create([
            'role' => User::ROLE_USER,
            'credits' => 0,
        ]);

        $post = Post::query()->create([
            'created_by' => $creator->id,
            'title' => 'Post A',
            'description' => 'Desc',
            'type' => 'Article',
            'category' => 'General',
            'credits_cost' => 5,
            'is_active' => true,
        ]);

        $bundle = Bundle::query()->create([
            'created_by' => $creator->id,
            'title' => 'Starter Bundle',
            'description' => 'Two posts',
            'credits_cost' => 10,
            'is_active' => true,
        ]);
        $bundle->posts()->attach($post->id, ['sort_order' => 0]);

        Sanctum::actingAs($buyer);

        $response = $this->postJson('/api/bundles/'.$bundle->id.'/purchase', [
            'payment_method' => 'bank_transfer',
        ]);

        $response->assertCreated()
            ->assertJsonPath('payment_method', 'bank_transfer')
            ->assertJsonPath('auto_confirmed', true)
            ->assertJsonPath('bundle.is_purchased', true);

        $this->assertDatabaseHas('bundle_purchases', [
            'user_id' => $buyer->id,
            'bundle_id' => $bundle->id,
            'credits_spent' => 10,
        ]);

        $this->assertDatabaseHas('post_purchases', [
            'user_id' => $buyer->id,
            'post_id' => $post->id,
        ]);

        $this->assertDatabaseHas('content_checkouts', [
            'user_id' => $buyer->id,
            'item_type' => ContentCheckout::TYPE_BUNDLE,
            'item_id' => $bundle->id,
            'payment_method' => 'bank_transfer',
            'payment_status' => 'paid',
        ]);

        $buyer->refresh();
        $this->assertSame(0, (int) $buyer->credits);
        $this->assertFalse($buyer->hasActiveSubscription());
    }

    public function test_one_off_payment_rejected_when_flag_disabled(): void
    {
        $hub = Hub::query()->where('slug', 'shared')->firstOrFail();
        $checklist = $hub->resolvedChecklist();
        $checklist['one_off_purchase'] = false;
        $hub->checklist = $checklist;
        $hub->save();
        app(HubService::class)->forgetCurrentCache();

        $creator = User::factory()->create(['role' => User::ROLE_CLIENT_ADMIN]);
        $buyer = User::factory()->create(['role' => User::ROLE_USER, 'credits' => 0]);

        $post = Post::query()->create([
            'created_by' => $creator->id,
            'title' => 'Post A',
            'description' => 'Desc',
            'type' => 'Article',
            'category' => 'General',
            'credits_cost' => 5,
            'is_active' => true,
        ]);

        $bundle = Bundle::query()->create([
            'created_by' => $creator->id,
            'title' => 'Starter Bundle',
            'description' => null,
            'credits_cost' => 10,
            'is_active' => true,
        ]);
        $bundle->posts()->attach($post->id, ['sort_order' => 0]);

        Sanctum::actingAs($buyer);

        $this->postJson('/api/bundles/'.$bundle->id.'/purchase', [
            'payment_method' => 'bank_transfer',
        ])->assertForbidden();
    }
}
