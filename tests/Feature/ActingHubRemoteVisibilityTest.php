<?php

namespace Tests\Feature;

use App\Models\Hub;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\CapabilitiesMatrixService;
use App\Services\HubService;
use App\Services\WhiteLabelDatabaseService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Central hub switcher must see Shared / WL hub data written on the remounted
 * content-hub DB — even when the content hub has a user with the same numeric
 * id as the Central Power Admin (common: both #1).
 */
class ActingHubRemoteVisibilityTest extends TestCase
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

    public function test_central_power_admin_sees_support_tickets_on_acting_white_label_hub_despite_user_id_collision(): void
    {
        $this->seedCentralControlPlane();
        $hub = $this->makeWiredContentHub(Hub::TYPE_WHITE_LABEL, 'wl-tickets');

        $this->enableSupportTicketsOnHub($hub);

        $remote = app(WhiteLabelDatabaseService::class);
        $connection = $remote->connect($hub);
        try {
            $this->createRemoteUserTables($connection);
            // Content-hub user #1 is an advisor — same numeric id as Central PA below.
            DB::connection($connection)->table('users')->insert([
                'id' => 1,
                'name' => 'WL Advisor',
                'email' => 'advisor-wl@example.com',
                'password' => Hash::make('password12'),
                'role' => User::ROLE_ADVISOR,
                'credits' => 0,
                'is_advisor' => 1,
                'has_unlimited_credits' => 0,
                'is_suspended' => 0,
                'is_discontinued' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::connection($connection)->table('user_roles')->insert([
                'user_id' => 1,
                'role' => User::ROLE_ADVISOR,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->createRemoteSupportTicketTables($connection);

            $ticketId = DB::connection($connection)->table('support_tickets')->insertGetId([
                'user_id' => 1,
                'subject' => 'WL ticket from content hub',
                'module_area' => 'dashboard',
                'category' => SupportTicket::CATEGORY_BUG,
                'priority' => SupportTicket::PRIORITY_HIGH,
                'description' => 'Created on the white-label database.',
                'status' => SupportTicket::STATUS_OPEN,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } finally {
            $remote->disconnect($hub);
        }

        $this->assertDatabaseMissing('support_tickets', [
            'subject' => 'WL ticket from content hub',
        ]);

        $admin = User::factory()->powerAdmin()->create([
            'acting_hub_id' => $hub->id,
        ]);
        $this->assertSame(1, (int) $admin->id, 'Regression requires Central PA id to collide with remote user #1.');
        Sanctum::actingAs($admin);

        $this->getJson('/api/power-admin/support-tickets')
            ->assertOk()
            ->assertJsonPath('data.0.id', $ticketId)
            ->assertJsonPath('data.0.subject', 'WL ticket from content hub');
    }

    public function test_central_power_admin_sees_support_tickets_on_acting_shared_hub_despite_user_id_collision(): void
    {
        $this->seedCentralControlPlane();
        $hub = $this->makeWiredContentHub(Hub::TYPE_SHARED, 'shared-tickets');

        $this->enableSupportTicketsOnHub($hub);

        $remote = app(WhiteLabelDatabaseService::class);
        $connection = $remote->connect($hub);
        try {
            $this->createRemoteUserTables($connection);
            DB::connection($connection)->table('users')->insert([
                'id' => 1,
                'name' => 'Shared User',
                'email' => 'user-shared@example.com',
                'password' => Hash::make('password12'),
                'role' => User::ROLE_USER,
                'credits' => 0,
                'is_advisor' => 0,
                'has_unlimited_credits' => 0,
                'is_suspended' => 0,
                'is_discontinued' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::connection($connection)->table('user_roles')->insert([
                'user_id' => 1,
                'role' => User::ROLE_USER,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->createRemoteSupportTicketTables($connection);

            $ticketId = DB::connection($connection)->table('support_tickets')->insertGetId([
                'user_id' => 1,
                'subject' => 'Shared hub ticket',
                'module_area' => 'authentication',
                'category' => SupportTicket::CATEGORY_ACCESS,
                'priority' => SupportTicket::PRIORITY_MEDIUM,
                'description' => 'Created on the shared hub database.',
                'status' => SupportTicket::STATUS_OPEN,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } finally {
            $remote->disconnect($hub);
        }

        $admin = User::factory()->powerAdmin()->create([
            'acting_hub_id' => $hub->id,
        ]);
        $this->assertSame(1, (int) $admin->id);
        Sanctum::actingAs($admin);

        $this->getJson('/api/power-admin/support-tickets')
            ->assertOk()
            ->assertJsonPath('data.0.id', $ticketId)
            ->assertJsonPath('data.0.subject', 'Shared hub ticket');
    }

    private function seedCentralControlPlane(): void
    {
        $matrix = app(CapabilitiesMatrixService::class);
        Hub::query()->create([
            'name' => 'Central Hub Controller',
            'slug' => 'central',
            'type' => Hub::TYPE_CENTRAL,
            'is_active' => true,
            'checklist' => array_merge(Hub::defaultChecklist(Hub::TYPE_CENTRAL), [
                'dashboard_control_white_label_hubs' => true,
            ]),
            'role_capabilities' => $matrix->defaultRoleCapabilities(Hub::TYPE_CENTRAL),
        ]);
        app(HubService::class)->forgetCurrentCache();
    }

    private function enableSupportTicketsOnHub(Hub $hub): void
    {
        $matrix = app(CapabilitiesMatrixService::class);
        $checklist = $hub->resolvedChecklist();
        $checklist['support_tickets'] = true;

        $caps = $matrix->resolvedRoleCapabilities($hub);
        foreach (Hub::SUPPORT_TICKETS_CAPABILITY_KEYS as $key) {
            $caps[User::ROLE_POWER_ADMIN][$key] = true;
            $caps[User::ROLE_FINPROMS_ADMIN][$key] = true;
        }

        $hub->forceFill([
            'checklist' => $checklist,
            'role_capabilities' => $caps,
        ])->save();
    }

    private function makeWiredContentHub(string $type, string $slug): Hub
    {
        $this->remoteSqlitePath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'acting-vis-'.uniqid('', true).'.sqlite';
        touch($this->remoteSqlitePath);

        $hub = Hub::query()->create([
            'name' => $type === Hub::TYPE_SHARED ? 'Shared Tickets Hub' : 'WL Tickets Hub',
            'slug' => $slug,
            'type' => $type,
            'is_active' => true,
            'checklist' => Hub::defaultChecklist($type),
            'frontend_url' => 'https://'.$slug.'.fin-proms.com',
            'db_driver' => 'sqlite',
            'db_host' => 'localhost',
            'db_port' => 3306,
            'db_database' => $this->remoteSqlitePath,
            'db_username' => 'test',
            'db_password' => 'secret',
        ]);

        $remote = app(WhiteLabelDatabaseService::class);
        $connection = $remote->connect($hub);
        try {
            Schema::connection($connection)->create('hubs', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('slug')->unique();
                $table->string('type')->default('white_label');
                $table->boolean('is_active')->default(true);
                $table->json('checklist')->nullable();
                $table->json('role_capabilities')->nullable();
                $table->timestamps();
            });

            DB::connection($connection)->table('hubs')->insert([
                'name' => $hub->name,
                'slug' => $hub->slug,
                'type' => $hub->type,
                'is_active' => 1,
                'checklist' => json_encode($hub->resolvedChecklist()),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } finally {
            $remote->disconnect($hub);
        }

        return $hub;
    }

    private function createRemoteUserTables(string $connection): void
    {
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

        Schema::connection($connection)->create('user_roles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('role', 64);
            $table->timestamps();
            $table->unique(['user_id', 'role']);
        });
    }

    private function createRemoteSupportTicketTables(string $connection): void
    {
        Schema::connection($connection)->create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('subject', 255);
            $table->string('module_area', 100);
            $table->string('category', 50)->default('bug');
            $table->string('priority', 20)->default('medium');
            $table->longText('description');
            $table->string('status', 40)->default('Open');
            $table->string('page_url', 500)->nullable();
            $table->string('browser_info', 500)->nullable();
            $table->text('status_note')->nullable();
            $table->unsignedBigInteger('status_changed_by')->nullable();
            $table->timestamp('status_changed_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });

        Schema::connection($connection)->create('support_ticket_attachments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ticket_id');
            $table->string('original_name');
            $table->string('file_path', 500);
            $table->string('file_url', 500)->nullable();
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->unsignedBigInteger('uploaded_by_user_id')->nullable();
            $table->string('uploaded_by_name')->nullable();
            $table->timestamps();
        });

        Schema::connection($connection)->create('support_ticket_comments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ticket_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('author_name')->nullable();
            $table->text('body');
            $table->string('from_status', 40)->nullable();
            $table->string('to_status', 40)->nullable();
            $table->timestamps();
        });
    }
}
