<?php

namespace Tests\Feature;

use App\Models\GeneralComplianceRequest;
use App\Models\GeneralComplianceRequestVersion;
use App\Models\Hub;
use App\Models\ModuleDisasterSnapshot;
use App\Models\SecurityIncident;
use App\Models\User;
use App\Services\CapabilitiesMatrixService;
use App\Services\HubService;
use App\Services\ModuleDisasterRecoveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ModuleDisasterRecoveryTest extends TestCase
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
                'module_general_compliance' => true,
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

    public function test_capability_key_is_registered(): void
    {
        $this->assertContains('secmon_manage_disaster_recovery', Hub::SECURITY_MONITORING_CAPABILITY_KEYS);
        $this->assertArrayHasKey('secmon_manage_disaster_recovery', Hub::CHECKLIST_DEFINITIONS);
    }

    public function test_recognize_and_rollback_general_compliance_module(): void
    {
        $hub = $this->createHubWithSecurityMonitoring();
        $admin = User::factory()->create(['role' => User::ROLE_FINPROMS_ADMIN]);

        $request = GeneralComplianceRequest::query()->create([
            'user_id' => $admin->id,
            'name' => 'Original submission',
            'current_version' => 1,
            'submission_date' => now(),
        ]);
        GeneralComplianceRequestVersion::query()->create([
            'request_id' => $request->id,
            'version_number' => 1,
            'status' => GeneralComplianceRequest::STATUS_PENDING,
            'description' => 'Good state before disaster',
            'submitted_by' => $admin->id,
            'submitted_at' => now(),
        ]);

        $dr = app(ModuleDisasterRecoveryService::class);
        $goodSnapshot = $dr->createSnapshot(
            $hub,
            'module_general_compliance',
            $admin,
            ModuleDisasterSnapshot::TRIGGER_MANUAL,
            'Good baseline'
        );

        // Corrupt module data (simulate disaster).
        $request->update(['name' => 'Corrupted after disaster']);
        GeneralComplianceRequestVersion::query()->where('request_id', $request->id)->update([
            'description' => 'Bad corrupted content',
        ]);

        Sanctum::actingAs($admin);

        $declare = $this->postJson('/api/client-admin/security-monitoring/incidents', [
            'title' => 'GC data corruption',
            'description' => 'General Compliance records were corrupted.',
            'severity' => 'high',
            'category' => 'data',
            'affected_module' => 'module_general_compliance',
            'recognize_disaster' => true,
        ])->assertCreated();

        $incidentId = $declare->json('data.id');
        $this->assertTrue((bool) $declare->json('data.disaster_recognized'));
        $this->assertSame('module_general_compliance', $declare->json('data.affected_module'));

        $this->postJson("/api/client-admin/security-monitoring/incidents/{$incidentId}/rollback", [
            'snapshot_id' => $goodSnapshot->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.recovery_status', 'rolled_back');

        $this->assertSame('Original submission', GeneralComplianceRequest::query()->find($request->id)?->name);
        $this->assertSame(
            'Good state before disaster',
            GeneralComplianceRequestVersion::query()->where('request_id', $request->id)->value('description')
        );
    }

    public function test_user_without_capability_cannot_rollback(): void
    {
        $hub = $this->createHubWithSecurityMonitoring();
        $user = User::factory()->create(['role' => User::ROLE_USER]);
        Sanctum::actingAs($user);

        $incident = SecurityIncident::query()->create([
            'hub_id' => $hub->id,
            'title' => 'Test',
            'category' => 'data',
            'severity' => 'high',
            'status' => SecurityIncident::STATUS_OPEN,
            'affected_module' => 'module_general_compliance',
            'disaster_recognized' => true,
            'recovery_status' => SecurityIncident::RECOVERY_RECOGNIZED,
            'declared_by_name' => 'System',
        ]);

        $this->postJson("/api/client-admin/security-monitoring/incidents/{$incident->id}/rollback", [
            'snapshot_id' => 1,
        ])->assertForbidden();
    }
}
