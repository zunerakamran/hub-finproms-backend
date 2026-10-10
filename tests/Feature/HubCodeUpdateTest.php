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

    public function test_publish_release_with_backend_zip_and_apply_locally(): void
    {
        $hub = Hub::query()->create([
            'name' => 'Central',
            'slug' => 'central',
            'type' => Hub::TYPE_CENTRAL,
            'is_active' => true,
        ]);

        Sanctum::actingAs(User::factory()->powerAdmin()->create());

        $versionBefore = is_file(base_path('VERSION'))
            ? (string) file_get_contents(base_path('VERSION'))
            : "1.0.0\n";
        $markerPath = storage_path('app/private/code-update-version.json');

        $zipPath = storage_path('framework/testing/backend-release.zip');
        if (! is_dir(dirname($zipPath))) {
            mkdir(dirname($zipPath), 0755, true);
        }
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        $zip->addFromString('VERSION', "2.0.0\n");
        $zip->addFromString('app/.code-update-probe', 'ok');
        $zip->close();

        try {
            $this->post('/api/power-admin/releases', [
                'version' => '2.0.0',
                'backend_zip' => new \Illuminate\Http\UploadedFile(
                    $zipPath,
                    'backend.zip',
                    'application/zip',
                    null,
                    true
                ),
            ], [
                'Accept' => 'application/json',
            ])->assertCreated()
                ->assertJsonPath('release.backend_artifact', true)
                ->assertJsonPath('release.ready_to_apply', true);

            $this->postJson('/api/power-admin/releases/apply', [
                'hub_ids' => [$hub->id],
            ])->assertOk()
                ->assertJsonPath('applied', 1)
                ->assertJsonPath('failed', 0);

            $hub->refresh();
            $this->assertSame('applied', $hub->code_apply_status);
            $this->assertSame('2.0.0', $hub->code_target_version);
            $this->assertFileExists(base_path('app/.code-update-probe'));
        } finally {
            @unlink(base_path('app/.code-update-probe'));
            file_put_contents(base_path('VERSION'), $versionBefore);
            @unlink($markerPath);
        }
    }

    public function test_apply_requires_artifacts(): void
    {
        $hub = Hub::query()->create([
            'name' => 'Central',
            'slug' => 'central',
            'type' => Hub::TYPE_CENTRAL,
            'is_active' => true,
        ]);

        Sanctum::actingAs(User::factory()->powerAdmin()->create());
        $this->postJson('/api/power-admin/releases', ['version' => '3.0.0'])->assertCreated();

        $this->postJson('/api/power-admin/releases/apply', [
            'hub_ids' => [$hub->id],
        ])->assertStatus(422);
    }

    public function test_remote_apply_posts_to_hub_api(): void
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
            'backup_token' => 'test-update-token',
        ]);

        Sanctum::actingAs(User::factory()->powerAdmin()->create());

        $zipPath = storage_path('framework/testing/backend-release-remote.zip');
        if (! is_dir(dirname($zipPath))) {
            mkdir(dirname($zipPath), 0755, true);
        }
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        $zip->addFromString('VERSION', "2.1.0\n");
        $zip->close();

        $this->post('/api/power-admin/releases', [
            'version' => '2.1.0',
            'backend_zip' => new \Illuminate\Http\UploadedFile(
                $zipPath,
                'backend.zip',
                'application/zip',
                null,
                true
            ),
        ], [
            'Accept' => 'application/json',
        ])->assertCreated();

        Http::fake([
            'https://api.client-hub.test/internal/code-updates/apply' => Http::response([
                'version' => '2.1.0',
                'backend_applied' => true,
                'frontend_applied' => false,
                'migrated' => true,
                'message' => 'Release 2.1.0 applied on this hub.',
            ], 200),
            'https://api.client-hub.test/version' => Http::response([
                'version' => '2.1.0',
                'backend_version' => '2.1.0',
                'frontend_version' => '2.1.0',
            ], 200),
        ]);

        $this->postJson('/api/power-admin/releases/apply', [
            'hub_ids' => [$wl->id],
        ])->assertOk()
            ->assertJsonPath('applied', 1);

        $wl->refresh();
        $this->assertSame('applied', $wl->code_apply_status);
        $this->assertSame('2.1.0', $wl->code_version);
    }
}
