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
}
