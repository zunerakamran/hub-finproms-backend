<?php

namespace Tests\Feature;

use App\Models\ComplianceAuditEvent;
use App\Models\Firm;
use App\Models\Hub;
use App\Models\SocialMediaComplianceRequest;
use App\Models\SocialMediaComplianceRequestVersion;
use App\Models\User;
use App\Services\CapabilitiesMatrixService;
use App\Services\ComplianceAuditTrailService;
use App\Services\HubService;
use App\Services\SocialMediaComplianceService;
use App\Support\WebsiteCompliance\WcDatabaseContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ComplianceAuditTrailTest extends TestCase
{
    use RefreshDatabase;

    private function createSharedHubWithSmc(): Hub
    {
        $hub = Hub::query()->create([
            'name' => 'Shared Hub',
            'slug' => 'shared-audit',
            'type' => Hub::TYPE_SHARED,
            'is_active' => true,
            'checklist' => array_merge(Hub::defaultChecklist(Hub::TYPE_SHARED), [
                'module_social_media_compliance' => true,
            ]),
        ]);

        $matrix = app(CapabilitiesMatrixService::class);
        $caps = $matrix->resolvedRoleCapabilities($hub);
        foreach ([User::ROLE_MANAGER, User::ROLE_APPROVER, User::ROLE_USER] as $role) {
            $caps[$role]['smc_submit_request'] = true;
            $caps[$role]['smc_view_own_requests'] = true;
            $caps[$role]['smc_view_all_requests'] = true;
            $caps[$role]['smc_assign_requests'] = true;
            $caps[$role]['smc_review_requests'] = true;
            $caps[$role]['smc_view_reports'] = true;
        }
        $hub->role_capabilities = $caps;
        $hub->save();
        app(HubService::class)->forgetCurrentCache();

        return $hub;
    }

    public function test_smc_lifecycle_writes_full_audit_trail_with_actor_identity(): void
    {
        Storage::fake('public');
        $hub = $this->createSharedHubWithSmc();
        $firm = Firm::query()->create([
            'name' => 'Firm Audit',
            'compliance_visible_to_own' => true,
        ]);

        $submitter = User::factory()->create([
            'role' => User::ROLE_USER,
            'firm_id' => $firm->id,
            'name' => 'Advisor Ada',
            'email' => 'ada@example.com',
        ]);
        $approver = User::factory()->create([
            'role' => User::ROLE_APPROVER,
            'firm_id' => $firm->id,
            'name' => 'Approver Pat',
            'email' => 'pat@example.com',
        ]);
        $manager = User::factory()->create([
            'role' => User::ROLE_MANAGER,
            'firm_id' => $firm->id,
            'name' => 'Manager Mo',
            'email' => 'mo@example.com',
        ]);

        $service = app(SocialMediaComplianceService::class);

        $compliance = $service->submit($hub, $submitter, [
            'description' => 'Post for compliance review',
            'attachment' => UploadedFile::fake()->image('post.jpg'),
        ]);

        $service->assign($hub, $manager, $compliance, (int) $approver->id);
        $service->review($hub, $approver, $compliance->fresh(), [
            'status' => SocialMediaComplianceRequest::STATUS_APPROVED,
            'feedback' => 'Looks good',
        ]);

        $trail = app(ComplianceAuditTrailService::class)->forSubject(
            ComplianceAuditEvent::MODULE_SMC,
            (int) $compliance->id
        );

        $this->assertCount(3, $trail);
        $this->assertSame(ComplianceAuditEvent::EVENT_SUBMITTED, $trail[0]['event_type']);
        $this->assertSame('Advisor Ada', $trail[0]['actor']['name']);
        $this->assertSame('ada@example.com', $trail[0]['actor']['email']);
        $this->assertSame(User::ROLE_USER, $trail[0]['actor']['role']);

        $this->assertSame(ComplianceAuditEvent::EVENT_ASSIGNED, $trail[1]['event_type']);
        $this->assertSame('Manager Mo', $trail[1]['actor']['name']);
        $this->assertSame('mo@example.com', $trail[1]['actor']['email']);
        $this->assertSame(User::ROLE_MANAGER, $trail[1]['actor']['role']);
        $this->assertSame('Approver Pat', $trail[1]['related_user']['name']);
        $this->assertSame('pat@example.com', $trail[1]['related_user']['email']);

        $this->assertSame(ComplianceAuditEvent::EVENT_REVIEWED, $trail[2]['event_type']);
        $this->assertSame(SocialMediaComplianceRequest::STATUS_PENDING, $trail[2]['from_status']);
        $this->assertSame(SocialMediaComplianceRequest::STATUS_APPROVED, $trail[2]['to_status']);
        $this->assertSame('Approver Pat', $trail[2]['actor']['name']);
        $this->assertSame(User::ROLE_APPROVER, $trail[2]['actor']['role']);

        $report = $service->report($hub, [], $manager);
        $this->assertNotEmpty($report['rows']);
        $row = collect($report['rows'])->firstWhere('id', $compliance->id);
        $this->assertNotNull($row);
        $this->assertCount(3, $row['audit_trail']);
        $this->assertSame('Manager Mo', $row['assigned_by']);
        $this->assertSame('mo@example.com', $row['assigned_by_email']);
        $this->assertSame(User::ROLE_MANAGER, $row['assigned_by_role']);
        $this->assertNotEmpty($row['audit_trail_summary']);

        $detail = $compliance->fresh()->toApiArray(includeVersions: true);
        $this->assertArrayHasKey('audit_trail', $detail);
        $this->assertCount(3, $detail['audit_trail']);
    }

    public function test_existing_version_without_audit_events_still_loads_empty_trail(): void
    {
        $hub = $this->createSharedHubWithSmc();
        $user = User::factory()->create(['role' => User::ROLE_USER]);

        $request = SocialMediaComplianceRequest::query()->create([
            'user_id' => $user->id,
            'name' => $user->name,
            'current_version' => 1,
            'submission_date' => now(),
        ]);
        SocialMediaComplianceRequestVersion::query()->create([
            'request_id' => $request->id,
            'version_number' => 1,
            'description' => 'Legacy',
            'submitted_by' => $user->id,
            'submitted_at' => now(),
            'status' => SocialMediaComplianceRequest::STATUS_PENDING,
            'feedback' => '',
        ]);

        $trail = app(ComplianceAuditTrailService::class)->forSubject(
            ComplianceAuditEvent::MODULE_SMC,
            (int) $request->id
        );
        $this->assertSame([], $trail);
        $this->assertSame([], $request->toApiArray(includeVersions: true)['audit_trail']);
    }

    public function test_audit_event_model_follows_acting_hub_wc_database_context(): void
    {
        $model = new ComplianceAuditEvent;
        $this->assertNull($model->getConnectionName());

        WcDatabaseContext::using('hub_remote_99', function () {
            $remounted = new ComplianceAuditEvent;
            $this->assertSame('hub_remote_99', $remounted->getConnectionName());
        });

        $this->assertNull((new ComplianceAuditEvent)->getConnectionName());
    }

    public function test_report_audit_events_include_rows_even_when_hub_id_mismatches_registry(): void
    {
        $hub = $this->createSharedHubWithSmc();
        $user = User::factory()->create(['role' => User::ROLE_MANAGER, 'name' => 'Mgr']);

        $request = SocialMediaComplianceRequest::query()->create([
            'user_id' => $user->id,
            'name' => $user->name,
            'current_version' => 1,
            'submission_date' => now(),
        ]);
        SocialMediaComplianceRequestVersion::query()->create([
            'request_id' => $request->id,
            'version_number' => 1,
            'description' => 'Body',
            'submitted_by' => $user->id,
            'submitted_at' => now(),
            'status' => SocialMediaComplianceRequest::STATUS_PENDING,
            'feedback' => '',
        ]);

        // Simulate content-hub local hubs.id differing from Central registry id.
        ComplianceAuditEvent::query()->create([
            'hub_id' => 9999,
            'module' => ComplianceAuditEvent::MODULE_SMC,
            'subject_type' => SocialMediaComplianceRequest::class,
            'subject_id' => $request->id,
            'event_type' => ComplianceAuditEvent::EVENT_SUBMITTED,
            'description' => 'Submitted',
            'actor_user_id' => $user->id,
            'actor_name' => $user->name,
            'actor_email' => $user->email,
            'actor_role' => $user->role,
            'created_at' => now(),
        ]);

        $report = app(SocialMediaComplianceService::class)->report($hub, [], $user);
        $this->assertNotEmpty($report['audit_events']);
        $this->assertSame(ComplianceAuditEvent::EVENT_SUBMITTED, $report['audit_events'][0]['event_type']);
    }
}
