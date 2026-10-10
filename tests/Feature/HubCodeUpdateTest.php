<?php

namespace Tests\Feature;

use App\Models\Hub;
use App\Models\HubRelease;
use App\Models\User;
use App\Services\PowerAdminCapabilitiesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class HubCodeUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'hub.current_slug' => 'central',
            'hub.type' => 'central',
            'hub.is_control_plane' => true,
            'hub.version' => '1.0.0',
            'hub.frontend_version' => '1.0.0',
        ]);
        app(PowerAdminCapabilitiesService::class)->seedDefaults();
    }

    public function test_public_version_endpoint_returns_this_deploy(): void
    {
        Hub::query()->create([
            'name' => 'Central',
            'slug' => 'central',
            'type' => Hub::TYPE_CENTRAL,
            'is_active' => true,
        ]);

        $this->getJson('/api/version')
            ->assertOk()
            ->assertJsonPath('version', '1.0.0')
            ->assertJsonPath('frontend_version', '1.0.0')
            ->assertJsonPath('slug', 'central')
            ->assertJsonPath('is_control_plane', true);
    }

    public function test_power_admin_can_publish_release_and_see_overview(): void
    {
        Hub::query()->create([
            'name' => 'Central',
            'slug' => 'central',
            'type' => Hub::TYPE_CENTRAL,
            'is_active' => true,
        ]);

        Sanctum::actingAs(User::factory()->powerAdmin()->create());

        $this->postJson('/api/power-admin/releases', [
            'version' => '1.2.0',
            'backend_version' => '1.2.0',
            'frontend_version' => '1.2.1',
            'notes' => 'Cookie notice + version tracking',
        ])->assertCreated()
            ->assertJsonPath('release.version', '1.2.0')
            ->assertJsonPath('release.is_latest', true)
            ->assertJsonPath('overview.latest_release.version', '1.2.0');

        $this->assertSame(1, HubRelease::query()->where('is_latest', true)->count());

        $this->getJson('/api/power-admin/releases')
            ->assertOk()
            ->assertJsonPath('latest_release.version', '1.2.0')
            ->assertJsonPath('this_deploy.version', '1.0.0');
    }

    public function test_refresh_hub_uses_local_version_for_central(): void
    {
        $hub = Hub::query()->create([
            'name' => 'Central',
            'slug' => 'central',
            'type' => Hub::TYPE_CENTRAL,
            'is_active' => true,
        ]);

        Sanctum::actingAs(User::factory()->powerAdmin()->create());

        $this->postJson('/api/power-admin/releases', ['version' => '1.0.0'])->assertCreated();

        $this->postJson("/api/power-admin/hubs/{$hub->id}/refresh-version")
            ->assertOk()
            ->assertJsonPath('code_update.reported_version', '1.0.0')
            ->assertJsonPath('code_update.status', 'up_to_date')
            ->assertJsonPath('hub.code_update.source', 'local');
    }

    public function test_refresh_content_hub_polls_remote_api_url(): void
    {
        Hub::query()->create([
            'name' => 'Central',
            'slug' => 'central',
            'type' => Hub::TYPE_CENTRAL,
            'is_active' => true,
        ]);

        $wl = Hub::query()->create([
            'name' => 'Client Hub',
            'slug' => 'client-hub',
            'type' => Hub::TYPE_WHITE_LABEL,
            'is_active' => true,
            'api_url' => 'https://api.client-hub.test',
            'frontend_url' => 'https://client-hub.test',
        ]);

        Sanctum::actingAs(User::factory()->powerAdmin()->create());
        $this->postJson('/api/power-admin/releases', ['version' => '2.0.0'])->assertCreated();

        Http::fake([
            'https://api.client-hub.test/version' => Http::response([
                'version' => '1.5.0',
                'backend_version' => '1.5.0',
                'frontend_version' => '1.5.0',
                'slug' => 'client-hub',
            ], 200),
        ]);

        $this->postJson("/api/power-admin/hubs/{$wl->id}/refresh-version")
            ->assertOk()
            ->assertJsonPath('code_update.reported_version', '1.5.0')
            ->assertJsonPath('code_update.status', 'behind')
            ->assertJsonPath('code_update.source', 'remote_api');
    }

    public function test_manual_mark_version_when_api_unavailable(): void
    {
        Hub::query()->create([
            'name' => 'Central',
            'slug' => 'central',
            'type' => Hub::TYPE_CENTRAL,
            'is_active' => true,
        ]);

        $wl = Hub::query()->create([
            'name' => 'Client Hub',
            'slug' => 'client-hub',
            'type' => Hub::TYPE_WHITE_LABEL,
            'is_active' => true,
        ]);

        Sanctum::actingAs(User::factory()->powerAdmin()->create());
        $this->postJson('/api/power-admin/releases', ['version' => '1.1.0'])->assertCreated();

        $this->postJson("/api/power-admin/hubs/{$wl->id}/mark-version", [
            'version' => '1.1.0',
        ])->assertOk()
            ->assertJsonPath('code_update.status', 'up_to_date')
            ->assertJsonPath('code_update.source', 'manual');
    }

    public function test_hub_list_includes_code_update_block(): void
    {
        Hub::query()->create([
            'name' => 'Central',
            'slug' => 'central',
            'type' => Hub::TYPE_CENTRAL,
            'is_active' => true,
            'code_version' => '1.0.0',
            'code_version_status' => 'up_to_date',
        ]);

        Sanctum::actingAs(User::factory()->powerAdmin()->create());

        $this->getJson('/api/power-admin/hubs')
            ->assertOk()
            ->assertJsonPath('hubs.0.code_update.reported_version', '1.0.0')
            ->assertJsonPath('hubs.0.code_update.status', 'up_to_date');
    }
}
