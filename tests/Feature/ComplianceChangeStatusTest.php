<?php

namespace Tests\Feature;

use App\Models\Firm;
use App\Models\GeneralComplianceRequest;
use App\Models\GeneralComplianceRequestVersion;
use App\Models\Hub;
use App\Models\SocialMediaComplianceRequest;
use App\Models\SocialMediaComplianceRequestVersion;
use App\Models\User;
use App\Models\WebsiteCompliance\ChangeRequest;
use App\Models\WebsiteCompliance\ChangeRequestVersion;
use App\Services\CapabilitiesMatrixService;
use App\Services\FirmComplianceVisibilityService;
use App\Services\GeneralComplianceService;
use App\Services\HubService;
use App\Services\SocialMediaComplianceService;
use App\Services\WebsiteCompliance\ChangeRequestWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ComplianceChangeStatusTest extends TestCase
{
    use RefreshDatabase;

    private function createSharedHubWithModules(): Hub
    {
        $hub = Hub::query()->create([
            'name' => 'Shared Hub',
            'slug' => 'shared',
            'type' => Hub::TYPE_SHARED,
            'is_active' => true,
            'checklist' => array_merge(Hub::defaultChecklist(Hub::TYPE_SHARED), [
                'module_social_media_compliance' => true,
                'module_general_compliance' => true,
                'module_website_compliance' => true,
            ]),
        ]);

        $matrix = app(CapabilitiesMatrixService::class);
        $caps = $matrix->resolvedRoleCapabilities($hub);
        foreach ([User::ROLE_MANAGER, User::ROLE_POWER_ADMIN] as $role) {
            $caps[$role]['smc_change_request_status'] = true;
            $caps[$role]['gc_change_request_status'] = true;
            $caps[$role]['wc_change_request_status'] = true;
            $caps[$role]['smc_view_all_requests'] = true;
            $caps[$role]['gc_view_all_requests'] = true;
            $caps[$role]['wc_view_all_change_requests'] = true;
        }
        $hub->role_capabilities = $caps;
        $hub->save();
        app(HubService::class)->forgetCurrentCache();

        return $hub;
    }

    public function test_capability_keys_are_registered_in_matrix(): void
    {
        $this->assertContains('smc_change_request_status', Hub::SOCIAL_MEDIA_COMPLIANCE_CAPABILITY_KEYS);
        $this->assertContains('gc_change_request_status', Hub::GENERAL_COMPLIANCE_CAPABILITY_KEYS);
        $this->assertContains('wc_change_request_status', Hub::WEBSITE_COMPLIANCE_CAPABILITY_KEYS);
        $this->assertArrayHasKey('smc_change_request_status', Hub::CHECKLIST_DEFINITIONS);
        $this->assertArrayHasKey('gc_change_request_status', Hub::CHECKLIST_DEFINITIONS);
        $this->assertArrayHasKey('wc_change_request_status', Hub::CHECKLIST_DEFINITIONS);
    }

    public function test_smc_change_status_creates_new_version_with_comment(): void
    {
        $hub = $this->createSharedHubWithModules();
        $firm = Firm::query()->create([
            'name' => 'Firm X',
            'compliance_visible_to_own' => true,
        ]);

        $submitter = User::factory()->create([
            'role' => User::ROLE_USER,
            'firm_id' => $firm->id,
        ]);
        $manager = User::factory()->create([
            'role' => User::ROLE_MANAGER,
            'firm_id' => $firm->id,
        ]);

        $request = SocialMediaComplianceRequest::query()->create([
            'user_id' => $submitter->id,
            'name' => $submitter->name,
            'email' => $submitter->email,
            'submission_date' => now(),
            'current_version' => 1,
        ]);
        SocialMediaComplianceRequestVersion::query()->create([
            'request_id' => $request->id,
            'version_number' => 1,
            'description' => 'Original copy',
            'submitted_by' => $submitter->id,
            'submitted_at' => now(),
            'status' => SocialMediaComplianceRequest::STATUS_APPROVED,
            'feedback' => '',
        ]);

        $updated = app(SocialMediaComplianceService::class)->changeStatus(
            $hub,
            $manager,
            $request->fresh(['user', 'currentVersionRow']),
            [
                'status' => SocialMediaComplianceRequest::STATUS_REJECTED,
                'comment' => 'Reopened after policy change',
            ]
        );

        $this->assertSame(2, (int) $updated->current_version);
        $this->assertSame(SocialMediaComplianceRequest::STATUS_REJECTED, $updated->currentVersionRow->status);
        $this->assertSame('Reopened after policy change', $updated->currentVersionRow->feedback);
        $this->assertSame('Original copy', $updated->currentVersionRow->description);
        $this->assertSame($manager->name, $updated->currentVersionRow->reviewed_by);
    }

    public function test_gc_change_status_copies_description_into_new_version(): void
    {
        $hub = $this->createSharedHubWithModules();
        $firm = Firm::query()->create([
            'name' => 'Firm X',
            'compliance_visible_to_own' => true,
        ]);

        $submitter = User::factory()->create([
            'role' => User::ROLE_USER,
            'firm_id' => $firm->id,
        ]);
        $manager = User::factory()->create([
            'role' => User::ROLE_MANAGER,
            'firm_id' => $firm->id,
        ]);

        $request = GeneralComplianceRequest::query()->create([
            'user_id' => $submitter->id,
            'name' => $submitter->name,
            'email' => $submitter->email,
            'submission_date' => now(),
            'current_version' => 1,
        ]);
        GeneralComplianceRequestVersion::query()->create([
            'request_id' => $request->id,
            'version_number' => 1,
            'description' => 'GC body',
            'submitted_by' => $submitter->id,
            'submitted_at' => now(),
            'status' => GeneralComplianceRequest::STATUS_PENDING,
            'feedback' => '',
        ]);

        $updated = app(GeneralComplianceService::class)->changeStatus(
            $hub,
            $manager,
            $request->fresh(['user', 'currentVersionRow']),
            [
                'status' => GeneralComplianceRequest::STATUS_APPROVED,
                'comment' => 'Manager override',
            ]
        );

        $this->assertSame(2, (int) $updated->current_version);
        $this->assertSame(GeneralComplianceRequest::STATUS_APPROVED, $updated->currentVersionRow->status);
        $this->assertSame('Manager override', $updated->currentVersionRow->feedback);
        $this->assertSame('GC body', $updated->currentVersionRow->description);
    }

    public function test_wc_change_status_blocked_when_published_or_scheduled(): void
    {
        $this->createSharedHubWithModules();
        $firm = Firm::query()->create([
            'name' => 'Firm X',
            'compliance_visible_to_own' => true,
        ]);
        $editor = User::factory()->create([
            'role' => User::ROLE_ADVISOR,
            'firm_id' => $firm->id,
        ]);
        $manager = User::factory()->create([
            'role' => User::ROLE_MANAGER,
            'firm_id' => $firm->id,
        ]);

        $published = ChangeRequest::query()->create([
            'editor_id' => $editor->id,
            'proposed_content' => json_encode([['section_id' => 1, 'proposed_content' => 'x']]),
            'status' => ChangeRequest::STATUS_APPROVED,
            'current_version' => 1,
        ]);
        ChangeRequestVersion::query()->create([
            'request_id' => $published->id,
            'version_number' => 1,
            'proposed_content' => $published->proposed_content,
            'status' => ChangeRequest::STATUS_APPROVED,
            'submitted_by' => $editor->id,
            'submitted_at' => now(),
        ]);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(ChangeRequestWorkflowService::class)->changeStatus(
            $published->fresh(['editor', 'currentVersionRow']),
            $manager,
            ['status' => ChangeRequest::STATUS_REJECTED, 'comment' => 'nope']
        );
    }

    public function test_wc_change_status_creates_version_when_not_locked(): void
    {
        $this->createSharedHubWithModules();
        $firm = Firm::query()->create([
            'name' => 'Firm X',
            'compliance_visible_to_own' => true,
        ]);
        $editor = User::factory()->create([
            'role' => User::ROLE_ADVISOR,
            'firm_id' => $firm->id,
        ]);
        $manager = User::factory()->create([
            'role' => User::ROLE_MANAGER,
            'firm_id' => $firm->id,
        ]);

        $pending = ChangeRequest::query()->create([
            'editor_id' => $editor->id,
            'proposed_content' => json_encode([['section_id' => 1, 'proposed_content' => 'hello']]),
            'status' => ChangeRequest::STATUS_PENDING,
            'current_version' => 1,
        ]);
        ChangeRequestVersion::query()->create([
            'request_id' => $pending->id,
            'version_number' => 1,
            'proposed_content' => $pending->proposed_content,
            'status' => ChangeRequest::STATUS_PENDING,
            'submitted_by' => $editor->id,
            'submitted_at' => now(),
        ]);

        $updated = app(ChangeRequestWorkflowService::class)->changeStatus(
            $pending->fresh(['editor', 'currentVersionRow', 'section']),
            $manager,
            [
                'status' => ChangeRequest::STATUS_REJECTED,
                'comment' => 'Needs rewrite',
            ]
        );

        $this->assertSame(2, (int) $updated->current_version);
        $this->assertSame(ChangeRequest::STATUS_REJECTED, $updated->status);
        $this->assertSame(ChangeRequest::STATUS_REJECTED, $updated->currentVersionRow->status);
        $this->assertSame('Needs rewrite', $updated->currentVersionRow->feedback);
    }

    public function test_manager_cannot_change_status_for_other_firm(): void
    {
        $hub = $this->createSharedHubWithModules();
        $firmX = Firm::query()->create([
            'name' => 'Firm X',
            'compliance_visible_to_own' => true,
        ]);
        $firmY = Firm::query()->create([
            'name' => 'Firm Y',
            'compliance_visible_to_own' => true,
        ]);

        $submitter = User::factory()->create([
            'role' => User::ROLE_USER,
            'firm_id' => $firmY->id,
        ]);
        $manager = User::factory()->create([
            'role' => User::ROLE_MANAGER,
            'firm_id' => $firmX->id,
        ]);

        $request = SocialMediaComplianceRequest::query()->create([
            'user_id' => $submitter->id,
            'name' => $submitter->name,
            'email' => $submitter->email,
            'submission_date' => now(),
            'current_version' => 1,
        ]);
        SocialMediaComplianceRequestVersion::query()->create([
            'request_id' => $request->id,
            'version_number' => 1,
            'description' => 'Y firm request',
            'submitted_by' => $submitter->id,
            'submitted_at' => now(),
            'status' => SocialMediaComplianceRequest::STATUS_PENDING,
            'feedback' => '',
        ]);

        $this->assertFalse(
            app(FirmComplianceVisibilityService::class)->actorCanViewRequest(
                $manager,
                $submitter->id,
                $firmY->id,
                null
            )
        );

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(SocialMediaComplianceService::class)->changeStatus(
            $hub,
            $manager,
            $request->fresh(['user', 'currentVersionRow']),
            ['status' => SocialMediaComplianceRequest::STATUS_APPROVED, 'comment' => 'cross firm']
        );
    }
}
