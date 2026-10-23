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

    private function enableManageFirms(Hub $hub, string $role = User::ROLE_CLIENT_ADMIN): void
    {
        $matrix = app(CapabilitiesMatrixService::class);
        $caps = $matrix->resolvedRoleCapabilities($hub);
        $caps[$role]['dashboard_manage_firms'] = true;
        $hub->role_capabilities = $caps;
        $hub->save();
        app(HubService::class)->forgetCurrentCache();
    }

    public function test_central_network_firm_is_created_and_can_be_renamed(): void
    {
        $hub = $this->createSharedHub();
        $this->enableManageFirms($hub);
        $admin = User::factory()->create(['role' => User::ROLE_CLIENT_ADMIN]);
        Sanctum::actingAs($admin);

        $index = $this->getJson('/api/client-admin/firms')->assertOk();
        $centralId = (int) $index->json('central_firm_id');
        $this->assertGreaterThan(0, $centralId);
        $this->assertTrue(collect($index->json('firms'))->contains(
            fn ($f) => ($f['is_central'] ?? false) === true
        ));

        $this->putJson('/api/client-admin/firms/'.$centralId, [
            'name' => 'Network HQ',
            'compliance_visible_to_own' => true,
            'compliance_authority_firm_ids' => [],
        ])->assertOk()
            ->assertJsonPath('firm.name', 'Network HQ')
            ->assertJsonPath('firm.is_central', true);

        $this->deleteJson('/api/client-admin/firms/'.$centralId)
            ->assertStatus(422);
    }

    public function test_firm_delegated_compliance_access_multi_select_saved(): void
    {
        $hub = $this->createSharedHub();
        $this->enableManageFirms($hub);
        $central = Firm::central();
        $admin = User::factory()->create(['role' => User::ROLE_CLIENT_ADMIN]);
        Sanctum::actingAs($admin);

        $peer = Firm::query()->create(['name' => 'Peer Firm']);
        $peer2 = Firm::query()->create(['name' => 'Peer Firm 2']);

        $created = $this->postJson('/api/client-admin/firms', [
            'name' => 'Acme Wealth',
            'compliance_visible_to_own' => true,
            'compliance_authority_firm_ids' => [$central->id, $peer->id, $peer2->id],
        ])->assertCreated();

        $firmId = (int) $created->json('firm.id');
        $authorityIds = $created->json('firm.compliance_visibility.authority_firm_ids');
        $this->assertEqualsCanonicalizing(
            [(int) $central->id, (int) $peer->id, (int) $peer2->id],
            array_map('intval', $authorityIds)
        );
        $this->assertTrue($created->json('firm.compliance_visibility.visible_to_central'));

        $this->putJson('/api/client-admin/firms/'.$firmId, [
            'name' => 'Acme Wealth',
            'compliance_visible_to_own' => false,
            'compliance_authority_firm_ids' => [$peer->id],
        ])->assertOk()
            ->assertJsonPath('firm.compliance_visibility.visible_to_own', false)
            ->assertJsonPath('firm.compliance_visibility.visible_to_central', false);

        $listed = collect($this->getJson('/api/client-admin/firms')->json('firms'))
            ->firstWhere('id', $firmId);
        $this->assertEqualsCanonicalizing(
            [(int) $peer->id],
            array_map('intval', $listed['compliance_visibility']['authority_firm_ids'])
        );
    }

    public function test_visibility_service_respects_own_and_multi_authority_firms(): void
    {
        $central = Firm::central();
        $acme = Firm::query()->create([
            'name' => 'Acme',
            'compliance_visible_to_own' => true,
        ]);
        $other = Firm::query()->create(['name' => 'Other']);
        $peer = Firm::query()->create(['name' => 'Peer']);
        $peer2 = Firm::query()->create(['name' => 'Peer 2']);

        $acme->syncComplianceAuthorities([(int) $central->id, (int) $peer->id, (int) $peer2->id]);
        $acme->refresh();

        $visibility = app(FirmComplianceVisibilityService::class);

        $this->assertTrue($visibility->viewerFirmCanSee($acme->id, $acme, false));
        $this->assertFalse($visibility->viewerFirmCanSee($other->id, $acme, false));
        $this->assertTrue($visibility->viewerFirmCanSee($central->id, $acme, true));
        $this->assertTrue($visibility->viewerFirmCanSee($peer->id, $acme, false));
        $this->assertTrue($visibility->viewerFirmCanSee($peer2->id, $acme, false));
        $this->assertFalse($visibility->viewerFirmCanSee(null, $acme, false));
        $this->assertFalse($visibility->viewerFirmCanSee($acme->id, null, false));

        // One-way: peer cannot see other firms just because Acme delegated to peer.
        $this->assertFalse($visibility->viewerFirmCanSee($acme->id, $peer, false));

        $acme->update(['compliance_visible_to_own' => false]);
        $acme->syncComplianceAuthorities([(int) $peer->id]);
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

    public function test_central_approver_bypasses_firm_scope(): void
    {
        $central = Firm::central();
        $visibility = app(FirmComplianceVisibilityService::class);

        $approver = User::factory()->create([
            'role' => User::ROLE_APPROVER,
            'firm_id' => $central->id,
        ]);
        $approver->setRelation('firm', $central);

        $this->assertTrue($visibility->actorBypassesFirmScope($approver));

        $memberFirm = Firm::query()->create(['name' => 'Member']);
        $memberApprover = User::factory()->create([
            'role' => User::ROLE_APPROVER,
            'firm_id' => $memberFirm->id,
        ]);
        $memberApprover->setRelation('firm', $memberFirm);
        $this->assertFalse($visibility->actorBypassesFirmScope($memberApprover));
    }

    public function test_manager_with_firm_only_sees_allowed_firm_requests(): void
    {
        $firmX = Firm::query()->create([
            'name' => 'Firm X',
            'compliance_visible_to_own' => true,
        ]);
        $firmY = Firm::query()->create([
            'name' => 'Firm Y',
            'compliance_visible_to_own' => true,
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

        $firmY->syncComplianceAuthorities([(int) $firmX->id]);
        $firmY->refresh();
        $this->assertTrue($visibility->actorCanSeeSubmitterFirm($manager, $firmY));

        $power = User::factory()->powerAdmin()->create();
        $this->assertTrue($visibility->actorCanSeeSubmitterFirm($power, $firmY));
        $this->assertTrue($visibility->actorCanViewRequest($power, 99, $firmY->id, null));
    }

    public function test_client_admin_with_firm_follows_same_visibility_rules(): void
    {
        $firmX = Firm::query()->create([
            'name' => 'Firm X',
            'compliance_visible_to_own' => true,
        ]);
        $firmY = Firm::query()->create([
            'name' => 'Firm Y',
            'compliance_visible_to_own' => true,
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
        ]);
        $other = Firm::query()->create([
            'name' => 'Other',
            'compliance_visible_to_own' => true,
        ]);
        $acme->syncComplianceAuthorities([(int) $central->id]);

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
        $centralReviewer->setRelation('firm', $central);
        $power = User::factory()->powerAdmin()->create(['firm_id' => null]);

        $this->assertTrue($visibility->userIsEligibleAssignee($acmeReviewer, $acme));
        $this->assertTrue($visibility->userIsEligibleAssignee($centralReviewer, $acme));
        $this->assertTrue($visibility->userIsEligibleAssignee($power, $acme));
        $this->assertFalse($visibility->userIsEligibleAssignee($otherReviewer, $acme));
        $this->assertFalse($visibility->userIsEligibleAssignee($otherReviewer, null));
        $this->assertTrue($visibility->userIsEligibleAssignee($power, null));
    }
}
