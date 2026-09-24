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

    public function test_modules_endpoint_returns_six_modules_with_white_label_locked_on_shared(): void
    {
        $hub = $this->createSharedHub();
        $admin = User::factory()->powerAdmin()->create();
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/power-admin/modules');
        $response->assertOk();

        $modules = collect($response->json('modules'));
        $this->assertCount(6, $modules);
        $this->assertSame(Hub::MODULE_KEYS, $modules->pluck('key')->all());

        $whiteLabel = $modules->firstWhere('key', 'module_white_label_hub');
        $this->assertFalse($whiteLabel['enabled']);
        $this->assertTrue($whiteLabel['locked']);
        $this->assertSame('shared_hub', $whiteLabel['locked_reason']);

        $library = $modules->firstWhere('key', 'module_social_media_template_library');
        $this->assertTrue($library['enabled']);
        $this->assertFalse($library['locked']);
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

        $checklist = $hub->resolvedChecklist();
        $checklist['module_white_label_hub'] = false;
        $hub->forceFill(['checklist' => $checklist])->save();

        $this->assertTrue($hub->fresh()->hasWhiteLabelHubModule());
    }

    public function test_disabling_social_media_template_library_disables_related_functionalities_and_caps(): void
    {
        $hub = $this->createSharedHub([
            'checklist' => array_merge(Hub::defaultChecklist(Hub::TYPE_SHARED), [
                'module_social_media_template_library' => true,
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
                'module_white_label_hub' => true, // ignored / locked
            ],
        ])->assertOk()
            ->assertJsonPath('modules.0.key', 'module_white_label_hub')
            ->assertJsonPath('modules.0.enabled', false)
            ->assertJsonPath('modules.0.locked', true);

        $hub->refresh();
        $resolved = $hub->resolvedChecklist();
        $this->assertFalse($resolved['module_social_media_template_library']);
        $this->assertFalse($resolved['module_white_label_hub']);
        $this->assertFalse($hub->can('one_off_purchase'));
        $this->assertFalse($hub->can('receive_content_from_shared'));
        $this->assertFalse($matrix->roleCan($hub, User::ROLE_USER, 'member_browse_catalog'));
        $this->assertFalse($matrix->roleCan($hub, User::ROLE_USER, 'member_purchase_content'));
    }

    public function test_website_template_library_caps_require_their_own_module(): void
    {
        $hub = $this->createSharedHub([
            'checklist' => array_merge(Hub::defaultChecklist(Hub::TYPE_SHARED), [
                'module_website_compliance' => true,
                'module_website_template_library' => false,
            ]),
        ]);

        $matrix = app(CapabilitiesMatrixService::class);
        $caps = $matrix->resolvedRoleCapabilities($hub);
        $caps[User::ROLE_CLIENT_ADMIN]['wc_manage_templates'] = true;
        $caps[User::ROLE_CLIENT_ADMIN]['wc_edit_sections'] = true;
        $hub->role_capabilities = $caps;
        $hub->save();

        $this->assertFalse($matrix->roleCan($hub, User::ROLE_CLIENT_ADMIN, 'wc_manage_templates'));
        $this->assertTrue($matrix->roleCan($hub, User::ROLE_CLIENT_ADMIN, 'wc_edit_sections'));

        $payload = $matrix->matrix($hub);
        $templateRow = collect($payload['rows'])->firstWhere('key', 'wc_manage_templates');
        $this->assertTrue($templateRow['inactive']);
        $this->assertSame('module_website_template_library', $templateRow['requires_module']);

        $complianceRow = collect($payload['rows'])->firstWhere('key', 'wc_edit_sections');
        $this->assertFalse($complianceRow['inactive']);
        $this->assertSame('module_website_compliance', $complianceRow['requires_module']);
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
