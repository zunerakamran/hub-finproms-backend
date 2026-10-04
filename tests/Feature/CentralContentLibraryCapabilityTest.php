<?php

namespace Tests\Feature;

use App\Models\Hub;
use App\Models\User;
use App\Services\CapabilitiesMatrixService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CentralContentLibraryCapabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'hub.current_slug' => 'central',
            'hub.type' => Hub::TYPE_CENTRAL,
            'hub.is_control_plane' => true,
        ]);
    }

    public function test_power_admin_keeps_central_library_while_acting_on_a_content_hub(): void
    {
        $matrix = app(CapabilitiesMatrixService::class);

        $central = Hub::query()->create([
            'name' => 'Central Hub Controller',
            'slug' => 'central',
            'type' => Hub::TYPE_CENTRAL,
            'is_active' => true,
            'checklist' => Hub::defaultChecklist(Hub::TYPE_CENTRAL),
            'role_capabilities' => $matrix->defaultRoleCapabilities(Hub::TYPE_CENTRAL),
        ]);

        $shared = Hub::query()->create([
            'name' => 'Shared Hub',
            'slug' => 'shared',
            'type' => Hub::TYPE_SHARED,
            'is_active' => true,
            'checklist' => Hub::defaultChecklist(Hub::TYPE_SHARED),
            'role_capabilities' => $matrix->defaultRoleCapabilities(Hub::TYPE_SHARED),
        ]);

        $admin = User::factory()->powerAdmin()->create([
            'acting_hub_id' => $shared->id,
        ]);
        Sanctum::actingAs($admin);

        $this->assertTrue($central->isControlPlane());
        $this->assertTrue(
            $matrix->userCan($central, $admin, 'dashboard_central_content_library')
        );

        $this->getJson('/api/hub')
            ->assertOk()
            ->assertJsonPath('hub.hub_switcher.is_acting_remotely', true)
            ->assertJsonPath(
                'hub.hub_switcher.effective_capabilities.dashboard_central_content_library',
                true
            );

        $this->getJson('/api/power-admin/central-library/posts')
            ->assertOk();
    }

    public function test_central_library_is_denied_when_power_admin_cell_is_off(): void
    {
        $matrix = app(CapabilitiesMatrixService::class);
        $roleCaps = $matrix->defaultRoleCapabilities(Hub::TYPE_CENTRAL);
        $roleCaps[User::ROLE_POWER_ADMIN]['dashboard_central_content_library'] = false;
        $roleCaps[User::ROLE_FINPROMS_ADMIN]['dashboard_central_content_library'] = false;

        Hub::query()->create([
            'name' => 'Central Hub Controller',
            'slug' => 'central',
            'type' => Hub::TYPE_CENTRAL,
            'is_active' => true,
            'checklist' => array_merge(Hub::defaultChecklist(Hub::TYPE_CENTRAL), [
                'dashboard_central_content_library' => false,
            ]),
            'role_capabilities' => $roleCaps,
        ]);

        $admin = User::factory()->powerAdmin()->create();
        Sanctum::actingAs($admin);

        $this->getJson('/api/hub')
            ->assertOk()
            ->assertJsonPath(
                'hub.effective_capabilities.dashboard_central_content_library',
                false
            );

        $this->getJson('/api/power-admin/central-library/posts')
            ->assertForbidden()
            ->assertJsonPath('capability', 'dashboard_central_content_library');
    }

    public function test_power_admin_can_list_taxonomy_on_central_without_member_catalog(): void
    {
        $matrix = app(CapabilitiesMatrixService::class);

        Hub::query()->create([
            'name' => 'Central Hub Controller',
            'slug' => 'central',
            'type' => Hub::TYPE_CENTRAL,
            'is_active' => true,
            'checklist' => Hub::defaultChecklist(Hub::TYPE_CENTRAL),
            'role_capabilities' => $matrix->defaultRoleCapabilities(Hub::TYPE_CENTRAL),
        ]);

        Sanctum::actingAs(User::factory()->powerAdmin()->create());

        $this->getJson('/api/types')->assertOk();
        $this->getJson('/api/categories')->assertOk();
        $this->getJson('/api/tags')->assertOk();
    }

    public function test_guests_cannot_list_taxonomy_on_central_via_library_or_flag(): void
    {
        $matrix = app(CapabilitiesMatrixService::class);

        Hub::query()->create([
            'name' => 'Central Hub Controller',
            'slug' => 'central',
            'type' => Hub::TYPE_CENTRAL,
            'is_active' => true,
            'checklist' => Hub::defaultChecklist(Hub::TYPE_CENTRAL),
            'role_capabilities' => $matrix->defaultRoleCapabilities(Hub::TYPE_CENTRAL),
        ]);

        $this->getJson('/api/types')
            ->assertForbidden()
            ->assertJsonPath('message', 'This capability is disabled for this hub by Power Admin.');
    }

    public function test_distribute_targets_include_eligible_white_label_hubs(): void
    {
        $matrix = app(CapabilitiesMatrixService::class);

        Hub::query()->create([
            'name' => 'Central Hub Controller',
            'slug' => 'central',
            'type' => Hub::TYPE_CENTRAL,
            'is_active' => true,
            'checklist' => Hub::defaultChecklist(Hub::TYPE_CENTRAL),
            'role_capabilities' => $matrix->defaultRoleCapabilities(Hub::TYPE_CENTRAL),
        ]);

        $sqlite = sys_get_temp_dir().DIRECTORY_SEPARATOR.'central-targets-'.uniqid('', true).'.sqlite';
        touch($sqlite);

        $shared = Hub::query()->create([
            'name' => 'Shared Hub',
            'slug' => 'shared',
            'type' => Hub::TYPE_SHARED,
            'is_active' => true,
            'checklist' => Hub::defaultChecklist(Hub::TYPE_SHARED),
            'db_driver' => 'sqlite',
            'db_database' => $sqlite,
            'db_username' => 'test',
            'db_password' => 'secret',
        ]);

        // Legacy WL checklist that previously hid the hub from distribute.
        $whiteLabel = Hub::query()->create([
            'name' => 'My Hub',
            'slug' => 'myhub',
            'type' => Hub::TYPE_WHITE_LABEL,
            'is_active' => true,
            'checklist' => array_merge(Hub::defaultChecklist(Hub::TYPE_WHITE_LABEL), [
                'receive_content_from_shared' => false,
                'manual_posts' => false,
                'ai_posts' => false,
            ]),
            'db_driver' => 'sqlite',
            'db_database' => $sqlite,
            'db_username' => 'test',
            'db_password' => 'secret',
        ]);

        // Heal the same way the migrate does for existing WL registry rows.
        $healed = $whiteLabel->checklist;
        $healed['receive_content_from_shared'] = true;
        $healed['manual_posts'] = true;
        $healed['ai_posts'] = false;
        $whiteLabel->checklist = $healed;
        $whiteLabel->save();

        Sanctum::actingAs(User::factory()->powerAdmin()->create());

        $response = $this->getJson('/api/power-admin/central-library/targets')
            ->assertOk();

        $hubs = collect($response->json('hubs'));
        $this->assertTrue($hubs->contains(fn ($row) => (int) $row['id'] === (int) $shared->id));
        $this->assertTrue($hubs->contains(fn ($row) => (int) $row['id'] === (int) $whiteLabel->id));

        $wlRow = $hubs->firstWhere('id', $whiteLabel->id);
        $this->assertTrue((bool) $wlRow['eligible']);
        $this->assertTrue((bool) $wlRow['can_receive']);
        $this->assertTrue((bool) $wlRow['manual_posts']);
        $this->assertSame('white_label', $wlRow['type']);
    }

    public function test_distribute_targets_list_ineligible_white_label_with_reason(): void
    {
        $matrix = app(CapabilitiesMatrixService::class);

        Hub::query()->create([
            'name' => 'Central Hub Controller',
            'slug' => 'central',
            'type' => Hub::TYPE_CENTRAL,
            'is_active' => true,
            'checklist' => Hub::defaultChecklist(Hub::TYPE_CENTRAL),
            'role_capabilities' => $matrix->defaultRoleCapabilities(Hub::TYPE_CENTRAL),
        ]);

        $whiteLabel = Hub::query()->create([
            'name' => 'Unwired WL',
            'slug' => 'unwired-wl',
            'type' => Hub::TYPE_WHITE_LABEL,
            'is_active' => true,
            'checklist' => Hub::defaultChecklist(Hub::TYPE_WHITE_LABEL),
        ]);

        Sanctum::actingAs(User::factory()->powerAdmin()->create());

        $response = $this->getJson('/api/power-admin/central-library/targets')
            ->assertOk();

        $wlRow = collect($response->json('hubs'))->firstWhere('id', $whiteLabel->id);
        $this->assertNotNull($wlRow);
        $this->assertFalse((bool) $wlRow['eligible']);
        $this->assertNotEmpty($wlRow['reason']);
        $this->assertStringContainsString('remote database', strtolower((string) $wlRow['reason']));
    }
}
