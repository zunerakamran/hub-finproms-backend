<?php

namespace Tests\Feature;

use App\Models\Firm;
use App\Models\Hub;
use App\Models\User;
use App\Services\CapabilitiesMatrixService;
use App\Services\FirmComplianceVisibilityService;
use App\Services\HubService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FirmComplianceVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function createSharedHub(): Hub
    {
        $hub = Hub::query()->create([
            'name' => 'Shared Hub',
            'slug' => 'shared',
            'type' => Hub::TYPE_SHARED,
            'is_active' => true,
            'checklist' => Hub::defaultChecklist(Hub::TYPE_SHARED),
        ]);
        app(HubService::class)->forgetCurrentCache();

        return $hub;
    }

    private function enableManageFirms(Hub $hub): void
    {
        $matrix = app(CapabilitiesMatrixService::class);
        $caps = $matrix->resolvedRoleCapabilities($hub);
        $caps[User::ROLE_POWER_ADMIN]['dashboard_manage_firms'] = true;
        $hub->role_capabilities = $caps;
        $hub->save();
        app(HubService::class)->forgetCurrentCache();
    }

    public function test_central_network_firm_is_created_and_can_be_renamed(): void
    {
        $hub = $this->createSharedHub();
        $this->enableManageFirms($hub);
        $admin = User::factory()->powerAdmin()->create();
        Sanctum::actingAs($admin);

        $index = $this->getJson('/api/power-admin/firms')->assertOk();
        $centralId = (int) $index->json('central_firm_id');
        $this->assertGreaterThan(0, $centralId);
        $this->assertTrue(collect($index->json('firms'))->contains(
            fn ($f) => ($f['is_central'] ?? false) === true
        ));

        $this->putJson('/api/power-admin/firms/'.$centralId, [
            'name' => 'Network HQ',
            'compliance_visible_to_own' => true,
            'compliance_visible_to_central' => true,
        ])->assertOk()
            ->assertJsonPath('firm.name', 'Network HQ')
            ->assertJsonPath('firm.is_central', true);

        $this->deleteJson('/api/power-admin/firms/'.$centralId)
            ->assertStatus(422);
    }

    public function test_firm_compliance_visibility_settings_saved(): void
    {
        $hub = $this->createSharedHub();
        $this->enableManageFirms($hub);
        $central = Firm::central();
        $admin = User::factory()->powerAdmin()->create();
        Sanctum::actingAs($admin);

        $created = $this->postJson('/api/power-admin/firms', [
            'name' => 'Acme Wealth',
            'compliance_visible_to_own' => true,
            'compliance_visible_to_central' => true,
            'compliance_visible_to_firm_id' => null,
        ])->assertCreated();

        $firmId = (int) $created->json('firm.id');

        $peer = Firm::query()->create(['name' => 'Peer Firm']);

        $this->putJson('/api/power-admin/firms/'.$firmId, [
            'name' => 'Acme Wealth',
            'compliance_visible_to_own' => false,
            'compliance_visible_to_central' => true,
            'compliance_visible_to_firm_id' => $peer->id,
        ])->assertOk()
            ->assertJsonPath('firm.compliance_visibility.visible_to_own', false)
            ->assertJsonPath('firm.compliance_visibility.visible_to_central', true)
            ->assertJsonPath('firm.compliance_visibility.visible_to_firm_id', $peer->id);

        $this->assertNotNull($central->id);
    }

    public function test_visibility_service_respects_own_central_and_other_firm(): void
    {
        $central = Firm::central();
        $acme = Firm::query()->create([
            'name' => 'Acme',
            'compliance_visible_to_own' => true,
            'compliance_visible_to_central' => true,
            'compliance_visible_to_firm_id' => null,
        ]);
        $other = Firm::query()->create(['name' => 'Other']);
        $peer = Firm::query()->create(['name' => 'Peer']);

        $visibility = app(FirmComplianceVisibilityService::class);

        $this->assertTrue($visibility->viewerFirmCanSee($acme->id, $acme, false));
        $this->assertFalse($visibility->viewerFirmCanSee($other->id, $acme, false));
        $this->assertTrue($visibility->viewerFirmCanSee($central->id, $acme, true));
        $this->assertFalse($visibility->viewerFirmCanSee(null, $acme, false));
        $this->assertFalse($visibility->viewerFirmCanSee($acme->id, null, false));

        $acme->update([
            'compliance_visible_to_own' => false,
            'compliance_visible_to_central' => false,
            'compliance_visible_to_firm_id' => $peer->id,
        ]);
        $acme->refresh();

        $this->assertFalse($visibility->viewerFirmCanSee($acme->id, $acme, false));
        $this->assertTrue($visibility->viewerFirmCanSee($peer->id, $acme, false));
        $this->assertFalse($visibility->viewerFirmCanSee($central->id, $acme, true));
    }

    public function test_power_and_finproms_admins_bypass_firm_scope(): void
    {
        $visibility = app(FirmComplianceVisibilityService::class);
        $power = User::factory()->powerAdmin()->create(['firm_id' => null]);
        $finproms = User::factory()->create(['role' => User::ROLE_FINPROMS_ADMIN, 'firm_id' => null]);
        $manager = User::factory()->create(['role' => User::ROLE_MANAGER, 'firm_id' => null]);

        $this->assertTrue($visibility->actorBypassesFirmScope($power));
        $this->assertTrue($visibility->actorBypassesFirmScope($finproms));
        $this->assertFalse($visibility->actorBypassesFirmScope($manager));
    }

    public function test_manager_with_firm_only_sees_allowed_firm_requests(): void
    {
        $firmX = Firm::query()->create([
            'name' => 'Firm X',
            'compliance_visible_to_own' => true,
            'compliance_visible_to_central' => false,
        ]);
        $firmY = Firm::query()->create([
            'name' => 'Firm Y',
            'compliance_visible_to_own' => true,
            'compliance_visible_to_central' => false,
        ]);

        $manager = User::factory()->create([
            'role' => User::ROLE_MANAGER,
            'firm_id' => $firmX->id,
        ]);
        $manager->setRelation('firm', $firmX);

        $visibility = app(FirmComplianceVisibilityService::class);

        $this->assertTrue($visibility->actorCanSeeSubmitterFirm($manager, $firmX));
        $this->assertFalse($visibility->actorCanSeeSubmitterFirm($manager, $firmY));
        $this->assertFalse($visibility->actorCanSeeSubmitterFirm($manager, null));

        $power = User::factory()->powerAdmin()->create();
        $this->assertTrue($visibility->actorCanSeeSubmitterFirm($power, $firmY));
        $this->assertTrue($visibility->actorCanViewRequest($power, 99, $firmY->id, null));
    }

    public function test_client_admin_with_firm_follows_same_visibility_rules(): void
    {
        $firmX = Firm::query()->create([
            'name' => 'Firm X',
            'compliance_visible_to_own' => true,
            'compliance_visible_to_central' => false,
        ]);
        $firmY = Firm::query()->create([
            'name' => 'Firm Y',
            'compliance_visible_to_own' => true,
            'compliance_visible_to_central' => false,
        ]);

        $admin = User::factory()->create([
            'role' => User::ROLE_CLIENT_ADMIN,
            'firm_id' => $firmX->id,
        ]);
        $admin->setRelation('firm', $firmX);

        $visibility = app(FirmComplianceVisibilityService::class);

        $this->assertTrue($visibility->actorCanSeeSubmitterFirm($admin, $firmX));
        $this->assertFalse($visibility->actorCanSeeSubmitterFirm($admin, $firmY));
        $this->assertTrue($visibility->actorCanViewRequest($admin, 1, $firmX->id, null));
        $this->assertFalse($visibility->actorCanViewRequest($admin, 2, $firmY->id, null));
        // Assigned work is always visible even across firms.
        $this->assertTrue($visibility->actorCanViewRequest($admin, 2, $firmY->id, $admin->id));
    }

    public function test_assignee_dropdown_respects_submitter_firm_visibility(): void
    {
        $central = Firm::central();
        $acme = Firm::query()->create([
            'name' => 'Acme',
            'compliance_visible_to_own' => true,
            'compliance_visible_to_central' => true,
            'compliance_visible_to_firm_id' => null,
        ]);
        $other = Firm::query()->create([
            'name' => 'Other',
            'compliance_visible_to_own' => true,
        ]);

        $visibility = app(FirmComplianceVisibilityService::class);

        $this->assertEqualsCanonicalizing(
            array_values(array_filter([(int) $acme->id, $central?->id ? (int) $central->id : null])),
            $visibility->assigneeFirmIdsForSubmitterFirm($acme)
        );

        $acmeReviewer = User::factory()->create([
            'role' => User::ROLE_APPROVER,
            'firm_id' => $acme->id,
        ]);
        $otherReviewer = User::factory()->create([
            'role' => User::ROLE_APPROVER,
            'firm_id' => $other->id,
        ]);
        $centralReviewer = User::factory()->create([
            'role' => User::ROLE_APPROVER,
            'firm_id' => $central->id,
        ]);
        $power = User::factory()->powerAdmin()->create(['firm_id' => null]);

        $this->assertTrue($visibility->userIsEligibleAssignee($acmeReviewer, $acme));
        $this->assertTrue($visibility->userIsEligibleAssignee($centralReviewer, $acme));
        $this->assertTrue($visibility->userIsEligibleAssignee($power, $acme));
        $this->assertFalse($visibility->userIsEligibleAssignee($otherReviewer, $acme));
        $this->assertFalse($visibility->userIsEligibleAssignee($otherReviewer, null));
        $this->assertTrue($visibility->userIsEligibleAssignee($power, null));
    }
}
