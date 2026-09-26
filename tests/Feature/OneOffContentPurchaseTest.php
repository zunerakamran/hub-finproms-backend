<?php

namespace Tests\Feature;

use App\Models\Bundle;
use App\Models\ContentCheckout;
use App\Models\Hub;
use App\Models\Post;
use App\Models\PostPurchase;
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

        config(['hub.current_slug' => 'shared']);
        app(HubService::class)->forgetCurrentCache();

        Setting::setValue(Setting::KEY_PAYMENT_BANK_TRANSFER_ENABLED, true);
        Setting::setValue(Setting::KEY_PAYMENT_BANK_TRANSFER_AUTO_CONFIRM, true);
        Setting::setValue(Setting::KEY_PAYMENT_STRIPE_ENABLED, false);
    }

    public function test_shared_hub_member_can_buy_bundle_via_bank_transfer_without_subscription(): void
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

    public function test_shared_hub_member_can_buy_post_with_credits(): void
    {
        $creator = User::factory()->create(['role' => User::ROLE_CLIENT_ADMIN]);
        $buyer = User::factory()->create([
            'role' => User::ROLE_USER,
            'credits' => 20,
        ]);

        $post = Post::query()->create([
            'created_by' => $creator->id,
            'title' => 'Post B',
            'description' => 'Desc',
            'type' => 'Article',
            'category' => 'General',
            'credits_cost' => 7,
            'is_active' => true,
        ]);

        Sanctum::actingAs($buyer);

        $this->postJson('/api/posts/'.$post->id.'/purchase')
            ->assertCreated()
            ->assertJsonPath('post.is_purchased', true);

        $this->assertDatabaseHas('post_purchases', [
            'user_id' => $buyer->id,
            'post_id' => $post->id,
            'credits_spent' => 7,
        ]);

        $buyer->refresh();
        $this->assertSame(13, (int) $buyer->credits);
    }

    public function test_shared_hub_exposes_cash_payment_methods_on_post_show(): void
    {
        $creator = User::factory()->create(['role' => User::ROLE_CLIENT_ADMIN]);
        $post = Post::query()->create([
            'created_by' => $creator->id,
            'title' => 'Post C',
            'description' => 'Desc',
            'type' => 'Article',
            'category' => 'General',
            'credits_cost' => 5,
            'is_active' => true,
        ]);

        $response = $this->getJson('/api/posts/'.$post->id);

        $response->assertOk()
            ->assertJsonPath('credits_purchase', true)
            ->assertJsonPath('cash_content_purchase', true)
            ->assertJsonPath('one_off_purchase', true);

        $methods = collect($response->json('payment_methods'))->keyBy('id');
        $this->assertTrue($methods->has('bank_transfer'));
        $this->assertTrue((bool) $methods['bank_transfer']['available']);
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

    public function test_white_label_hub_rejects_cash_payment_even_when_one_off_enabled(): void
    {
        $this->activateWhiteLabelHub(oneOffPurchase: true);

        $creator = User::factory()->create(['role' => User::ROLE_CLIENT_ADMIN]);
        $buyer = User::factory()->create([
            'role' => User::ROLE_USER,
            'credits' => 0,
        ]);

        $post = Post::query()->create([
            'created_by' => $creator->id,
            'title' => 'WL Post',
            'description' => 'Desc',
            'type' => 'Article',
            'category' => 'General',
            'credits_cost' => 5,
            'is_active' => true,
        ]);

        Sanctum::actingAs($buyer);

        $this->postJson('/api/posts/'.$post->id.'/purchase', [
            'payment_method' => 'bank_transfer',
        ])->assertForbidden()
            ->assertJsonPath('message', 'White-labelled hubs only support buying posts and bundles with credits.');

        $this->assertDatabaseCount('content_checkouts', 0);
        $this->assertDatabaseCount('post_purchases', 0);
    }

    public function test_white_label_hub_allows_credits_purchase_only(): void
    {
        $this->activateWhiteLabelHub(oneOffPurchase: true);

        $creator = User::factory()->create(['role' => User::ROLE_CLIENT_ADMIN]);
        $buyer = User::factory()->create([
            'role' => User::ROLE_USER,
            'credits' => 15,
        ]);

        $post = Post::query()->create([
            'created_by' => $creator->id,
            'title' => 'WL Post Credits',
            'description' => 'Desc',
            'type' => 'Reel',
            'category' => 'General',
            'credits_cost' => 5,
            'is_active' => true,
        ]);

        $bundle = Bundle::query()->create([
            'created_by' => $creator->id,
            'title' => 'WL Bundle',
            'description' => null,
            'credits_cost' => 8,
            'is_active' => true,
        ]);
        $bundle->posts()->attach($post->id, ['sort_order' => 0]);

        Sanctum::actingAs($buyer);

        $this->getJson('/api/posts/'.$post->id)
            ->assertOk()
            ->assertJsonPath('credits_purchase', true)
            ->assertJsonPath('cash_content_purchase', false)
            ->assertJsonPath('payment_methods', []);

        $this->postJson('/api/posts/'.$post->id.'/purchase')
            ->assertCreated()
            ->assertJsonPath('post.is_purchased', true);

        // Buying the same post again via bundle credit path would skip duplicate post purchase.
        PostPurchase::query()->where('user_id', $buyer->id)->where('post_id', $post->id)->delete();

        $this->postJson('/api/bundles/'.$bundle->id.'/purchase')
            ->assertCreated()
            ->assertJsonPath('bundle.is_purchased', true);

        $buyer->refresh();
        $this->assertSame(2, (int) $buyer->credits);
    }

    private function activateWhiteLabelHub(bool $oneOffPurchase): void
    {
        $checklist = Hub::defaultChecklist(Hub::TYPE_WHITE_LABEL);
        $checklist['one_off_purchase'] = $oneOffPurchase;
        $checklist['paid_credits'] = true;
        $checklist['unlimited_credits'] = false;

        Hub::query()->updateOrCreate(
            ['slug' => 'wl-content'],
            [
                'name' => 'WL Content Hub',
                'type' => Hub::TYPE_WHITE_LABEL,
                'is_active' => true,
                'checklist' => $checklist,
            ]
        );

        config(['hub.current_slug' => 'wl-content']);
        app(HubService::class)->forgetCurrentCache();
    }
}
