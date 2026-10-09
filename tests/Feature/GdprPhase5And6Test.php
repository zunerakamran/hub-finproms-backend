<?php

namespace Tests\Feature;

use App\Models\Hub;
use App\Models\User;
use App\Services\HubService;
use App\Support\CookieNoticeDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GdprPhase5And6Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Hub::query()->create([
            'name' => 'Shared Hub',
            'slug' => 'shared',
            'type' => Hub::TYPE_SHARED,
            'is_active' => true,
            'checklist' => Hub::defaultChecklist(Hub::TYPE_SHARED),
        ]);
        app(HubService::class)->forgetCurrentCache();
    }

    public function test_hub_payload_includes_essential_cookie_notice(): void
    {
        $this->getJson('/api/hub')
            ->assertOk()
            ->assertJsonPath('hub.auth.cookies.essential_only', true)
            ->assertJsonPath('hub.auth.cookies.version', 1);

        $this->assertStringContainsString('essential cookies', CookieNoticeDefaults::shared());
    }

    public function test_capable_admin_can_update_cookie_notice_and_bump_version(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_FINPROMS_ADMIN,
            'email_verified_at' => now(),
        ]);
        Sanctum::actingAs($admin);

        Hub::query()->where('slug', 'shared')->update([
            'cookie_notice' => [
                'content' => CookieNoticeDefaults::shared(),
                'version' => 1,
                'updated_at' => now()->toIso8601String(),
            ],
        ]);
        app(HubService::class)->forgetCurrentCache();

        $this->putJson('/api/client-admin/cookies', [
            'content' => '<p>Updated essential cookie notice for testing.</p>',
        ])
            ->assertOk()
            ->assertJsonPath('cookies.version', 2);

        $this->getJson('/api/hub')
            ->assertOk()
            ->assertJsonPath('hub.auth.cookies.version', 2)
            ->assertJsonPath('hub.auth.cookies.content', '<p>Updated essential cookie notice for testing.</p>');
    }
}
