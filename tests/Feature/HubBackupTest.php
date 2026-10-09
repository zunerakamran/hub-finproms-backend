<?php

namespace Tests\Feature;

use App\Models\Hub;
use App\Models\HubBackup;
use App\Models\User;
use App\Services\HubBackupService;
use App\Services\PowerAdminCapabilitiesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class HubBackupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['hub.current_slug' => 'central', 'hub.type' => 'central', 'hub.is_control_plane' => true]);
        app(PowerAdminCapabilitiesService::class)->seedDefaults();
    }

    public function test_power_admin_can_update_backup_schedule(): void
    {
        $hub = Hub::query()->create([
            'name' => 'Central',
            'slug' => 'central',
            'type' => Hub::TYPE_CENTRAL,
            'is_active' => true,
        ]);

        Sanctum::actingAs(User::factory()->powerAdmin()->create());

        $this->putJson("/api/power-admin/hubs/{$hub->id}/backups/schedule", [
            'backup_enabled' => true,
            'backup_time' => '03:30',
            'backup_timezone' => 'Asia/Karachi',
            'backup_frequency' => 'daily',
            'backup_retention_local' => 2,
            'backup_retention_central' => 7,
        ])->assertOk()
            ->assertJsonPath('schedule.enabled', true)
            ->assertJsonPath('schedule.time', '03:30')
            ->assertJsonPath('schedule.timezone', 'Asia/Karachi');

        $hub->refresh();
        $this->assertTrue((bool) $hub->backup_enabled);
        $this->assertSame('03:30', $hub->backup_time);
        $this->assertNotEmpty($hub->backup_token);
    }

    public function test_local_backup_creates_archive_for_current_hub(): void
    {
        $hub = Hub::query()->create([
            'name' => 'Central',
            'slug' => 'central',
            'type' => Hub::TYPE_CENTRAL,
            'is_active' => true,
            'backup_enabled' => true,
            'backup_time' => '02:00',
            'backup_timezone' => 'UTC',
        ]);

        $backup = app(HubBackupService::class)->createLocalBackup($hub, HubBackup::TRIGGER_MANUAL);

        $this->assertSame(HubBackup::STATUS_COMPLETED, $backup->status);
        // Control-plane deploy stores a single Central copy (no local duplicate).
        $this->assertSame(HubBackup::LOCATION_CENTRAL, $backup->location);
        $this->assertNotEmpty($backup->disk_path);
        $this->assertStringContainsString('backups/central/', (string) $backup->disk_path);
        $this->assertFileExists($backup->absolutePath());
    }

    public function test_central_backup_now_creates_single_copy(): void
    {
        $hub = Hub::query()->create([
            'name' => 'Central',
            'slug' => 'central',
            'type' => Hub::TYPE_CENTRAL,
            'is_active' => true,
        ]);

        Sanctum::actingAs(User::factory()->powerAdmin()->create());

        $this->postJson("/api/power-admin/hubs/{$hub->id}/backups", [])
            ->assertCreated()
            ->assertJsonPath('backup.location', HubBackup::LOCATION_CENTRAL)
            ->assertJsonMissingPath('local_backup');

        $this->assertSame(1, HubBackup::query()->where('status', HubBackup::STATUS_COMPLETED)->count());
    }

    public function test_backup_routes_require_capability(): void
    {
        $hub = Hub::query()->create([
            'name' => 'Central',
            'slug' => 'central',
            'type' => Hub::TYPE_CENTRAL,
            'is_active' => true,
        ]);

        app(PowerAdminCapabilitiesService::class)->update([
            'pa_manage_hub_backups' => false,
        ]);

        Sanctum::actingAs(User::factory()->powerAdmin()->create());

        $this->getJson("/api/power-admin/hubs/{$hub->id}/backups")->assertForbidden();
    }
}
