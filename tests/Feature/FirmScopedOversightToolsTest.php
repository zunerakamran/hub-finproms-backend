<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\ComplianceAuditEvent;
use App\Models\Firm;
use App\Models\Hub;
use App\Models\SocialMediaComplianceRequest;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\ComplianceAuditTrailService;
use App\Services\FirmComplianceVisibilityService;
use App\Services\HubService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FirmScopedOversightToolsTest extends TestCase
{
    use RefreshDatabase;

    private function sharedHub(): Hub
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

    public function test_visible_firm_ids_include_own_and_delegated_subjects(): void
    {
        $central = Firm::central();
        $firm1 = Firm::query()->create(['name' => 'Firm 1']);
        $firm2 = Firm::query()->create(['name' => 'Firm 2']);
        $firm3 = Firm::query()->create(['name' => 'Firm 3']);

        // Firm1 delegates to Firm2 (and Central).
        $firm1->syncComplianceAuthorities([(int) $central->id, (int) $firm2->id]);

        $auditor2 = User::factory()->create([
            'role' => User::ROLE_AUDITOR,
            'firm_id' => $firm2->id,
        ]);
        $auditor2->setRelation('firm', $firm2);

        $visibility = app(FirmComplianceVisibilityService::class);
        $ids = $visibility->visibleFirmIdsForActor($auditor2);

        $this->assertEqualsCanonicalizing([(int) $firm2->id, (int) $firm1->id], $ids);
        $this->assertNotContains((int) $firm3->id, $ids);

        $centralAuditor = User::factory()->create([
            'role' => User::ROLE_AUDITOR,
            'firm_id' => $central->id,
        ]);
        $centralAuditor->setRelation('firm', $central);
        $this->assertNull($visibility->visibleFirmIdsForActor($centralAuditor));
    }

    public function test_activity_logs_are_firm_scoped_for_member_auditor(): void
    {
        $hub = $this->sharedHub();
        $firm1 = Firm::query()->create(['name' => 'Firm 1']);
        $firm2 = Firm::query()->create(['name' => 'Firm 2']);

        $user1 = User::factory()->create(['role' => User::ROLE_USER, 'firm_id' => $firm1->id]);
        $user2 = User::factory()->create(['role' => User::ROLE_USER, 'firm_id' => $firm2->id]);
        $auditor1 = User::factory()->create(['role' => User::ROLE_AUDITOR, 'firm_id' => $firm1->id]);
        $auditor1->setRelation('firm', $firm1);

        ActivityLog::query()->create([
            'hub_id' => $hub->id,
            'user_id' => $user1->id,
            'user_name' => $user1->name,
            'user_email' => $user1->email,
            'user_role' => $user1->role,
            'action' => 'test.firm1',
            'created_at' => now(),
        ]);
        ActivityLog::query()->create([
            'hub_id' => $hub->id,
            'user_id' => $user2->id,
            'user_name' => $user2->name,
            'user_email' => $user2->email,
            'user_role' => $user2->role,
            'action' => 'test.firm2',
            'created_at' => now(),
        ]);

        $service = app(ActivityLogService::class);
        $listed = $service->list($hub, ['per_page' => 50], $auditor1);
        $actions = collect($listed->items())->pluck('action')->all();

        $this->assertContains('test.firm1', $actions);
        $this->assertNotContains('test.firm2', $actions);

        // After Firm2 delegates to Firm1, Firm1 auditor sees Firm2 logs too.
        $firm2->syncComplianceAuthorities([(int) $firm1->id]);
        $listed2 = $service->list($hub, ['per_page' => 50], $auditor1);
        $actions2 = collect($listed2->items())->pluck('action')->all();
        $this->assertContains('test.firm1', $actions2);
        $this->assertContains('test.firm2', $actions2);
    }

    public function test_audit_trail_page_is_firm_scoped(): void
    {
        $hub = $this->sharedHub();
        $firm1 = Firm::query()->create(['name' => 'Firm 1']);
        $firm2 = Firm::query()->create(['name' => 'Firm 2']);

        $user1 = User::factory()->create(['role' => User::ROLE_USER, 'firm_id' => $firm1->id]);
        $user2 = User::factory()->create(['role' => User::ROLE_USER, 'firm_id' => $firm2->id]);
        $auditor1 = User::factory()->create(['role' => User::ROLE_AUDITOR, 'firm_id' => $firm1->id]);
        $auditor1->setRelation('firm', $firm1);

        $req1 = SocialMediaComplianceRequest::query()->create([
            'user_id' => $user1->id,
            'name' => 'Firm1 request',
            'post_id' => null,
            'current_version' => 1,
            'submission_date' => now()->toDateString(),
        ]);
        $req2 = SocialMediaComplianceRequest::query()->create([
            'user_id' => $user2->id,
            'name' => 'Firm2 request',
            'post_id' => null,
            'current_version' => 1,
            'submission_date' => now()->toDateString(),
        ]);

        ComplianceAuditEvent::query()->create([
            'hub_id' => $hub->id,
            'module' => ComplianceAuditEvent::MODULE_SMC,
            'subject_type' => SocialMediaComplianceRequest::class,
            'subject_id' => $req1->id,
            'event_type' => ComplianceAuditEvent::EVENT_SUBMITTED,
            'description' => 'Firm1 submit',
            'created_at' => now(),
        ]);
        ComplianceAuditEvent::query()->create([
            'hub_id' => $hub->id,
            'module' => ComplianceAuditEvent::MODULE_SMC,
            'subject_type' => SocialMediaComplianceRequest::class,
            'subject_id' => $req2->id,
            'event_type' => ComplianceAuditEvent::EVENT_SUBMITTED,
            'description' => 'Firm2 submit',
            'created_at' => now(),
        ]);

        $trail = app(ComplianceAuditTrailService::class);
        $page = $trail->paginateForHub(ComplianceAuditEvent::MODULE_SMC, $hub, [
            'per_page' => 50,
        ], $auditor1);

        $descriptions = collect($page['events'])->pluck('description')->all();
        $this->assertContains('Firm1 submit', $descriptions);
        $this->assertNotContains('Firm2 submit', $descriptions);
    }
}
