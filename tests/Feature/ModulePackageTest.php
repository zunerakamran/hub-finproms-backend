<?php

namespace Tests\Feature;

use App\Models\Hub;
use App\Models\Invoice;
use App\Models\User;
use App\Services\CapabilitiesMatrixService;
use App\Services\ModulePackageService;
use App\Services\ModulePricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ModulePackageTest extends TestCase
{
    use RefreshDatabase;

    private Hub $central;

    private CapabilitiesMatrixService $matrix;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'hub.current_slug' => 'central',
            'hub.type' => Hub::TYPE_CENTRAL,
            'hub.is_control_plane' => true,
        ]);

        $this->matrix = app(CapabilitiesMatrixService::class);
        $this->central = Hub::query()->create([
            'name' => 'Central Hub Controller',
            'slug' => 'central',
            'type' => Hub::TYPE_CENTRAL,
            'is_active' => true,
            'checklist' => Hub::defaultChecklist(Hub::TYPE_CENTRAL),
            'role_capabilities' => $this->matrix->defaultRoleCapabilities(Hub::TYPE_CENTRAL),
        ]);
    }

    public function test_package_create_auto_includes_dependencies(): void
    {
        $admin = User::factory()->powerAdmin()->create();
        Sanctum::actingAs($admin);
        $this->grantPackageCapability($this->central);
        app(ModulePricingService::class)->seedDefaultsIfEmpty();

        $response = $this->postJson('/api/power-admin/module-packages', [
            'name' => 'Social Media Suite',
            'amount' => 4500,
            'module_keys' => ['module_social_media_compliance'],
        ]);

        $response->assertCreated();
        $keys = $response->json('package.module_keys');
        $this->assertSame(
            ['module_social_media_template_library', 'module_social_media_compliance'],
            $keys
        );
        $this->assertSame(4500.0, (float) $response->json('package.amount'));
        $this->assertSame(5000.0, (float) $response->json('package.individual_module_amount_sum'));
    }

    public function test_package_rejects_base_hub_module(): void
    {
        $admin = User::factory()->powerAdmin()->create();
        Sanctum::actingAs($admin);
        $this->grantPackageCapability($this->central);

        $this->postJson('/api/power-admin/module-packages', [
            'name' => 'Bad',
            'amount' => 1,
            'module_keys' => ['module_shared_hub'],
        ])->assertStatus(422);
    }

    public function test_enabling_package_locks_modules_and_invoices_package_price_only(): void
    {
        $hub = $this->createWhiteLabelHub([
            'checklist' => array_merge(Hub::defaultChecklist(Hub::TYPE_WHITE_LABEL), [
                'charge_amount_per_module' => true,
                'module_social_media_template_library' => true,
                'module_social_media_compliance' => false,
                'module_general_compliance' => false,
            ]),
        ]);

        $admin = User::factory()->powerAdmin()->create(['acting_hub_id' => $hub->id]);
        Sanctum::actingAs($admin);
        app(ModulePricingService::class)->seedDefaultsIfEmpty();

        $package = app(ModulePackageService::class)->create([
            'name' => 'Compliance Bundle',
            'amount' => 7000,
            'module_keys' => [
                'module_social_media_compliance',
                'module_general_compliance',
            ],
        ]);

        $response = $this->putJson('/api/power-admin/modules?hub_id='.$hub->id, [
            'hub_id' => $hub->id,
            'packages' => [
                ['id' => $package->id, 'enabled' => true],
            ],
        ]);

        $response->assertOk();
        $invoices = collect($response->json('module_invoices'));
        $this->assertCount(1, $invoices);
        $this->assertSame(7000.0, (float) $invoices->first()['amount']);
        $this->assertStringContainsString('Compliance Bundle', $invoices->first()['description']);

        $modules = collect($response->json('modules'));
        $smc = $modules->firstWhere('key', 'module_social_media_compliance');
        $gc = $modules->firstWhere('key', 'module_general_compliance');
        $this->assertTrue($smc['enabled']);
        $this->assertTrue($smc['locked']);
        $this->assertSame('module_package', $smc['locked_reason']);
        $this->assertSame($package->id, $smc['locked_by_package']['id']);
        $this->assertTrue($gc['enabled']);
        $this->assertTrue($gc['locked']);

        $hub->refresh();
        $this->assertTrue($hub->hasSocialMediaComplianceModule());
        $this->assertTrue($hub->hasGeneralComplianceModule());
        $this->assertSame([$package->id], $hub->enabledModulePackageIds());

        // Individual toggle ignored while package owns the module.
        $this->putJson('/api/power-admin/modules?hub_id='.$hub->id, [
            'hub_id' => $hub->id,
            'modules' => [
                'module_social_media_compliance' => false,
                'module_general_compliance' => false,
            ],
        ])->assertOk();

        $hub->refresh();
        $this->assertTrue($hub->hasSocialMediaComplianceModule());
        $this->assertTrue($hub->hasGeneralComplianceModule());
    }

    public function test_overlapping_packages_cannot_both_be_enabled(): void
    {
        $hub = $this->createSharedHub([
            'checklist' => array_merge(Hub::defaultChecklist(Hub::TYPE_SHARED), [
                'charge_amount_per_module' => true,
            ]),
        ]);

        $admin = User::factory()->powerAdmin()->create(['acting_hub_id' => $hub->id]);
        Sanctum::actingAs($admin);

        $svc = app(ModulePackageService::class);
        $a = $svc->create([
            'name' => 'SM Only',
            'amount' => 100,
            'module_keys' => ['module_social_media_template_library'],
        ]);
        $b = $svc->create([
            'name' => 'SM Full',
            'amount' => 200,
            'module_keys' => ['module_social_media_compliance'],
        ]);

        $this->putJson('/api/power-admin/modules?hub_id='.$hub->id, [
            'hub_id' => $hub->id,
            'packages' => [
                ['id' => $a->id, 'enabled' => true],
            ],
        ])->assertOk();

        $this->putJson('/api/power-admin/modules?hub_id='.$hub->id, [
            'hub_id' => $hub->id,
            'packages' => [
                ['id' => $b->id, 'enabled' => true],
            ],
        ])->assertStatus(422)
            ->assertJsonFragment(['message' => 'Module packages cannot overlap: “SM Only” and “SM Full” both include Social Media Template Library. Disable one package or remove the shared module.']);
    }

    public function test_disabling_package_turns_covered_modules_off(): void
    {
        $hub = $this->createSharedHub([
            'checklist' => array_merge(Hub::defaultChecklist(Hub::TYPE_SHARED), [
                'module_social_media_template_library' => false,
                'module_social_media_compliance' => false,
            ]),
        ]);

        $admin = User::factory()->powerAdmin()->create(['acting_hub_id' => $hub->id]);
        Sanctum::actingAs($admin);

        $package = app(ModulePackageService::class)->create([
            'name' => 'SM Pack',
            'amount' => 0,
            'module_keys' => ['module_social_media_compliance'],
        ]);

        $enable = $this->putJson('/api/power-admin/modules?hub_id='.$hub->id, [
            'hub_id' => $hub->id,
            'packages' => [['id' => $package->id, 'enabled' => true]],
        ]);
        $enable->assertOk();

        $hub->refresh();
        $this->assertSame([$package->id], $hub->enabledModulePackageIds(), 'packages after enable: '.json_encode($enable->json('packages')));
        $this->assertTrue($hub->hasSocialMediaComplianceModule());

        $this->putJson('/api/power-admin/modules?hub_id='.$hub->id, [
            'hub_id' => $hub->id,
            'packages' => [['id' => $package->id, 'enabled' => false]],
        ])->assertOk();

        $hub->refresh();
        $this->assertSame([], $hub->enabledModulePackageIds());
        $this->assertFalse($hub->hasSocialMediaComplianceModule());
        $this->assertFalse($hub->hasSocialMediaTemplateLibraryModule());
    }

    public function test_individually_enabling_uncovered_module_still_invoices(): void
    {
        $hub = $this->createWhiteLabelHub([
            'checklist' => array_merge(Hub::defaultChecklist(Hub::TYPE_WHITE_LABEL), [
                'charge_amount_per_module' => true,
                'module_social_media_template_library' => true,
                'module_general_compliance' => false,
            ]),
        ]);

        $admin = User::factory()->powerAdmin()->create(['acting_hub_id' => $hub->id]);
        Sanctum::actingAs($admin);
        app(ModulePricingService::class)->seedDefaultsIfEmpty();

        $package = app(ModulePackageService::class)->create([
            'name' => 'SM Only Pack',
            'amount' => 4000,
            'module_keys' => ['module_social_media_compliance'],
        ]);

        $this->putJson('/api/power-admin/modules?hub_id='.$hub->id, [
            'hub_id' => $hub->id,
            'packages' => [['id' => $package->id, 'enabled' => true]],
        ])->assertOk();

        $response = $this->putJson('/api/power-admin/modules?hub_id='.$hub->id, [
            'hub_id' => $hub->id,
            'modules' => [
                'module_general_compliance' => true,
            ],
        ]);

        $response->assertOk();
        $created = collect($response->json('module_invoices'));
        $this->assertCount(1, $created);
        $this->assertSame(3500.0, (float) $created->first()['amount']);
        $this->assertStringContainsString('Generic Content Pre Approval', $created->first()['description']);
        $this->assertSame(Invoice::TYPE_MODULE_BILLING, $created->first()['type']);
    }

    public function test_modules_show_includes_packages(): void
    {
        $hub = $this->createSharedHub();
        $admin = User::factory()->powerAdmin()->create(['acting_hub_id' => $hub->id]);
        Sanctum::actingAs($admin);

        app(ModulePackageService::class)->create([
            'name' => 'Visible Pack',
            'amount' => 10,
            'module_keys' => ['module_general_compliance'],
        ]);

        $response = $this->getJson('/api/power-admin/modules?hub_id='.$hub->id);
        $response->assertOk();
        $this->assertNotEmpty($response->json('packages'));
        $this->assertSame('Visible Pack', $response->json('packages.0.name'));
        $this->assertFalse($response->json('packages.0.enabled'));
    }

    public function test_delete_package_removes_it_from_hubs(): void
    {
        $hub = $this->createSharedHub();
        $admin = User::factory()->powerAdmin()->create(['acting_hub_id' => $hub->id]);
        Sanctum::actingAs($admin);
        $this->grantPackageCapability($this->central);

        $package = app(ModulePackageService::class)->create([
            'name' => 'Temp Pack',
            'amount' => 0,
            'module_keys' => ['module_general_compliance'],
        ]);

        $this->putJson('/api/power-admin/modules?hub_id='.$hub->id, [
            'hub_id' => $hub->id,
            'packages' => [['id' => $package->id, 'enabled' => true]],
        ])->assertOk();

        $this->deleteJson('/api/power-admin/module-packages/'.$package->id)
            ->assertOk();

        $this->assertDatabaseMissing('module_packages', ['id' => $package->id]);
        $this->assertSame([], $hub->fresh()->enabledModulePackageIds());
    }

    public function test_module_pricing_index_filters_by_hub_type(): void
    {
        $wl = $this->createWhiteLabelHub();
        $admin = User::factory()->powerAdmin()->create(['acting_hub_id' => $wl->id]);
        Sanctum::actingAs($admin);
        app(ModulePricingService::class)->seedDefaultsIfEmpty();

        $caps = $this->matrix->resolvedRoleCapabilities($this->central);
        $caps[User::ROLE_POWER_ADMIN]['dashboard_manage_module_pricing'] = true;
        $this->central->role_capabilities = $caps;
        $this->central->save();

        $response = $this->getJson('/api/power-admin/module-pricing');
        $response->assertOk();
        $keys = collect($response->json('pricing'))->pluck('module_key')->all();
        $this->assertContains('module_white_label_hub', $keys);
        $this->assertNotContains('module_shared_hub', $keys);
        $this->assertNotContains('module_central_hub', $keys);
        $this->assertContains('module_social_media_template_library', $keys);
        $this->assertSame(Hub::TYPE_WHITE_LABEL, $response->json('target_hub.type'));
    }

    private function grantPackageCapability(Hub $hub): void
    {
        $caps = $this->matrix->resolvedRoleCapabilities($hub);
        $caps[User::ROLE_POWER_ADMIN]['dashboard_manage_module_packages'] = true;
        $hub->role_capabilities = $caps;
        $hub->save();
    }

    private function createSharedHub(array $extra = []): Hub
    {
        return Hub::query()->create(array_merge([
            'name' => 'Shared Hub',
            'slug' => 'shared-pkg-'.uniqid(),
            'type' => Hub::TYPE_SHARED,
            'is_active' => true,
            'checklist' => Hub::defaultChecklist(Hub::TYPE_SHARED),
            'role_capabilities' => $this->matrix->defaultRoleCapabilities(Hub::TYPE_SHARED),
        ], $extra));
    }

    private function createWhiteLabelHub(array $extra = []): Hub
    {
        return Hub::query()->create(array_merge([
            'name' => 'WL Hub',
            'slug' => 'wl-pkg-'.uniqid(),
            'type' => Hub::TYPE_WHITE_LABEL,
            'is_active' => true,
            'checklist' => Hub::defaultChecklist(Hub::TYPE_WHITE_LABEL),
            'role_capabilities' => $this->matrix->defaultRoleCapabilities(Hub::TYPE_WHITE_LABEL),
        ], $extra));
    }
}
