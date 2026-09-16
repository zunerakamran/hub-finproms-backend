<?php

namespace Tests\Feature;

use App\Models\Hub;
use App\Models\User;
use App\Services\WhiteLabelDatabaseService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WhiteLabelControlPlaneTest extends TestCase
{
    use RefreshDatabase;

    private ?string $remoteSqlitePath = null;

    protected function tearDown(): void
    {
        if ($this->remoteSqlitePath && is_file($this->remoteSqlitePath)) {
            @unlink($this->remoteSqlitePath);
        }

        parent::tearDown();
    }

    public function test_creating_a_user_while_acting_on_a_white_label_hub_writes_to_the_remote_database(): void
    {
        [$admin, $hub] = $this->actingPowerAdminOnWiredHub();

        $response = $this->postJson('/api/power-admin/users', [
            'name' => 'WL Manager',
            'email' => 'wl-manager@example.com',
            'password' => 'password12',
            'role' => User::ROLE_MANAGER,
        ]);

        $response->assertCreated()
            ->assertJsonPath('acting_on_white_label', true)
            ->assertJsonPath('user.email', 'wl-manager@example.com')
            ->assertJsonPath('target_hub.slug', 'myhub');

        $this->assertDatabaseMissing('users', ['email' => 'wl-manager@example.com']);

        $remote = app(WhiteLabelDatabaseService::class);
        $connection = $remote->connect($hub);
        try {
            $this->assertTrue(
                DB::connection($connection)->table('users')->where('email', 'wl-manager@example.com')->exists()
            );
        } finally {
            $remote->disconnect($hub);
        }
    }

    public function test_switching_a_white_label_hub_to_public_updates_the_remote_hub_checklist(): void
    {
        [$admin, $hub] = $this->actingPowerAdminOnWiredHub();

        $response = $this->putJson('/api/power-admin/hubs/'.$hub->id.'/checklist', [
            'checklist' => [
                'public_subscribe' => true,
                'private_invite_only' => false,
            ],
        ]);

        $response->assertOk();

        $hub->refresh();
        $this->assertFalse((bool) $hub->resolvedChecklist()['private_invite_only']);
        $this->assertTrue((bool) $hub->resolvedChecklist()['public_subscribe']);

        $remote = app(WhiteLabelDatabaseService::class);
        $connection = $remote->connect($hub);
        try {
            $row = DB::connection($connection)->table('hubs')->where('slug', 'myhub')->first();
            $this->assertNotNull($row);
            $checklist = is_string($row->checklist) ? json_decode($row->checklist, true) : (array) $row->checklist;
            $this->assertFalse((bool) ($checklist['private_invite_only'] ?? true));
            $this->assertTrue((bool) ($checklist['public_subscribe'] ?? false));
        } finally {
            $remote->disconnect($hub);
        }
    }

    public function test_power_admin_capabilities_follow_selected_white_label_hub(): void
    {
        [$admin, $hub] = $this->actingPowerAdminOnWiredHub();
        $shared = Hub::query()->where('slug', 'shared')->firstOrFail();

        $this->putJson('/api/power-admin/capabilities/matrix', [
            'hub_id' => $hub->id,
            'matrix' => [
                'power_admin' => [
                    'dashboard_manage_posts' => false,
                    'smc_view_reports' => false,
                    'advisor_excel_import' => false,
                    'dashboard_manage_advisor_pricing' => false,
                    'dashboard_manage_advisor_renewal' => false,
                ],
            ],
        ])->assertOk();

        $shared->refresh();
        $hub->refresh();
        $this->assertFalse((bool) data_get($hub->role_capabilities, 'power_admin.dashboard_manage_posts'));
        $this->assertArrayNotHasKey('power_admin', $shared->role_capabilities ?? []);

        $this->getJson('/api/hub')
            ->assertOk()
            ->assertJsonPath('hub.hub_switcher.effective_capabilities.dashboard_manage_posts', false);

        $this->putJson('/api/power-admin/modules', [
            'modules' => [
                'module_social_media_compliance' => true,
                'module_general_compliance' => true,
                'module_website_compliance' => true,
            ],
        ])->assertOk();

        $this->putJson('/api/power-admin/capabilities/matrix', [
            'hub_id' => $hub->id,
            'matrix' => [
                'power_admin' => [
                    'dashboard_manage_posts' => true,
                    'smc_view_reports' => true,
                    'gc_view_reports' => true,
                    'wc_view_platform_report' => true,
                    'advisor_excel_import' => true,
                    'dashboard_manage_advisor_pricing' => true,
                    'dashboard_manage_advisor_renewal' => true,
                    'dashboard_view_advisor_invoices' => true,
                    'dashboard_manage_subscriber_credits' => true,
                ],
                'client_admin' => [
                    'dashboard_manage_posts' => true,
                ],
            ],
        ])->assertOk()
            ->assertJsonPath('matrix.control_plane_roles.0', 'power_admin');

        $shared->refresh();
        $hub->refresh();
        $this->assertTrue((bool) data_get($hub->role_capabilities, 'power_admin.dashboard_manage_posts'));
        $this->assertTrue((bool) data_get($hub->role_capabilities, 'power_admin.smc_view_reports'));
        $this->assertTrue((bool) data_get($hub->role_capabilities, 'power_admin.advisor_excel_import'));
        $this->assertTrue((bool) data_get($hub->role_capabilities, 'power_admin.dashboard_manage_advisor_pricing'));
        $this->assertTrue((bool) data_get($hub->role_capabilities, 'power_admin.dashboard_manage_advisor_renewal'));
        $this->assertTrue((bool) data_get($hub->role_capabilities, 'client_admin.dashboard_manage_posts'));
        $this->assertNull(data_get($shared->role_capabilities, 'power_admin.dashboard_manage_posts'));

        $this->getJson('/api/hub')
            ->assertOk()
            ->assertJsonPath('hub.hub_switcher.effective_capabilities.dashboard_manage_posts', true)
            ->assertJsonPath('hub.hub_switcher.effective_capabilities.smc_view_reports', true)
            ->assertJsonPath('hub.hub_switcher.effective_capabilities.gc_view_reports', true)
            ->assertJsonPath('hub.hub_switcher.effective_capabilities.wc_view_platform_report', true)
            ->assertJsonPath('hub.hub_switcher.effective_capabilities.advisor_excel_import', true)
            ->assertJsonPath('hub.hub_switcher.effective_capabilities.dashboard_manage_advisor_pricing', true)
            ->assertJsonPath('hub.hub_switcher.effective_capabilities.dashboard_manage_advisor_renewal', true)
            ->assertJsonPath('hub.hub_switcher.effective_capabilities.dashboard_view_advisor_invoices', true)
            ->assertJsonPath('hub.hub_switcher.effective_capabilities.dashboard_manage_subscriber_credits', true)
            ->assertJsonPath('hub.hub_switcher.effective_capabilities.module_social_media_compliance', true);

        $remote = app(WhiteLabelDatabaseService::class);
        $connection = $remote->connect($hub);
        try {
            $row = DB::connection($connection)->table('hubs')->where('slug', 'myhub')->first();
            $caps = is_string($row->role_capabilities)
                ? json_decode($row->role_capabilities, true)
                : (array) $row->role_capabilities;
            $this->assertTrue((bool) data_get($caps, 'power_admin.dashboard_manage_posts'));
            $this->assertTrue((bool) data_get($caps, 'power_admin.smc_view_reports'));
            $this->assertTrue((bool) data_get($caps, 'client_admin.dashboard_manage_posts'));
        } finally {
            $remote->disconnect($hub);
        }
    }

    public function test_modules_update_while_acting_on_white_label_writes_to_the_remote_database(): void
    {
        [$admin, $hub] = $this->actingPowerAdminOnWiredHub();
        $shared = Hub::query()->where('slug', 'shared')->firstOrFail();

        $this->putJson('/api/power-admin/modules', [
            'modules' => [
                'module_social_media_compliance' => true,
            ],
        ])->assertOk()
            ->assertJsonPath('hub.slug', 'myhub');

        $hub->refresh();
        $this->assertTrue((bool) $hub->resolvedChecklist()['module_social_media_compliance']);
        $shared->refresh();
        $this->assertFalse((bool) $shared->resolvedChecklist()['module_social_media_compliance']);

        $remote = app(WhiteLabelDatabaseService::class);
        $connection = $remote->connect($hub);
        try {
            $row = DB::connection($connection)->table('hubs')->where('slug', 'myhub')->first();
            $checklist = is_string($row->checklist) ? json_decode($row->checklist, true) : (array) $row->checklist;
            $this->assertTrue((bool) ($checklist['module_social_media_compliance'] ?? false));
        } finally {
            $remote->disconnect($hub);
        }
    }

    private function actingPowerAdminOnWiredHub(): array
    {
        Hub::query()->create([
            'name' => 'Shared Hub',
            'slug' => 'shared',
            'type' => Hub::TYPE_SHARED,
            'is_active' => true,
            'checklist' => Hub::defaultChecklist(Hub::TYPE_SHARED),
        ]);

        $hub = $this->makeWiredWhiteLabelHub();

        $admin = User::factory()->powerAdmin()->create([
            'acting_hub_id' => $hub->id,
        ]);
        Sanctum::actingAs($admin);

        return [$admin, $hub];
    }

    private function makeWiredWhiteLabelHub(): Hub
    {
        $this->remoteSqlitePath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wl-hub-'.uniqid('', true).'.sqlite';
        touch($this->remoteSqlitePath);

        $hub = Hub::query()->create([
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
        ]);

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
            $table->string('logo_url')->nullable();
            $table->string('favicon_url')->nullable();
            $table->string('from_email')->nullable();
            $table->string('frontend_url')->nullable();
            $table->json('checklist')->nullable();
            $table->json('role_capabilities')->nullable();
            $table->unsignedInteger('subscriber_credits')->nullable();
            $table->unsignedTinyInteger('advisor_billing_renew_day')->nullable();
            $table->timestamps();
        });

        Schema::connection($connection)->create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role')->default('user');
            $table->unsignedInteger('credits')->default(0);
            $table->boolean('is_advisor')->default(false);
            $table->boolean('has_unlimited_credits')->default(false);
            $table->boolean('is_suspended')->default(false);
            $table->boolean('is_discontinued')->default(false);
            $table->timestamp('discontinued_at')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        DB::connection($connection)->table('hubs')->insert([
            'name' => 'My Hub',
            'slug' => 'myhub',
            'type' => Hub::TYPE_WHITE_LABEL,
            'is_active' => 1,
            'checklist' => json_encode(Hub::defaultChecklist(Hub::TYPE_WHITE_LABEL)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $remote->disconnect($hub);

        return $hub;
    }
}
