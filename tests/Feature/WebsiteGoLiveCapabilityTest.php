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
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WebsiteGoLiveCapabilityTest extends TestCase
{
    use RefreshDatabase;

    private Hub $hub;

    private Firm $firm;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hub = Hub::query()->create([
            'name' => 'Shared Hub',
            'slug' => 'shared',
            'type' => Hub::TYPE_SHARED,
            'is_active' => true,
            'checklist' => array_merge(Hub::defaultChecklist(Hub::TYPE_SHARED), [
                'module_website_compliance' => true,
                'module_website_template_library' => true,
                'wc_request_go_live' => true,
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

    public function test_firm_admin_requester_can_submit_go_live(): void
    {
        $firmAdmin = User::factory()->create([
            'role' => User::ROLE_FIRM_ADMIN,
            'firm_id' => $this->firm->id,
        ]);
        $this->syncRoles($firmAdmin, [User::ROLE_FIRM_ADMIN]);

        $templateRequest = TemplateRequest::query()->create([
            'requested_by_id' => $firmAdmin->id,
            'template_name' => 'template4',
            'request_type' => 'advisor_website',
            'domain_name' => 'acme.example.com',
            'status' => TemplateRequest::STATUS_STAGING,
            'staging_domain' => 'https://staging.acme.example.com',
            'cpanel_domain' => 'https://staging.acme.example.com',
        ]);

        Sanctum::actingAs($firmAdmin);

        $this->postJson('/api/website-compliance/template-requests/'.$templateRequest->id.'/request-go-live', [
            'domain_name' => 'www.acme.example.com',
            'notes' => 'Ready for launch',
        ])->assertCreated()
            ->assertJsonPath('template_request.status', TemplateRequest::STATUS_READY_FOR_LIVE)
            ->assertJsonPath('template_request.domain_name', 'www.acme.example.com');
    }

    public function test_non_requester_with_capability_cannot_submit_go_live(): void
    {
        $requester = User::factory()->create([
            'role' => User::ROLE_FIRM_ADMIN,
            'firm_id' => $this->firm->id,
        ]);
        $this->syncRoles($requester, [User::ROLE_FIRM_ADMIN]);

        $other = User::factory()->create([
            'role' => User::ROLE_FIRM_ADMIN,
            'firm_id' => $this->firm->id,
        ]);
        $this->syncRoles($other, [User::ROLE_FIRM_ADMIN]);

        $templateRequest = TemplateRequest::query()->create([
            'requested_by_id' => $requester->id,
            'template_name' => 'template4',
            'request_type' => 'advisor_website',
            'domain_name' => 'acme.example.com',
            'status' => TemplateRequest::STATUS_STAGING,
        ]);

        Sanctum::actingAs($other);

        $this->postJson('/api/website-compliance/template-requests/'.$templateRequest->id.'/request-go-live', [
            'domain_name' => 'www.acme.example.com',
        ])->assertForbidden();
    }

    public function test_user_without_go_live_capability_is_denied(): void
    {
        $websiteAdmin = User::factory()->create([
            'role' => User::ROLE_WEBSITE_ADMIN,
            'firm_id' => $this->firm->id,
        ]);
        $this->syncRoles($websiteAdmin, [User::ROLE_WEBSITE_ADMIN]);

        $templateRequest = TemplateRequest::query()->create([
            'requested_by_id' => $websiteAdmin->id,
            'template_name' => 'template4',
            'request_type' => 'advisor_website',
            'domain_name' => 'acme.example.com',
            'status' => TemplateRequest::STATUS_STAGING,
        ]);

        Sanctum::actingAs($websiteAdmin);

        $this->postJson('/api/website-compliance/template-requests/'.$templateRequest->id.'/request-go-live', [
            'domain_name' => 'www.acme.example.com',
        ])->assertForbidden();
    }
}
