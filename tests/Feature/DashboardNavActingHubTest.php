<?php

namespace Tests\Feature;

use App\Models\Hub;
use App\Models\User;
use App\Services\CapabilitiesMatrixService;
use App\Services\WhiteLabelDatabaseService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardNavActingHubTest extends TestCase
{
    use RefreshDatabase;

    private ?string $remoteSqlitePath = null;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'hub.current_slug' => 'central',
            'hub.type' => 'central',
            'hub.is_control_plane' => true,
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->remoteSqlitePath && is_file($this->remoteSqlitePath)) {
            @unlink($this->remoteSqlitePath);
        }

        parent::tearDown();
    }

    public function test_get_hub_overlays_acting_hub_dashboard_nav_labels(): void
    {
        $central = Hub::query()->create([
            'name' => 'Central Hub Controller',
            'slug' => 'central',
            'type' => Hub::TYPE_CENTRAL,
            'is_active' => true,
            'checklist' => Hub::defaultChecklist(Hub::TYPE_CENTRAL),
            'role_capabilities' => app(CapabilitiesMatrixService::class)
                ->defaultRoleCapabilities(Hub::TYPE_CENTRAL),
            'dashboard_nav' => [
                'sections' => [
                    'account' => 'Central Account',
                ],
                'items' => [
                    '/my-dashboard/settings' => 'Central Settings',
                ],
            ],
        ]);

        $hub = $this->makeWiredWhiteLabelHub([
            'dashboard_nav' => [
                'sections' => [
                    'account' => 'Firm Account',
                ],
                'items' => [
                    '/my-dashboard/settings' => 'Firm Settings',
                ],
            ],
        ]);

        Sanctum::actingAs(User::factory()->powerAdmin()->create([
            'acting_hub_id' => $hub->id,
        ]));

        $response = $this->getJson('/api/hub')->assertOk();
        $this->assertSame('Firm Account', data_get($response->json(), 'hub.dashboard_nav.sections.account'));
        $this->assertSame(
            'Firm Settings',
            data_get($response->json(), 'hub.dashboard_nav.items./my-dashboard/settings')
        );
        $this->assertSame(
            'Firm Account',
            data_get($response->json(), 'hub.hub_switcher.dashboard_nav.sections.account')
        );
        $this->assertSame(
            'Firm Account',
            data_get($response->json(), 'hub.hub_switcher.acting_hub.dashboard_nav.sections.account')
        );

        // Central's own stored labels must not leak while a content hub is selected.
        $this->assertSame('Central Account', $central->fresh()->resolvedDashboardNav()['sections']['account']);
    }

    public function test_settings_dashboard_nav_update_on_acting_hub_appears_on_get_hub(): void
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

        $hub = $this->makeWiredWhiteLabelHub();

        Sanctum::actingAs(User::factory()->powerAdmin()->create([
            'acting_hub_id' => $hub->id,
        ]));

        $update = $this->putJson('/api/client-admin/dashboard-nav', [
            'dashboard_nav' => [
                'sections' => [
                    'content' => 'Custom SM Section',
                ],
                'items' => [
                    '/my-dashboard/posts' => 'Custom Posts Label',
                ],
            ],
        ])->assertOk();
        $this->assertSame(
            'Custom SM Section',
            data_get($update->json(), 'dashboard_nav.sections.content')
        );
        $this->assertSame(
            'Custom Posts Label',
            data_get($update->json(), 'dashboard_nav.items./my-dashboard/posts')
        );

        $hubResponse = $this->getJson('/api/hub')->assertOk();
        $this->assertSame(
            'Custom SM Section',
            data_get($hubResponse->json(), 'hub.dashboard_nav.sections.content')
        );
        $this->assertSame(
            'Custom Posts Label',
            data_get($hubResponse->json(), 'hub.dashboard_nav.items./my-dashboard/posts')
        );
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function makeWiredWhiteLabelHub(array $extra = []): Hub
    {
        $this->remoteSqlitePath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wl-nav-'.uniqid('', true).'.sqlite';
        touch($this->remoteSqlitePath);

        $hub = Hub::query()->create(array_merge([
            'name' => 'My Hub',
            'slug' => 'myhub',
            'type' => Hub::TYPE_WHITE_LABEL,
            'is_active' => true,
            'checklist' => Hub::defaultChecklist(Hub::TYPE_WHITE_LABEL),
            'frontend_url' => 'https://myhub.fin-proms.com',
            'db_driver' => 'sqlite',
            'db_host' => 'localhost',
            'db_port' => 3306,
            'db_database' => $this->remoteSqlitePath,
            'db_username' => 'test',
            'db_password' => 'secret',
        ], $extra));

        $remote = app(WhiteLabelDatabaseService::class);
        $connection = $remote->connect($hub);

        Schema::connection($connection)->create('hubs', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('type')->default('white_label');
            $table->boolean('is_active')->default(true);
            $table->string('primary_color')->nullable();
            $table->string('secondary_color')->nullable();
            $table->string('accent_color')->nullable();
            $table->string('logo_url')->nullable();
            $table->string('white_logo_url')->nullable();
            $table->string('favicon_url')->nullable();
            $table->string('auth_bg_image_url')->nullable();
            $table->json('page_content')->nullable();
            $table->json('dashboard_nav')->nullable();
            $table->string('from_email')->nullable();
            $table->string('frontend_url')->nullable();
            $table->json('checklist')->nullable();
            $table->json('role_capabilities')->nullable();
            $table->json('role_display_names')->nullable();
            $table->json('compliance_status_display_names')->nullable();
            $table->timestamps();
        });

        DB::connection($connection)->table('hubs')->insert([
            'name' => 'My Hub',
            'slug' => 'myhub',
            'type' => Hub::TYPE_WHITE_LABEL,
            'is_active' => true,
            'frontend_url' => 'https://myhub.fin-proms.com',
            'checklist' => json_encode(Hub::defaultChecklist(Hub::TYPE_WHITE_LABEL)),
            'dashboard_nav' => isset($extra['dashboard_nav'])
                ? json_encode($extra['dashboard_nav'])
                : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $remote->disconnect($hub);

        return $hub;
    }
}
