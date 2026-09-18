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

class RoleDisplayNameTest extends TestCase
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

    public function test_hub_endpoint_exposes_default_role_labels(): void
    {
        $this->createSharedHub();

        $this->getJson('/api/hub')
            ->assertOk()
            ->assertJsonPath('hub.role_labels.advisor', 'Advisor')
            ->assertJsonPath('hub.role_labels.client_admin', 'Client Admin');
    }

    public function test_authorized_role_can_update_display_names_on_shared_hub(): void
    {
        $hub = $this->createSharedHub();
        $admin = User::factory()->powerAdmin()->create();
        Sanctum::actingAs($admin);

        $response = $this->putJson('/api/client-admin/role-display-names', [
            'roles' => [
                'advisor' => 'IFA',
                'manager' => 'Team Lead',
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('role_labels.advisor', 'IFA')
            ->assertJsonPath('role_labels.manager', 'Team Lead')
            ->assertJsonPath('role_labels.user', 'User');

        $hub->refresh();
        $this->assertSame('IFA', $hub->role_display_names['advisor']);
        $this->assertSame('Team Lead', $hub->role_display_names['manager']);

        $this->getJson('/api/hub')
            ->assertOk()
            ->assertJsonPath('hub.role_labels.advisor', 'IFA');
    }

    public function test_role_without_capability_cannot_update_display_names(): void
    {
        $hub = $this->createSharedHub();
        $matrix = app(CapabilitiesMatrixService::class);
        $roleCaps = $matrix->resolvedRoleCapabilities($hub);
        $roleCaps[User::ROLE_USER]['dashboard_manage_role_display_names'] = false;
        $hub->role_capabilities = $roleCaps;
        $hub->save();

        $user = User::factory()->create(['role' => User::ROLE_USER]);
        Sanctum::actingAs($user);

        $this->putJson('/api/client-admin/role-display-names', [
            'roles' => ['advisor' => 'IFA'],
        ])->assertForbidden();
    }

    public function test_updating_white_label_role_display_names_syncs_to_remote_database(): void
    {
        [$admin, $hub] = $this->actingPowerAdminOnWiredHub();

        $response = $this->putJson('/api/client-admin/role-display-names', [
            'roles' => [
                'advisor' => 'Partner',
                'client_admin' => 'Firm Admin',
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('role_labels.advisor', 'Partner')
            ->assertJsonPath('hub.slug', 'myhub');

        $hub->refresh();
        $this->assertSame('Partner', $hub->role_display_names['advisor']);

        $remote = app(WhiteLabelDatabaseService::class);
        $connection = $remote->connect($hub);
        try {
            $row = DB::connection($connection)->table('hubs')->where('slug', 'myhub')->first();
            $this->assertNotNull($row);
            $names = is_string($row->role_display_names)
                ? json_decode($row->role_display_names, true)
                : (array) $row->role_display_names;
            $this->assertSame('Partner', $names['advisor'] ?? null);
            $this->assertSame('Firm Admin', $names['client_admin'] ?? null);
        } finally {
            $remote->disconnect($hub);
        }
    }

    public function test_capabilities_matrix_uses_custom_role_labels(): void
    {
        $hub = $this->createSharedHub([
            'role_display_names' => ['approver' => 'Compliance Officer'],
        ]);
        $admin = User::factory()->powerAdmin()->create();
        Sanctum::actingAs($admin);

        $this->getJson('/api/power-admin/capabilities/matrix?hub_id='.$hub->id)
            ->assertOk()
            ->assertJsonFragment(['key' => 'approver', 'label' => 'Compliance Officer']);
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

    private function actingPowerAdminOnWiredHub(): array
    {
        $this->createSharedHub();
        $hub = $this->makeWiredWhiteLabelHub();

        $admin = User::factory()->powerAdmin()->create([
            'acting_hub_id' => $hub->id,
        ]);
        Sanctum::actingAs($admin);

        return [$admin, $hub];
    }

    private function makeWiredWhiteLabelHub(): Hub
    {
        $this->remoteSqlitePath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wl-roles-'.uniqid('', true).'.sqlite';
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
            $table->json('role_display_names')->nullable();
            $table->unsignedInteger('subscriber_credits')->nullable();
            $table->unsignedTinyInteger('advisor_billing_renew_day')->nullable();
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
