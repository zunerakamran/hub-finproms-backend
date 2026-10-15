<?php

namespace Tests\Feature;

use App\Models\Hub;
use App\Models\SecurityEvent;
use App\Models\SecurityIncident;
use App\Models\User;
use App\Services\CapabilitiesMatrixService;
use App\Services\HubService;
use App\Services\SecurityMonitoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SecurityMonitoringTest extends TestCase
{
    use RefreshDatabase;

    private function createHubWithSecurityMonitoring(): Hub
    {
        $hub = Hub::query()->create([
            'name' => 'Shared Hub',
            'slug' => 'shared',
            'type' => Hub::TYPE_SHARED,
            'is_active' => true,
            'checklist' => array_merge(Hub::defaultChecklist(Hub::TYPE_SHARED), [
                'security_monitoring' => true,
            ]),
        ]);

        $matrix = app(CapabilitiesMatrixService::class);
        $caps = $matrix->resolvedRoleCapabilities($hub);

        foreach (Hub::SECURITY_MONITORING_CAPABILITY_KEYS as $key) {
            $caps[User::ROLE_FINPROMS_ADMIN][$key] = true;
            $caps[User::ROLE_MANAGER][$key] = true;
            $caps[User::ROLE_POWER_ADMIN][$key] = true;
            $caps[User::ROLE_USER][$key] = false;
        }

        $hub->role_capabilities = $caps;
        $hub->save();
        app(HubService::class)->forgetCurrentCache();

        return $hub;
    }

    public function test_capability_keys_are_registered(): void
    {
        $this->assertContains('secmon_view_events', Hub::SECURITY_MONITORING_CAPABILITY_KEYS);
        $this->assertContains('secmon_manage_access', Hub::SECURITY_MONITORING_CAPABILITY_KEYS);
        $this->assertNotContains('module_security_monitoring', Hub::MODULE_KEYS);
        $this->assertArrayHasKey('security_monitoring', Hub::CHECKLIST_DEFINITIONS);
        $this->assertSame(Hub::GROUP_BEHAVIOUR, Hub::CHECKLIST_DEFINITIONS['security_monitoring']['group']);
        $this->assertSame(Hub::GROUP_SECURITY_MONITORING, Hub::CHECKLIST_DEFINITIONS['secmon_view_report']['group']);
        $this->assertSame(Hub::GROUP_SECURITY_MONITORING, Hub::CHECKLIST_DEFINITIONS['secmon_manage_access']['group']);
    }

    public function test_admin_can_list_events_and_report(): void
    {
        $hub = $this->createHubWithSecurityMonitoring();

        app(SecurityMonitoringService::class)->recordEvent($hub, [
            'category' => SecurityEvent::CATEGORY_AUTH,
            'event_type' => 'auth.login_failed',
            'severity' => SecurityEvent::SEVERITY_LOW,
            'title' => 'Failed login attempt',
            'description' => 'Failed login attempt for someone@example.test at '.now()->toIso8601String().'.',
            'actor_email' => 'someone@example.test',
            'source_ip' => '203.0.113.10',
        ]);

        $admin = User::factory()->create(['role' => User::ROLE_FINPROMS_ADMIN]);
        Sanctum::actingAs($admin);

        $this->getJson('/api/client-admin/security-monitoring/events')
            ->assertOk()
            ->assertJsonPath('data.0.title', 'Failed login attempt')
            ->assertJsonPath('data.0.severity', 'low');

        $this->getJson('/api/client-admin/security-monitoring/report')
            ->assertOk()
            ->assertJsonPath('report.totals.events', 1);
    }

    public function test_failed_login_creates_security_event(): void
    {
        $this->createHubWithSecurityMonitoring();

        User::factory()->create([
            'email' => 'victim@example.test',
            'password' => 'CorrectHorseBattery',
            'role' => User::ROLE_USER,
        ]);

        $this->postJson('/api/auth/login', [
            'email' => 'victim@example.test',
            'password' => 'wrong-password',
        ])->assertStatus(422);

        $this->assertDatabaseHas('security_events', [
            'event_type' => 'auth.login_failed',
            'actor_email' => 'victim@example.test',
        ]);
    }

    public function test_admin_can_declare_and_update_incident(): void
    {
        $hub = $this->createHubWithSecurityMonitoring();
        $admin = User::factory()->create(['role' => User::ROLE_FINPROMS_ADMIN]);
        Sanctum::actingAs($admin);

        $create = $this->postJson('/api/client-admin/security-monitoring/incidents', [
            'title' => 'Suspected credential stuffing',
            'description' => 'Multiple failed logins observed from one IP.',
            'severity' => 'high',
            'category' => 'auth',
        ])->assertCreated()
            ->assertJsonPath('data.title', 'Suspected credential stuffing')
            ->assertJsonPath('data.status', 'open');

        $id = $create->json('data.id');

        $this->patchJson('/api/client-admin/security-monitoring/incidents/'.$id, [
            'status' => 'investigating',
            'note' => 'Checking access logs and rotating credentials.',
        ])->assertOk()
            ->assertJsonPath('data.status', 'investigating')
            ->assertJsonPath('data.investigation_notes.0.body', 'Checking access logs and rotating credentials.');

        $this->assertDatabaseHas('security_incidents', [
            'id' => $id,
            'hub_id' => $hub->id,
            'status' => SecurityIncident::STATUS_INVESTIGATING,
        ]);
    }

    public function test_admin_can_monitor_now_on_demand(): void
    {
        $this->createHubWithSecurityMonitoring();
        $admin = User::factory()->create(['role' => User::ROLE_FINPROMS_ADMIN]);
        Sanctum::actingAs($admin);

        $this->postJson('/api/client-admin/security-monitoring/scan-now')
            ->assertOk()
            ->assertJsonStructure([
                'data' => ['status', 'summary', 'advisories', 'scanned_at'],
            ]);
    }

    public function test_blocked_when_functionality_off(): void
    {
        Hub::query()->create([
            'name' => 'Shared Hub',
            'slug' => 'shared',
            'type' => Hub::TYPE_SHARED,
            'is_active' => true,
            'checklist' => array_merge(Hub::defaultChecklist(Hub::TYPE_SHARED), [
                'security_monitoring' => false,
            ]),
        ]);
        app(HubService::class)->forgetCurrentCache();

        $admin = User::factory()->create(['role' => User::ROLE_FINPROMS_ADMIN]);
        Sanctum::actingAs($admin);

        $this->getJson('/api/client-admin/security-monitoring/events')
            ->assertStatus(403);
    }
}
