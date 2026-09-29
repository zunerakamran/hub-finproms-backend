<?php

namespace Tests\Feature;

use App\Models\Hub;
use App\Models\User;
use App\Services\CapabilitiesMatrixService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class HubModulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_modules_endpoint_returns_six_modules_with_shared_hub_base(): void
    {
        $hub = $this->createSharedHub();
        $admin = User::factory()->powerAdmin()->create();
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/power-admin/modules');
        $response->assertOk();

        $modules = collect($response->json('modules'));
        $this->assertCount(6, $modules);
        $this->assertSame($hub->moduleKeysForPage(), $modules->pluck('key')->all());

        $base = $modules->firstWhere('key', 'module_shared_hub');
        $this->assertTrue($base['enabled']);
        $this->assertTrue($base['locked']);
        $this->assertSame('shared_hub', $base['locked_reason']);
        $this->assertSame([], $base['depends_on']);

        $library = $modules->firstWhere('key', 'module_social_media_template_library');
        $this->assertTrue($library['enabled']);
        $this->assertFalse($library['locked']);
        $this->assertSame(['module_shared_hub'], $library['depends_on']);
        $this->assertTrue($library['dependencies_met']);

        $smc = $modules->firstWhere('key', 'module_social_media_compliance');
        $this->assertSame(
            ['module_shared_hub', 'module_social_media_template_library'],
            $smc['depends_on']
        );
    }

    public function test_white_label_hub_module_is_always_enabled_and_locked(): void
    {
        $hub = Hub::query()->create([
            'name' => 'WL Hub',
            'slug' => 'wl-hub',
            'type' => Hub::TYPE_WHITE_LABEL,
            'is_active' => true,
            'checklist' => Hub::defaultChecklist(Hub::TYPE_WHITE_LABEL),
        ]);

        $this->assertTrue($hub->hasWhiteLabelHubModule());
        $this->assertTrue($hub->resolvedChecklist()['module_white_label_hub']);
        $this->assertFalse($hub->resolvedChecklist()['module_shared_hub']);
        $this->assertSame('module_white_label_hub', $hub->baseModuleKey());

        $checklist = $hub->resolvedChecklist();
        $checklist['module_white_label_hub'] = false;
        $hub->forceFill(['checklist' => $checklist])->save();

        $this->assertTrue($hub->fresh()->hasWhiteLabelHubModule());
    }

    public function test_disabling_social_media_template_library_cascades_to_pre_approval(): void
    {
        $hub = $this->createSharedHub([
            'checklist' => array_merge(Hub::defaultChecklist(Hub::TYPE_SHARED), [
                'module_social_media_template_library' => true,
                'module_social_media_compliance' => true,
                'one_off_purchase' => true,
                'receive_content_from_shared' => true,
            ]),
        ]);

        $matrix = app(CapabilitiesMatrixService::class);
        $caps = $matrix->resolvedRoleCapabilities($hub);
        $caps[User::ROLE_POWER_ADMIN]['dashboard_manage_modules'] = true;
        $caps[User::ROLE_USER]['member_browse_catalog'] = true;
        $caps[User::ROLE_USER]['member_purchase_content'] = true;
        $hub->role_capabilities = $caps;
        $hub->save();

        $admin = User::factory()->powerAdmin()->create();
        Sanctum::actingAs($admin);

        $this->putJson('/api/power-admin/modules', [
            'modules' => [
                'module_social_media_template_library' => false,
                'module_social_media_compliance' => true, // forced off by dependency
                'module_shared_hub' => false, // ignored / locked on
            ],
        ])->assertOk()
            ->assertJsonPath('modules.0.key', 'module_shared_hub')
            ->assertJsonPath('modules.0.enabled', true)
            ->assertJsonPath('modules.0.locked', true);

        $hub->refresh();
        $this->assertTrue($hub->hasSharedHubModule());
        $this->assertFalse($hub->hasSocialMediaTemplateLibraryModule());
        $this->assertFalse($hub->hasSocialMediaComplianceModule());
        $this->assertFalse($hub->can('one_off_purchase'));
        $this->assertFalse($hub->can('receive_content_from_shared'));
        $this->assertFalse($matrix->roleCan($hub, User::ROLE_USER, 'member_browse_catalog'));
        $this->assertFalse($matrix->roleCan($hub, User::ROLE_USER, 'member_purchase_content'));
    }

    public function test_website_content_pre_approval_requires_template_library(): void
    {
        $hub = $this->createSharedHub([
            'checklist' => array_merge(Hub::defaultChecklist(Hub::TYPE_SHARED), [
                'module_website_compliance' => true,
                'module_website_template_library' => false,
            ]),
        ]);

        // Dependency cascade forces website compliance off while template library is off.
        $this->assertFalse($hub->hasWebsiteTemplateLibraryModule());
        $this->assertFalse($hub->hasWebsiteComplianceModule());

        $matrix = app(CapabilitiesMatrixService::class);
        $caps = $matrix->resolvedRoleCapabilities($hub);
        $caps[User::ROLE_CLIENT_ADMIN]['wc_manage_templates'] = true;
        $caps[User::ROLE_CLIENT_ADMIN]['wc_edit_sections'] = true;
        $hub->role_capabilities = $caps;
        $hub->save();

        $this->assertFalse($matrix->roleCan($hub, User::ROLE_CLIENT_ADMIN, 'wc_manage_templates'));
        $this->assertFalse($matrix->roleCan($hub, User::ROLE_CLIENT_ADMIN, 'wc_edit_sections'));

        $checklist = $hub->resolvedChecklist();
        $checklist['module_website_template_library'] = true;
        $checklist['module_website_compliance'] = true;
        $hub->forceFill(['checklist' => $hub->applyModuleDependencies($checklist)])->save();

        $this->assertTrue($hub->fresh()->hasWebsiteTemplateLibraryModule());
        $this->assertTrue($hub->fresh()->hasWebsiteComplianceModule());
        $this->assertTrue($matrix->roleCan($hub->fresh(), User::ROLE_CLIENT_ADMIN, 'wc_edit_sections'));
    }

    private function createSharedHub(array $extra = []): Hub
    {
        return Hub::query()->create(array_merge([
            'name' => 'Shared Hub',
            'slug' => 'shared',
            'type' => Hub::TYPE_SHARED,
            'is_active' => true,
            'checklist' => Hub::defaultChecklist(Hub::TYPE_SHARED),
        ], $extra));
    }
}
