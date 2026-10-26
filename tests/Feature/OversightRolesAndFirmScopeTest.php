<?php

namespace Tests\Feature;

use App\Models\Firm;
use App\Models\Hub;
use App\Models\User;
use App\Services\CapabilitiesMatrixService;
use App\Services\FirmComplianceVisibilityService;
use App\Services\HubService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OversightRolesAndFirmScopeTest extends TestCase
{
    use RefreshDatabase;

    private function sharedHub(): Hub
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
            'checklist' => Hub::defaultChecklist(Hub::TYPE_SHARED),
        ]);
        app(HubService::class)->forgetCurrentCache();
        app(CapabilitiesMatrixService::class)->forgetResolvedCaches();

        return $hub;
    }

    public function test_default_capabilities_for_oversight_roles(): void
    {
        $hub = $this->sharedHub();
        $defaults = app(CapabilitiesMatrixService::class)->defaultRoleCapabilities($hub->type);

        $this->assertTrue($defaults[User::ROLE_TOP_MANAGEMENT]['smc_view_reports'] ?? false);
        $this->assertTrue($defaults[User::ROLE_TOP_MANAGEMENT]['gc_view_reports'] ?? false);
        $this->assertTrue($defaults[User::ROLE_TOP_MANAGEMENT]['wc_view_platform_report'] ?? false);

        $this->assertTrue($defaults[User::ROLE_AUDITOR]['dashboard_view_activity_logs'] ?? false);
        $this->assertTrue($defaults[User::ROLE_AUDITOR]['dashboard_view_compliance_audit_trail'] ?? false);

        $this->assertTrue($defaults[User::ROLE_ACCOUNTS_ADMIN]['dashboard_view_one_time_invoices'] ?? false);
        $this->assertTrue($defaults[User::ROLE_ACCOUNTS_ADMIN]['dashboard_bank_transfers'] ?? false);
        $this->assertTrue($defaults[User::ROLE_ACCOUNTS_ADMIN]['dashboard_view_module_invoices'] ?? false);
        $this->assertTrue($defaults[User::ROLE_ACCOUNTS_ADMIN]['dashboard_manage_payment_card'] ?? false);

        $this->assertTrue($defaults[User::ROLE_NETWORK_ADMIN]['dashboard_manage_payment_card'] ?? false);
        $this->assertTrue($defaults[User::ROLE_NETWORK_ADMIN]['dashboard_view_all_module_invoices'] ?? false);
        $this->assertTrue($defaults[User::ROLE_FIRM_ADMIN]['dashboard_manage_payment_card'] ?? false);
        $this->assertTrue($defaults[User::ROLE_FIRM_ADMIN]['dashboard_view_module_invoices'] ?? false);
        $this->assertFalse($defaults[User::ROLE_FIRM_ADMIN]['dashboard_view_all_module_invoices'] ?? true);
    }

    public function test_member_firm_top_management_stays_firm_scoped(): void
    {
        $this->sharedHub();
        $firm = Firm::query()->create(['name' => 'Member Co']);
        $user = User::factory()->create([
            'firm_id' => $firm->id,
            'role' => User::ROLE_TOP_MANAGEMENT,
        ]);
        app(\App\Services\UserRolesService::class)->syncRoles(
            $user,
            [User::ROLE_TOP_MANAGEMENT],
            log: false,
            syncFirmHead: false,
        );

        $this->assertFalse(app(FirmComplianceVisibilityService::class)->actorBypassesFirmScope($user->fresh()));
    }

    public function test_central_firm_top_management_bypasses_firm_scope(): void
    {
        $this->sharedHub();
        $central = Firm::central();
        $user = User::factory()->create([
            'firm_id' => $central->id,
            'role' => User::ROLE_USER,
        ]);
        app(\App\Services\UserRolesService::class)->syncRoles(
            $user,
            [User::ROLE_USER, User::ROLE_TOP_MANAGEMENT],
            log: false,
            syncFirmHead: false,
        );

        $this->assertTrue(app(FirmComplianceVisibilityService::class)->actorBypassesFirmScope($user->fresh()));
    }

    public function test_central_firm_without_oversight_role_does_not_bypass(): void
    {
        $this->sharedHub();
        $central = Firm::central();
        $user = User::factory()->create([
            'firm_id' => $central->id,
            'role' => User::ROLE_MANAGER,
        ]);
        app(\App\Services\UserRolesService::class)->syncRoles(
            $user,
            [User::ROLE_MANAGER],
            log: false,
            syncFirmHead: false,
        );

        $this->assertFalse(app(FirmComplianceVisibilityService::class)->actorBypassesFirmScope($user->fresh()));
    }
}
