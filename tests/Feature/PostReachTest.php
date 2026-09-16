<?php

namespace Tests\Feature;

use App\Models\Hub;
use App\Models\Post;
use App\Models\PostReach;
use App\Models\User;
use App\Services\HubService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PostReachTest extends TestCase
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
    }

    public function test_listing_scroll_reach_increments_once_per_viewer(): void
    {
        $creator = User::factory()->create(['role' => User::ROLE_CLIENT_ADMIN]);
        $viewer = User::factory()->create(['role' => User::ROLE_USER]);

        $post = Post::query()->create([
            'created_by' => $creator->id,
            'title' => 'Reel one',
            'description' => 'Desc',
            'type' => 'Reel',
            'category' => 'General',
            'credits_cost' => 5,
            'is_active' => true,
            'reach_count' => 0,
            'views_count' => 0,
        ]);

        Sanctum::actingAs($viewer);

        $this->postJson('/api/posts/reach', [
            'post_ids' => [$post->id],
        ])
            ->assertOk()
            ->assertJsonPath('reached_post_ids.0', $post->id);

        $post->refresh();
        $this->assertSame(1, (int) $post->reach_count);
        $this->assertDatabaseHas('post_reaches', [
            'post_id' => $post->id,
            'viewer_key' => 'user:'.$viewer->id,
        ]);

        // Same viewer scrolling again must not double-count reach.
        $this->postJson('/api/posts/reach', [
            'post_ids' => [$post->id],
        ])
            ->assertOk()
            ->assertJsonPath('reached_post_ids', []);

        $post->refresh();
        $this->assertSame(1, (int) $post->reach_count);
        $this->assertSame(0, (int) $post->views_count);
        $this->assertSame(1, PostReach::query()->where('post_id', $post->id)->count());
    }

    public function test_opening_post_detail_increments_views_not_reach(): void
    {
        $creator = User::factory()->create(['role' => User::ROLE_CLIENT_ADMIN]);
        $viewer = User::factory()->create(['role' => User::ROLE_USER]);

        $post = Post::query()->create([
            'created_by' => $creator->id,
            'title' => 'Article',
            'description' => 'Desc',
            'type' => 'Article',
            'category' => 'General',
            'credits_cost' => 5,
            'is_active' => true,
            'reach_count' => 0,
            'views_count' => 0,
        ]);

        Sanctum::actingAs($viewer);

        $this->getJson('/api/posts/'.$post->id)->assertOk();

        $post->refresh();
        $this->assertSame(1, (int) $post->views_count);
        $this->assertSame(0, (int) $post->reach_count);
    }
}
