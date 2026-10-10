<?php

namespace Tests\Feature;

use App\Models\Hub;
use App\Models\User;
use App\Services\CapabilitiesMatrixService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PageContentImportTest extends TestCase
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

    public function test_can_import_page_content_from_another_hub(): void
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
            'page_content' => [
                'home' => [
                    'title' => 'Imported Hero Title',
                    'nav_tagline' => 'Imported tagline',
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

        $this->getJson('/api/client-admin/page-content/import-sources')
            ->assertOk()
            ->assertJsonFragment(['slug' => 'source-hub']);

        $this->postJson('/api/client-admin/page-content/import', [
            'source_hub_id' => $source->id,
        ])
            ->assertOk()
            ->assertJsonPath('source_hub.slug', 'source-hub')
            ->assertJsonPath('page_content.home.title', 'Imported Hero Title')
            ->assertJsonPath('page_content.home.nav_tagline', 'Imported tagline');

        $target->refresh();
        $this->assertSame('Imported Hero Title', $target->resolvedPageContent()['home']['title']);
    }
}
