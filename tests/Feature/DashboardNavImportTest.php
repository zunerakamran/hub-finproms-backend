<?php

namespace Tests\Feature;

use App\Models\Hub;
use App\Models\User;
use App\Services\CapabilitiesMatrixService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardNavImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'hub.current_slug' => 'central',
            'hub.type' => 'central',
            'hub.is_control_plane' => true,
        ]);
    }

    public function test_can_list_import_sources_and_import_dashboard_nav_from_another_hub(): void
    {
        Hub::query()->create([
            'name' => 'Central Hub Controller',
            'slug' => 'central',
            'type' => Hub::TYPE_CENTRAL,
            'is_active' => true,
            'checklist' => Hub::defaultChecklist(Hub::TYPE_CENTRAL),
            'role_capabilities' => app(CapabilitiesMatrixService::class)
                ->defaultRoleCapabilities(Hub::TYPE_CENTRAL),
        ]);

        $source = Hub::query()->create([
            'name' => 'Source Hub',
            'slug' => 'source-hub',
            'type' => Hub::TYPE_WHITE_LABEL,
            'is_active' => true,
            'checklist' => Hub::defaultChecklist(Hub::TYPE_WHITE_LABEL),
            'dashboard_nav' => [
                'sections' => [
                    'account' => 'Imported Account',
                ],
                'items' => [
                    '/my-dashboard/settings' => 'Imported Settings',
                ],
                'item_groups' => [
                    '/my-dashboard/settings' => 'platform',
                ],
            ],
        ]);

        $target = Hub::query()->create([
            'name' => 'Target Hub',
            'slug' => 'target-hub',
            'type' => Hub::TYPE_WHITE_LABEL,
            'is_active' => true,
            'checklist' => Hub::defaultChecklist(Hub::TYPE_WHITE_LABEL),
        ]);

        Sanctum::actingAs(User::factory()->powerAdmin()->create([
            'acting_hub_id' => $target->id,
        ]));

        $sources = $this->getJson('/api/client-admin/dashboard-nav/import-sources')
            ->assertOk()
            ->json('sources');

        $this->assertNotEmpty($sources);
        $this->assertTrue(collect($sources)->contains(fn ($row) => (int) $row['id'] === (int) $source->id));
        $this->assertFalse(collect($sources)->contains(fn ($row) => (int) $row['id'] === (int) $target->id));

        $this->postJson('/api/client-admin/dashboard-nav/import', [
            'source_hub_id' => $source->id,
        ])
            ->assertOk()
            ->assertJsonPath('source_hub.slug', 'source-hub')
            ->assertJsonPath('hub.slug', 'target-hub');

        $target->refresh();
        $resolved = $target->resolvedDashboardNav();
        $this->assertSame('Imported Account', $resolved['sections']['account']);
        $this->assertSame('Imported Settings', $resolved['items']['/my-dashboard/settings']);
        $this->assertSame('platform', $resolved['item_groups']['/my-dashboard/settings']);
    }

    public function test_cannot_import_from_same_hub(): void
    {
        Hub::query()->create([
            'name' => 'Central Hub Controller',
            'slug' => 'central',
            'type' => Hub::TYPE_CENTRAL,
            'is_active' => true,
            'checklist' => Hub::defaultChecklist(Hub::TYPE_CENTRAL),
            'role_capabilities' => app(CapabilitiesMatrixService::class)
                ->defaultRoleCapabilities(Hub::TYPE_CENTRAL),
        ]);

        $hub = Hub::query()->create([
            'name' => 'Only Hub',
            'slug' => 'only-hub',
            'type' => Hub::TYPE_WHITE_LABEL,
            'is_active' => true,
            'checklist' => Hub::defaultChecklist(Hub::TYPE_WHITE_LABEL),
        ]);

        Sanctum::actingAs(User::factory()->powerAdmin()->create([
            'acting_hub_id' => $hub->id,
        ]));

        $this->postJson('/api/client-admin/dashboard-nav/import', [
            'source_hub_id' => $hub->id,
        ])->assertStatus(422);
    }
}
