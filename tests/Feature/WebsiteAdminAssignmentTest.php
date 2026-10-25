<?php

namespace Tests\Feature;

use App\Models\Firm;
use App\Models\Hub;
use App\Models\User;
use App\Models\WebsiteCompliance\TemplateRequest;
use App\Services\CapabilitiesMatrixService;
use App\Services\HubService;
use App\Services\UserRolesService;
use App\Support\TermsAndConditionsDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WebsiteAdminAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private Hub $hub;

    private Firm $firm;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.website_compliance.hub_showcase_templates' => [
                'shared' => ['template4'],
            ],
        ]);

        $this->hub = Hub::query()->create([
            'name' => 'Shared Hub',
            'slug' => 'shared',
            'type' => Hub::TYPE_SHARED,
            'is_active' => true,
            'checklist' => array_merge(Hub::defaultChecklist(Hub::TYPE_SHARED), [
                'module_website_compliance' => true,
                'module_website_template_library' => true,
                'wc_request_deployments' => true,
                'wc_assign_website_templates' => true,
            ]),
            'terms_and_conditions' => [
                'content' => TermsAndConditionsDefaults::shared(),
                'version' => 1,
                'updated_at' => now()->toIso8601String(),
            ],
        ]);

        $matrix = app(CapabilitiesMatrixService::class);
        $caps = $matrix->defaultRoleCapabilities(Hub::TYPE_SHARED);
        $this->hub->role_capabilities = $caps;
        $this->hub->save();
        app(HubService::class)->forgetCurrentCache();

        $this->firm = Firm::query()->create([
            'name' => 'Acme Wealth',
            'is_central' => false,
            'compliance_visible_to_own' => true,
        ]);
    }

    private function syncRoles(User $user, array $roles): void
    {
        app(UserRolesService::class)->syncRoles(
            $user,
            $roles,
            log: false,
            syncFirmHead: false,
        );
        $user->refresh();
    }

    public function test_website_admins_endpoint_lists_role_holders_for_firm_admin(): void
    {
        $firmAdmin = User::factory()->create([
            'role' => User::ROLE_FIRM_ADMIN,
            'firm_id' => $this->firm->id,
        ]);
        $this->syncRoles($firmAdmin, [User::ROLE_FIRM_ADMIN]);

        $inFirm = User::factory()->create([
            'role' => User::ROLE_WEBSITE_ADMIN,
            'firm_id' => $this->firm->id,
            'name' => 'Site Editor One',
        ]);
        $this->syncRoles($inFirm, [User::ROLE_WEBSITE_ADMIN]);

        $otherFirm = Firm::query()->create([
            'name' => 'Other Firm',
            'compliance_visible_to_own' => true,
        ]);
        $outOfScope = User::factory()->create([
            'role' => User::ROLE_WEBSITE_ADMIN,
            'firm_id' => $otherFirm->id,
            'name' => 'Other Firm Admin',
        ]);
        $this->syncRoles($outOfScope, [User::ROLE_WEBSITE_ADMIN]);

        Sanctum::actingAs($firmAdmin);

        $response = $this->getJson('/api/website-compliance/website-admins')->assertOk();
        $ids = collect($response->json())->pluck('id')->map(fn ($id) => (int) $id)->all();

        $this->assertContains((int) $inFirm->id, $ids);
        $this->assertNotContains((int) $outOfScope->id, $ids);
    }

    public function test_firm_admin_can_request_site_with_multiple_website_admins(): void
    {
        $this->assertTrue(
            Schema::hasTable('wc_template_request_website_admins'),
            'Run migrations so wc_template_request_website_admins exists'
        );

        $firmAdmin = User::factory()->create([
            'role' => User::ROLE_FIRM_ADMIN,
            'firm_id' => $this->firm->id,
        ]);
        $this->syncRoles($firmAdmin, [User::ROLE_FIRM_ADMIN]);

        $adminA = User::factory()->create([
            'role' => User::ROLE_WEBSITE_ADMIN,
            'firm_id' => $this->firm->id,
            'name' => 'Website Admin A',
        ]);
        $this->syncRoles($adminA, [User::ROLE_WEBSITE_ADMIN]);

        $adminB = User::factory()->create([
            'role' => User::ROLE_WEBSITE_ADMIN,
            'firm_id' => $this->firm->id,
            'name' => 'Website Admin B',
        ]);
        $this->syncRoles($adminB, [User::ROLE_WEBSITE_ADMIN]);

        Sanctum::actingAs($firmAdmin);

        $response = $this->postJson('/api/website-compliance/template-requests', [
            'domain_name' => 'acme.example.com',
            'template_name' => 'template4',
            'request_type' => 'advisor_website',
            'website_admin_ids' => [$adminA->id, $adminB->id],
        ])->assertCreated();

        $this->assertEqualsCanonicalizing(
            [(int) $adminA->id, (int) $adminB->id],
            collect($response->json('website_admins') ?? $response->json('websiteAdmins') ?? [])
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all()
        );
        $this->assertSame((int) $adminA->id, (int) $response->json('assigned_advisor_id'));

        $requestId = (int) $response->json('id');
        $this->assertDatabaseHas('wc_template_request_website_admins', [
            'template_request_id' => $requestId,
            'user_id' => $adminA->id,
        ]);
        $this->assertDatabaseHas('wc_template_request_website_admins', [
            'template_request_id' => $requestId,
            'user_id' => $adminB->id,
        ]);
    }

    public function test_assign_rejects_non_website_admin_user(): void
    {
        $firmAdmin = User::factory()->create([
            'role' => User::ROLE_FIRM_ADMIN,
            'firm_id' => $this->firm->id,
        ]);
        $this->syncRoles($firmAdmin, [User::ROLE_FIRM_ADMIN]);

        $advisor = User::factory()->create([
            'role' => User::ROLE_ADVISOR,
            'firm_id' => $this->firm->id,
            'is_advisor' => true,
        ]);
        $this->syncRoles($advisor, [User::ROLE_ADVISOR]);

        $templateRequest = TemplateRequest::query()->create([
            'requested_by_id' => $firmAdmin->id,
            'template_name' => 'template4',
            'request_type' => 'advisor_website',
            'domain_name' => 'pending.example.com',
            'status' => 'pending',
        ]);

        Sanctum::actingAs($firmAdmin);

        $this->postJson('/api/website-compliance/template-requests/'.$templateRequest->id.'/assign-advisor', [
            'website_admin_ids' => [$advisor->id],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['website_admin_ids']);
    }
}
