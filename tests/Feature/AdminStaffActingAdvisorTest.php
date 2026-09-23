<?php

namespace Tests\Feature;

use App\Models\Firm;
use App\Models\Hub;
use App\Models\User;
use App\Services\ActingAdvisorService;
use App\Services\CapabilitiesMatrixService;
use App\Services\HubService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminStaffActingAdvisorTest extends TestCase
{
    use RefreshDatabase;

    private function createSharedHub(): Hub
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

        app(HubService::class)->forgetCurrentCache();

        return $hub;
    }

    private function setRoleCaps(Hub $hub, string $role, array $flags): void
    {
        $matrix = app(CapabilitiesMatrixService::class);
        $caps = $matrix->resolvedRoleCapabilities($hub);
        foreach ($flags as $flag => $enabled) {
            $caps[$role][$flag] = $enabled;
        }
        $hub->role_capabilities = $caps;
        $hub->save();
        app(HubService::class)->forgetCurrentCache();
    }

    public function test_eligible_advisors_are_firm_scoped_and_opted_in(): void
    {
        $this->createSharedHub();
        $firmA = Firm::query()->create(['name' => 'Firm A']);
        $firmB = Firm::query()->create(['name' => 'Firm B']);

        $staff = User::factory()->create([
            'role' => User::ROLE_ADMIN_STAFF,
            'firm_id' => $firmA->id,
        ]);

        $allowed = User::factory()->create([
            'name' => 'Allowed Advisor',
            'role' => User::ROLE_ADVISOR,
            'is_advisor' => true,
            'firm_id' => $firmA->id,
            'allows_admin_staff_acting' => true,
        ]);

        User::factory()->create([
            'name' => 'Denied Advisor',
            'role' => User::ROLE_ADVISOR,
            'is_advisor' => true,
            'firm_id' => $firmA->id,
            'allows_admin_staff_acting' => false,
        ]);

        User::factory()->create([
            'name' => 'Other Firm Advisor',
            'role' => User::ROLE_ADVISOR,
            'is_advisor' => true,
            'firm_id' => $firmB->id,
            'allows_admin_staff_acting' => true,
        ]);

        $service = app(ActingAdvisorService::class);
        $eligible = $service->eligibleAdvisors($staff);

        $this->assertCount(1, $eligible);
        $this->assertSame($allowed->id, $eligible->first()->id);
    }

    public function test_without_selection_staff_keeps_admin_staff_matrix_role(): void
    {
        $hub = $this->createSharedHub();
        $this->setRoleCaps($hub, User::ROLE_ADMIN_STAFF, [
            'gc_submit_request' => true,
            'smc_submit_request' => false,
        ]);
        $this->setRoleCaps($hub, User::ROLE_ADVISOR, [
            'gc_submit_request' => false,
            'smc_submit_request' => true,
        ]);

        $firm = Firm::query()->create(['name' => 'Firm A']);
        $staff = User::factory()->create([
            'role' => User::ROLE_ADMIN_STAFF,
            'firm_id' => $firm->id,
        ]);

        $matrix = app(CapabilitiesMatrixService::class);
        $this->assertTrue($matrix->userCan($hub, $staff, 'gc_submit_request'));
        $this->assertFalse($matrix->userCan($hub, $staff, 'smc_submit_request'));
        $this->assertSame(User::ROLE_ADMIN_STAFF, app(ActingAdvisorService::class)->effectiveCapabilityRole($staff));
    }

    public function test_with_selection_staff_mirrors_advisor_matrix_and_attribution(): void
    {
        $hub = $this->createSharedHub();
        $this->setRoleCaps($hub, User::ROLE_ADMIN_STAFF, [
            'gc_submit_request' => true,
            'smc_submit_request' => false,
        ]);
        $this->setRoleCaps($hub, User::ROLE_ADVISOR, [
            'gc_submit_request' => false,
            'smc_submit_request' => true,
        ]);

        $firm = Firm::query()->create(['name' => 'Firm A']);
        $staff = User::factory()->create([
            'name' => 'Pat Staff',
            'role' => User::ROLE_ADMIN_STAFF,
            'firm_id' => $firm->id,
        ]);
        $advisor = User::factory()->create([
            'name' => 'Alex Advisor',
            'role' => User::ROLE_ADVISOR,
            'is_advisor' => true,
            'firm_id' => $firm->id,
            'allows_admin_staff_acting' => true,
        ]);

        $service = app(ActingAdvisorService::class);
        $service->setActingAdvisor($staff, $advisor->id);
        $staff->refresh();

        $matrix = app(CapabilitiesMatrixService::class);
        $this->assertSame(User::ROLE_ADVISOR, $service->effectiveCapabilityRole($staff));
        $this->assertFalse($matrix->userCan($hub, $staff, 'gc_submit_request'));
        $this->assertTrue($matrix->userCan($hub, $staff, 'smc_submit_request'));

        $subject = $service->requireSubject($staff);
        $this->assertSame($advisor->id, $subject->id);
        $this->assertSame($staff->id, $service->onBehalfById($staff, $subject));

        $label = ActingAdvisorService::attributionLabel($advisor->name, $staff->name);
        $this->assertSame('Pat Staff submitted on behalf of Alex Advisor', $label);
    }

    public function test_acting_advisor_api_and_hub_payload(): void
    {
        $hub = $this->createSharedHub();
        $this->setRoleCaps($hub, User::ROLE_ADVISOR, [
            'smc_submit_request' => true,
        ]);
        $this->setRoleCaps($hub, User::ROLE_ADMIN_STAFF, [
            'smc_submit_request' => false,
        ]);

        $firm = Firm::query()->create(['name' => 'Firm A']);
        $staff = User::factory()->create([
            'role' => User::ROLE_ADMIN_STAFF,
            'firm_id' => $firm->id,
        ]);
        $advisor = User::factory()->create([
            'role' => User::ROLE_ADVISOR,
            'is_advisor' => true,
            'firm_id' => $firm->id,
            'allows_admin_staff_acting' => true,
        ]);

        Sanctum::actingAs($staff);

        $this->getJson('/api/acting-advisor')
            ->assertOk()
            ->assertJsonPath('acting_advisor_switcher.enabled', true)
            ->assertJsonPath('acting_advisor_switcher.acting_advisor', null)
            ->assertJsonCount(1, 'acting_advisor_switcher.advisors');

        $this->putJson('/api/acting-advisor', ['advisor_id' => $advisor->id])
            ->assertOk()
            ->assertJsonPath('acting_advisor_switcher.acting_advisor.id', $advisor->id)
            ->assertJsonPath('acting_advisor_switcher.effective_role', User::ROLE_ADVISOR);

        $hubPayload = $this->getJson('/api/hub')->assertOk();
        $this->assertSame(User::ROLE_ADVISOR, $hubPayload->json('hub.effective_role'));
        $this->assertTrue((bool) $hubPayload->json('hub.effective_capabilities.smc_submit_request'));
    }

    public function test_user_create_persists_allows_admin_staff_acting_for_advisors(): void
    {
        $this->createSharedHub();
        $firm = Firm::query()->create(['name' => 'Firm A']);
        $admin = User::factory()->powerAdmin()->create();
        Sanctum::actingAs($admin);

        $create = $this->postJson('/api/power-admin/users', [
            'name' => 'Opt-in Advisor',
            'email' => 'optin@example.com',
            'password' => 'password123',
            'role' => User::ROLE_ADVISOR,
            'firm_id' => $firm->id,
            'allows_admin_staff_acting' => true,
        ]);

        $create->assertCreated();
        $userId = (int) $create->json('user.id');
        $this->assertTrue((bool) User::query()->find($userId)?->allows_admin_staff_acting);
    }
}
