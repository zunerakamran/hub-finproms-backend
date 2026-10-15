<?php

namespace Tests\Feature;

use App\Models\ActivityAnomaly;
use App\Models\ActivityLog;
use App\Models\Hub;
use App\Models\User;
use App\Services\ActivityAnomalyService;
use App\Services\CapabilitiesMatrixService;
use App\Services\HubService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ActivityAnomalyDetectionTest extends TestCase
{
    use RefreshDatabase;

    private function createHub(): Hub
    {
        $hub = Hub::query()->create([
            'name' => 'Shared Hub',
            'slug' => 'shared',
            'type' => Hub::TYPE_SHARED,
            'is_active' => true,
            'checklist' => Hub::defaultChecklist(Hub::TYPE_SHARED),
        ]);

        $matrix = app(CapabilitiesMatrixService::class);
        $caps = $matrix->resolvedRoleCapabilities($hub);
        $caps[User::ROLE_FINPROMS_ADMIN]['dashboard_view_activity_logs'] = true;
        $caps[User::ROLE_USER]['dashboard_view_activity_logs'] = false;
        $hub->role_capabilities = $caps;
        $hub->save();
        app(HubService::class)->forgetCurrentCache();

        return $hub;
    }

    public function test_failed_login_burst_creates_anomaly(): void
    {
        $hub = $this->createHub();

        for ($i = 0; $i < 5; $i++) {
            ActivityLog::query()->create([
                'hub_id' => $hub->id,
                'user_id' => null,
                'user_email' => 'victim@example.test',
                'action' => 'auth.login_failed',
                'description' => 'Failed login',
                'ip_address' => '203.0.113.50',
                'created_at' => now()->subMinutes(2),
            ]);
        }

        $result = app(ActivityAnomalyService::class)->detectForHub($hub);

        $this->assertGreaterThanOrEqual(1, $result['created']);
        $this->assertDatabaseHas('activity_anomalies', [
            'hub_id' => $hub->id,
            'anomaly_type' => ActivityAnomaly::TYPE_FAILED_LOGIN_BURST,
            'status' => ActivityAnomaly::STATUS_OPEN,
        ]);
    }

    public function test_admin_can_list_review_and_detect_now(): void
    {
        $hub = $this->createHub();
        $admin = User::factory()->create(['role' => User::ROLE_FINPROMS_ADMIN]);

        $anomaly = ActivityAnomaly::query()->create([
            'hub_id' => $hub->id,
            'detected_at' => now(),
            'anomaly_type' => ActivityAnomaly::TYPE_ADMIN_ACTION_BURST,
            'severity' => ActivityAnomaly::SEVERITY_MEDIUM,
            'title' => 'Burst of high-impact admin actions',
            'description' => 'Test anomaly',
            'status' => ActivityAnomaly::STATUS_OPEN,
            'actor_user_id' => $admin->id,
            'actor_name' => $admin->name,
            'actor_email' => $admin->email,
            'related_activity_log_ids' => [],
            'properties' => ['dedupe_key' => 'admin_burst|'.$admin->id],
        ]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/client-admin/activity-logs/anomalies')
            ->assertOk()
            ->assertJsonPath('data.0.id', $anomaly->id)
            ->assertJsonPath('data.0.title', 'Burst of high-impact admin actions');

        $this->getJson('/api/client-admin/activity-logs/anomalies/'.$anomaly->id)
            ->assertOk()
            ->assertJsonPath('data.id', $anomaly->id);

        $this->patchJson('/api/client-admin/activity-logs/anomalies/'.$anomaly->id, [
            'status' => 'reviewed',
        ])->assertOk()
            ->assertJsonPath('data.status', 'reviewed');

        $this->postJson('/api/client-admin/activity-logs/anomalies/detect-now')
            ->assertOk()
            ->assertJsonStructure(['data' => ['created', 'by_type', 'detected_at']]);
    }

    public function test_user_without_capability_cannot_list_anomalies(): void
    {
        $this->createHub();
        $user = User::factory()->create(['role' => User::ROLE_USER]);
        Sanctum::actingAs($user);

        $this->getJson('/api/client-admin/activity-logs/anomalies')
            ->assertForbidden();
    }

    public function test_escalate_requires_security_monitoring(): void
    {
        $hub = $this->createHub();
        $admin = User::factory()->create(['role' => User::ROLE_FINPROMS_ADMIN]);

        $anomaly = ActivityAnomaly::query()->create([
            'hub_id' => $hub->id,
            'detected_at' => now(),
            'anomaly_type' => ActivityAnomaly::TYPE_EXPORT_VOLUME,
            'severity' => ActivityAnomaly::SEVERITY_HIGH,
            'title' => 'Unusual export volume',
            'description' => 'Test',
            'status' => ActivityAnomaly::STATUS_OPEN,
            'related_activity_log_ids' => [],
            'properties' => [],
        ]);

        Sanctum::actingAs($admin);

        $this->postJson('/api/client-admin/activity-logs/anomalies/'.$anomaly->id.'/escalate')
            ->assertStatus(422);
    }
}
