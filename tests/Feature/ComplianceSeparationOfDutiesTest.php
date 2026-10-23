<?php

namespace Tests\Feature;

use App\Models\Firm;
use App\Models\Hub;
use App\Models\SocialMediaComplianceRequest;
use App\Models\SocialMediaComplianceRequestVersion;
use App\Models\TaxonomyAddRequest;
use App\Models\User;
use App\Services\CapabilitiesMatrixService;
use App\Services\HubService;
use App\Services\SocialMediaComplianceService;
use App\Services\TaxonomyAddRequestService;
use App\Services\UserRolesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ComplianceSeparationOfDutiesTest extends TestCase
{
    use RefreshDatabase;

    private function createSharedHubWithSmc(): Hub
    {
        config([
            'hub.current_slug' => 'shared',
            'hub.type' => 'shared',
            'hub.is_control_plane' => false,
        ]);

        $hub = Hub::query()->create([
            'name' => 'Shared Hub',
            'slug' => 'shared',
            'type' => Hub::TYPE_SHARED,
            'is_active' => true,
            'checklist' => array_merge(Hub::defaultChecklist(Hub::TYPE_SHARED), [
                'module_social_media_compliance' => true,
            ]),
        ]);

        $matrix = app(CapabilitiesMatrixService::class);
        $caps = $matrix->resolvedRoleCapabilities($hub);
        foreach ([User::ROLE_APPROVER, User::ROLE_MANAGER, User::ROLE_USER] as $role) {
            $caps[$role]['smc_review_requests'] = true;
            $caps[$role]['smc_assign_requests'] = true;
            $caps[$role]['smc_view_all_requests'] = true;
            $caps[$role]['smc_change_request_status'] = true;
        }
        $hub->role_capabilities = $caps;
        $hub->save();
        app(HubService::class)->forgetCurrentCache();
        $matrix->forgetResolvedCaches();

        return $hub;
    }

    private function createPendingSmc(User $owner, ?User $onBehalf = null): SocialMediaComplianceRequest
    {
        $request = SocialMediaComplianceRequest::query()->create([
            'user_id' => $owner->id,
            'name' => $owner->name,
            'email' => $owner->email,
            'submission_date' => now(),
            'current_version' => 1,
            'on_behalf_by_user_id' => $onBehalf?->id,
        ]);
        SocialMediaComplianceRequestVersion::query()->create([
            'request_id' => $request->id,
            'version_number' => 1,
            'description' => 'Copy under review',
            'submitted_by' => $onBehalf?->id ?? $owner->id,
            'submitted_at' => now(),
            'status' => SocialMediaComplianceRequest::STATUS_PENDING,
            'feedback' => '',
        ]);

        return $request->fresh(['user', 'currentVersionRow']);
    }

    public function test_multi_role_owner_cannot_review_own_smc_request(): void
    {
        $hub = $this->createSharedHubWithSmc();
        $firm = Firm::query()->create([
            'name' => 'Firm A',
            'compliance_visible_to_own' => true,
        ]);

        $dual = User::factory()->create([
            'role' => User::ROLE_USER,
            'firm_id' => $firm->id,
        ]);
        app(UserRolesService::class)->syncRoles(
            $dual,
            [User::ROLE_USER, User::ROLE_APPROVER],
            User::ROLE_USER,
            log: false,
        );

        $request = $this->createPendingSmc($dual);
        $service = app(SocialMediaComplianceService::class);

        try {
            $service->review($hub, $dual->fresh(), $request, [
                'status' => SocialMediaComplianceRequest::STATUS_APPROVED,
                'feedback' => 'Self approve',
            ]);
            $this->fail('Expected ValidationException for self-review');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('separation_of_duties', $e->errors());
        }
    }

    public function test_owner_cannot_be_assigned_as_reviewer(): void
    {
        $hub = $this->createSharedHubWithSmc();
        $firm = Firm::query()->create([
            'name' => 'Firm A',
            'compliance_visible_to_own' => true,
        ]);

        $owner = User::factory()->create([
            'role' => User::ROLE_APPROVER,
            'firm_id' => $firm->id,
        ]);
        app(UserRolesService::class)->syncRoles(
            $owner,
            [User::ROLE_APPROVER],
            User::ROLE_APPROVER,
            log: false,
        );

        $manager = User::factory()->create([
            'role' => User::ROLE_MANAGER,
            'firm_id' => $firm->id,
        ]);
        app(UserRolesService::class)->syncRoles(
            $manager,
            [User::ROLE_MANAGER],
            User::ROLE_MANAGER,
            log: false,
        );

        $request = $this->createPendingSmc($owner);
        $service = app(SocialMediaComplianceService::class);

        try {
            $service->assign($hub, $manager, $request, $owner->id);
            $this->fail('Expected ValidationException for conflicted assignee');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('assigned_to', $e->errors());
        }
    }

    public function test_on_behalf_staff_cannot_review_request_they_submitted(): void
    {
        $hub = $this->createSharedHubWithSmc();
        $firm = Firm::query()->create([
            'name' => 'Firm A',
            'compliance_visible_to_own' => true,
        ]);

        $advisor = User::factory()->create([
            'role' => User::ROLE_USER,
            'firm_id' => $firm->id,
        ]);
        $staff = User::factory()->create([
            'role' => User::ROLE_APPROVER,
            'firm_id' => $firm->id,
        ]);
        app(UserRolesService::class)->syncRoles(
            $staff,
            [User::ROLE_APPROVER],
            User::ROLE_APPROVER,
            log: false,
        );

        $request = $this->createPendingSmc($advisor, $staff);
        $request->update(['assigned_to' => $staff->id]);
        $service = app(SocialMediaComplianceService::class);

        try {
            $service->review($hub, $staff->fresh(), $request->fresh(['user', 'currentVersionRow']), [
                'status' => SocialMediaComplianceRequest::STATUS_APPROVED,
                'feedback' => '',
            ]);
            $this->fail('Expected ValidationException for on-behalf self-review');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('separation_of_duties', $e->errors());
        }
    }

    public function test_reviewers_list_excludes_owner_and_on_behalf(): void
    {
        $hub = $this->createSharedHubWithSmc();
        $firm = Firm::query()->create([
            'name' => 'Firm A',
            'compliance_visible_to_own' => true,
        ]);

        $owner = User::factory()->create([
            'role' => User::ROLE_APPROVER,
            'firm_id' => $firm->id,
            'name' => 'Owner Reviewer',
        ]);
        $staff = User::factory()->create([
            'role' => User::ROLE_APPROVER,
            'firm_id' => $firm->id,
            'name' => 'On Behalf Staff',
        ]);
        $other = User::factory()->create([
            'role' => User::ROLE_APPROVER,
            'firm_id' => $firm->id,
            'name' => 'Other Reviewer',
        ]);
        foreach ([$owner, $staff, $other] as $u) {
            app(UserRolesService::class)->syncRoles(
                $u,
                [User::ROLE_APPROVER],
                User::ROLE_APPROVER,
                log: false,
            );
        }

        $ids = app(SocialMediaComplianceService::class)
            ->reviewersForHub(
                $hub,
                excludeOwnerUserId: (int) $owner->id,
                excludeOnBehalfUserId: (int) $staff->id,
            )
            ->pluck('id')
            ->all();

        $this->assertContains((int) $other->id, $ids);
        $this->assertNotContains((int) $owner->id, $ids);
        $this->assertNotContains((int) $staff->id, $ids);
    }

    public function test_multi_role_user_appears_in_reviewers_via_additional_role(): void
    {
        $hub = $this->createSharedHubWithSmc();
        $firm = Firm::query()->create([
            'name' => 'Firm A',
            'compliance_visible_to_own' => true,
        ]);

        // Primary advisor; secondary approver — must still be listed as a reviewer.
        $dual = User::factory()->create([
            'role' => User::ROLE_USER,
            'firm_id' => $firm->id,
            'name' => 'Dual Role User',
        ]);
        app(UserRolesService::class)->syncRoles(
            $dual,
            [User::ROLE_USER, User::ROLE_APPROVER],
            User::ROLE_USER,
            log: false,
        );

        $ids = app(SocialMediaComplianceService::class)
            ->reviewersForHub($hub)
            ->pluck('id')
            ->all();

        $this->assertContains((int) $dual->id, $ids);
    }

    public function test_all_requests_list_excludes_own_submission_for_dual_role(): void
    {
        $hub = $this->createSharedHubWithSmc();
        $firm = Firm::query()->create([
            'name' => 'Firm A',
            'compliance_visible_to_own' => true,
        ]);

        $dual = User::factory()->create([
            'role' => User::ROLE_USER,
            'firm_id' => $firm->id,
        ]);
        app(UserRolesService::class)->syncRoles(
            $dual,
            [User::ROLE_USER, User::ROLE_APPROVER],
            User::ROLE_USER,
            log: false,
        );

        $other = User::factory()->create([
            'role' => User::ROLE_USER,
            'firm_id' => $firm->id,
        ]);

        $own = $this->createPendingSmc($dual);
        $theirs = $this->createPendingSmc($other);

        $ids = app(SocialMediaComplianceService::class)
            ->listForActor($hub, $dual->fresh(), ['per_page' => 50])
            ->getCollection()
            ->pluck('id')
            ->all();

        $this->assertNotContains((int) $own->id, $ids);
        $this->assertContains((int) $theirs->id, $ids);
    }

    public function test_dual_role_assignee_can_open_request_assigned_to_them(): void
    {
        $this->createSharedHubWithSmc();
        $firm = Firm::query()->create([
            'name' => 'Firm A',
            'compliance_visible_to_own' => true,
        ]);

        // Primary advisor/user; secondary approver — must still open assigned reviews.
        $dual = User::factory()->create([
            'role' => User::ROLE_USER,
            'firm_id' => $firm->id,
        ]);
        app(UserRolesService::class)->syncRoles(
            $dual,
            [User::ROLE_USER, User::ROLE_APPROVER],
            User::ROLE_USER,
            log: false,
        );

        $submitter = User::factory()->create([
            'role' => User::ROLE_USER,
            'firm_id' => $firm->id,
        ]);
        $request = $this->createPendingSmc($submitter);
        $request->update(['assigned_to' => $dual->id]);

        \Laravel\Sanctum\Sanctum::actingAs($dual->fresh());

        $this->getJson('/api/client-admin/social-media-compliance/requests/'.$request->id)
            ->assertOk()
            ->assertJsonPath('data.id', $request->id);
    }

    public function test_taxonomy_owner_cannot_approve_own_request(): void
    {
        config([
            'hub.current_slug' => 'central',
            'hub.type' => 'central',
            'hub.is_control_plane' => true,
        ]);

        $matrix = app(CapabilitiesMatrixService::class);
        $hub = Hub::query()->create([
            'name' => 'Central Hub',
            'slug' => 'central',
            'type' => Hub::TYPE_CENTRAL,
            'is_active' => true,
            'checklist' => Hub::defaultChecklist(Hub::TYPE_CENTRAL),
            'role_capabilities' => $matrix->defaultRoleCapabilities(Hub::TYPE_CENTRAL),
        ]);
        $caps = $matrix->resolvedRoleCapabilities($hub);
        $caps[User::ROLE_POWER_ADMIN]['taxonomy_request_add'] = true;
        $caps[User::ROLE_POWER_ADMIN]['taxonomy_request_manage'] = true;
        $hub->role_capabilities = $caps;
        $hub->save();
        app(HubService::class)->forgetCurrentCache();
        $matrix->forgetResolvedCaches();

        $admin = User::factory()->create(['role' => User::ROLE_POWER_ADMIN]);
        app(UserRolesService::class)->syncRoles(
            $admin,
            [User::ROLE_POWER_ADMIN],
            User::ROLE_POWER_ADMIN,
            log: false,
        );

        $request = TaxonomyAddRequest::query()->create([
            'user_id' => $admin->id,
            'target' => TaxonomyAddRequest::TARGET_CATEGORY,
            'proposed_name' => 'Self Category',
            'remarks' => 'Need this category',
            'status' => TaxonomyAddRequest::STATUS_PENDING,
        ]);

        try {
            app(TaxonomyAddRequestService::class)->approve($hub, $admin, $request, []);
            $this->fail('Expected ValidationException for taxonomy self-approve');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('separation_of_duties', $e->errors());
        }
    }
}
