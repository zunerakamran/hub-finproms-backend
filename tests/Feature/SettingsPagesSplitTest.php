<?php

namespace Tests\Feature;

use App\Models\Hub;
use App\Models\User;
use App\Services\CapabilitiesMatrixService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SettingsPagesSplitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'hub.current_slug' => 'shared',
            'hub.type' => 'shared',
            'hub.is_control_plane' => false,
        ]);
    }

    public function test_general_settings_endpoint_excludes_page_content_and_dashboard_nav(): void
    {
        $this->createSharedHub();
        Sanctum::actingAs(User::factory()->powerAdmin()->create());

        $response = $this->getJson('/api/client-admin/settings')->assertOk();
        $settings = $response->json('settings');
        $this->assertArrayHasKey('application_name', $settings);
        $this->assertArrayHasKey('color_scheme', $settings);
        $this->assertArrayNotHasKey('page_content', $settings);
        $this->assertArrayNotHasKey('dashboard_nav', $settings);
    }

    public function test_legacy_combined_settings_payload_is_rejected(): void
    {
        $this->createSharedHub();
        Sanctum::actingAs(User::factory()->powerAdmin()->create());

        $this->putJson('/api/client-admin/settings', [
            'application_name' => 'Renamed',
            'page_content' => ['home' => ['hero_title' => 'Hi']],
        ])->assertStatus(422);

        $this->putJson('/api/client-admin/settings', [
            'dashboard_nav' => ['sections' => ['account' => 'Acct']],
        ])->assertStatus(422);
    }

    public function test_page_content_and_dashboard_nav_have_separate_endpoints(): void
    {
        $this->createSharedHub();
        Sanctum::actingAs(User::factory()->powerAdmin()->create());

        $this->putJson('/api/client-admin/page-content', [
            'page_content' => [
                'home' => [
                    'title' => 'Custom Hero',
                ],
            ],
        ])->assertOk()
            ->assertJsonPath('page_content.home.title', 'Custom Hero');

        $this->putJson('/api/client-admin/dashboard-nav', [
            'dashboard_nav' => [
                'sections' => [
                    'account' => 'My Account',
                ],
            ],
        ])->assertOk();

        $nav = $this->getJson('/api/client-admin/dashboard-nav')->assertOk()->json('dashboard_nav');
        $this->assertSame('My Account', $nav['sections']['account'] ?? null);
    }

    public function test_each_settings_page_is_gated_by_its_own_capability(): void
    {
        $hub = $this->createSharedHub();
        $matrix = app(CapabilitiesMatrixService::class);
        $roleCaps = $matrix->resolvedRoleCapabilities($hub);

        $roleCaps[User::ROLE_CLIENT_ADMIN]['dashboard_manage_settings'] = true;
        $roleCaps[User::ROLE_CLIENT_ADMIN]['dashboard_manage_page_content'] = false;
        $roleCaps[User::ROLE_CLIENT_ADMIN]['dashboard_manage_dashboard_nav'] = false;
        $hub->role_capabilities = $roleCaps;
        $hub->save();
        $matrix->forgetResolvedCaches();

        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_CLIENT_ADMIN]));

        $this->getJson('/api/client-admin/settings')->assertOk();
        $this->getJson('/api/client-admin/page-content')->assertForbidden();
        $this->getJson('/api/client-admin/dashboard-nav')->assertForbidden();

        $roleCaps[User::ROLE_CLIENT_ADMIN]['dashboard_manage_settings'] = false;
        $roleCaps[User::ROLE_CLIENT_ADMIN]['dashboard_manage_page_content'] = true;
        $roleCaps[User::ROLE_CLIENT_ADMIN]['dashboard_manage_dashboard_nav'] = true;
        $hub->role_capabilities = $roleCaps;
        $hub->save();
        $matrix->forgetResolvedCaches();

        $this->getJson('/api/client-admin/settings')->assertForbidden();
        $this->getJson('/api/client-admin/page-content')->assertOk();
        $this->getJson('/api/client-admin/dashboard-nav')->assertOk();
    }

    public function test_new_capabilities_appear_in_checklist_definitions_and_defaults(): void
    {
        $this->assertArrayHasKey('dashboard_manage_settings', Hub::CHECKLIST_DEFINITIONS);
        $this->assertArrayHasKey('dashboard_manage_page_content', Hub::CHECKLIST_DEFINITIONS);
        $this->assertArrayHasKey('dashboard_manage_dashboard_nav', Hub::CHECKLIST_DEFINITIONS);

        $defaults = app(CapabilitiesMatrixService::class)
            ->defaultRoleCapabilities(Hub::TYPE_SHARED);

        $this->assertTrue($defaults[User::ROLE_CLIENT_ADMIN]['dashboard_manage_settings']);
        $this->assertTrue($defaults[User::ROLE_CLIENT_ADMIN]['dashboard_manage_page_content']);
        $this->assertTrue($defaults[User::ROLE_CLIENT_ADMIN]['dashboard_manage_dashboard_nav']);
    }

    private function createSharedHub(): Hub
    {
        return Hub::query()->create([
            'name' => 'Shared Hub',
            'slug' => 'shared',
            'type' => Hub::TYPE_SHARED,
            'is_active' => true,
            'checklist' => Hub::defaultChecklist(Hub::TYPE_SHARED),
            'role_capabilities' => app(CapabilitiesMatrixService::class)
                ->defaultRoleCapabilities(Hub::TYPE_SHARED),
        ]);
    }
}
