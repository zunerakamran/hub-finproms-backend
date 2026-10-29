<?php

namespace Tests\Feature;

use App\Models\AdvisorImportBatch;
use App\Models\Firm;
use App\Models\Hub;
use App\Models\User;
use App\Services\ActingHubService;
use App\Services\AdvisorImportHistoryService;
use App\Services\AdvisorImportService;
use App\Services\CapabilitiesMatrixService;
use App\Services\HubService;
use App\Services\WhiteLabelDatabaseService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\Support\ExcelUploadFactory;
use Tests\TestCase;

class PowerAdminAdvisorImportSubmissionsTest extends TestCase
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

    public function test_power_admin_acting_on_private_shared_sees_pending_with_view_only(): void
    {
        [$hub, $admin] = $this->actingPowerAdminOnPrivateShared([
            'advisor_excel_import' => false,
            'advisor_excel_template' => false,
            'advisor_excel_submit' => false,
            'advisor_excel_view_submissions' => true,
        ]);

        $batchId = $this->insertRemotePendingSubmission($hub, [
            'email' => 'sam-acting@example.com',
            'name' => 'Sam',
            'filename' => 'acting-pa.xlsx',
        ]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/hub')
            ->assertOk()
            ->assertJsonPath('hub.hub_switcher.effective_capabilities.advisor_excel_view_submissions', true)
            ->assertJsonPath('hub.hub_switcher.effective_capabilities.advisor_excel_import', false);

        $this->getJson('/api/power-admin/advisors/import-history')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('batches.0.id', $batchId)
            ->assertJsonPath('batches.0.status', 'pending')
            ->assertJsonPath('batches.0.pending_users.0.email', 'sam-acting@example.com')
            ->assertJsonPath('capabilities.can_view_submissions', true)
            ->assertJsonPath('capabilities.can_import', false);
    }

    public function test_power_admin_without_view_or_import_hides_others_pending(): void
    {
        [$hub, $admin] = $this->actingPowerAdminOnPrivateShared([
            'advisor_excel_import' => false,
            'advisor_excel_template' => true,
            'advisor_excel_submit' => false,
            'advisor_excel_view_submissions' => false,
        ]);

        $this->insertRemotePendingSubmission($hub, [
            'email' => 'other-acting@example.com',
            'name' => 'Other',
            'filename' => 'hidden-pa.xlsx',
        ]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/power-admin/advisors/import-history')
            ->assertOk()
            ->assertJsonPath('meta.total', 0)
            ->assertJsonPath('capabilities.can_view_submissions', false);
    }

    public function test_power_admin_acting_without_remote_db_does_not_silently_read_central(): void
    {
        $matrix = app(CapabilitiesMatrixService::class);

        Hub::query()->create([
            'name' => 'Central',
            'slug' => 'central',
            'type' => Hub::TYPE_CENTRAL,
            'is_active' => true,
            'checklist' => Hub::defaultChecklist(Hub::TYPE_CENTRAL),
            'role_capabilities' => $matrix->defaultRoleCapabilities(Hub::TYPE_CENTRAL),
        ]);

        $hub = Hub::query()->create([
            'name' => 'Shared Private Unwired',
            'slug' => 'shared-unwired',
            'type' => Hub::TYPE_SHARED,
            'is_active' => true,
            'checklist' => array_merge(Hub::defaultChecklist(Hub::TYPE_SHARED), [
                'private_invite_only' => true,
                'public_subscribe' => false,
            ]),
        ]);

        $roleCaps = $matrix->resolvedRoleCapabilities($hub);
        $roleCaps[User::ROLE_POWER_ADMIN]['advisor_excel_view_submissions'] = true;
        $roleCaps[User::ROLE_POWER_ADMIN]['advisor_excel_template'] = true;
        $hub->role_capabilities = $roleCaps;
        $hub->save();
        $matrix->forgetResolvedCaches();
        app(HubService::class)->forgetCurrentCache();

        $admin = User::factory()->powerAdmin()->create([
            'acting_hub_id' => $hub->id,
        ]);
        Sanctum::actingAs($admin);

        $this->getJson('/api/power-admin/advisors/import-history')
            ->assertStatus(422)
            ->assertJsonPath('requires_remote_database', true);
    }

    public function test_power_admin_on_content_hub_sees_client_admin_pending_submission(): void
    {
        // Same /my-dashboard/advisors page on a content hub deploy (not Central).
        // Client admin submits; power_admin with View (+ Import) must see Pending.
        config([
            'hub.current_slug' => 'shared',
            'hub.type' => 'shared',
            'hub.is_control_plane' => false,
        ]);

        $matrix = app(CapabilitiesMatrixService::class);

        $hub = Hub::query()->create([
            'name' => 'Private Shared',
            'slug' => 'shared',
            'type' => Hub::TYPE_SHARED,
            'is_active' => true,
            'checklist' => array_merge(Hub::defaultChecklist(Hub::TYPE_SHARED), [
                'private_invite_only' => true,
                'public_subscribe' => false,
            ]),
        ]);

        $roleCaps = $matrix->resolvedRoleCapabilities($hub);
        $roleCaps[User::ROLE_CLIENT_ADMIN]['advisor_excel_import'] = false;
        $roleCaps[User::ROLE_CLIENT_ADMIN]['advisor_excel_template'] = true;
        $roleCaps[User::ROLE_CLIENT_ADMIN]['advisor_excel_submit'] = true;
        $roleCaps[User::ROLE_CLIENT_ADMIN]['advisor_excel_view_submissions'] = false;
        $roleCaps[User::ROLE_POWER_ADMIN]['advisor_excel_import'] = true;
        $roleCaps[User::ROLE_POWER_ADMIN]['advisor_excel_template'] = true;
        $roleCaps[User::ROLE_POWER_ADMIN]['advisor_excel_submit'] = false;
        $roleCaps[User::ROLE_POWER_ADMIN]['advisor_excel_view_submissions'] = true;
        $hub->role_capabilities = $roleCaps;
        $hub->save();
        $matrix->forgetResolvedCaches();
        app(HubService::class)->forgetCurrentCache();

        Firm::query()->create(['name' => 'Acme Wealth']);

        $clientAdmin = User::factory()->create(['role' => User::ROLE_CLIENT_ADMIN]);
        $powerAdmin = User::factory()->powerAdmin()->create();

        $file = ExcelUploadFactory::make([
            ['name', 'email', 'password', 'role', 'firm'],
            ['Pat', 'pat-pending@example.com', '', 'User', 'Acme Wealth'],
        ]);
        $plan = app(AdvisorImportService::class)->buildPlan($file, $hub);
        $storedPath = 'advisor-imports/submissions/ca-pending.xlsx';
        \Illuminate\Support\Facades\Storage::disk('local')->put($storedPath, 'fake-xlsx');
        $batch = app(AdvisorImportHistoryService::class)->recordSubmission(
            $hub,
            $clientAdmin,
            $plan,
            $file,
            $storedPath
        );

        Sanctum::actingAs($clientAdmin);
        $this->getJson('/api/client-admin/advisors/import-history')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('batches.0.id', $batch->id)
            ->assertJsonPath('batches.0.status', 'pending');

        Sanctum::actingAs($powerAdmin);

        // Must work on content hub via /api/power-admin (not blocked by control_plane).
        $this->getJson('/api/power-admin/advisors/import-history')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('batches.0.id', $batch->id)
            ->assertJsonPath('batches.0.status', 'pending')
            ->assertJsonPath('batches.0.pending_users.0.email', 'pat-pending@example.com')
            ->assertJsonPath('batches.0.can_download_submission', true)
            ->assertJsonPath('batches.0.can_import_submission', true)
            ->assertJsonPath('capabilities.can_view_submissions', true)
            ->assertJsonPath('capabilities.can_import', true);

        $this->get('/api/power-admin/advisors/import-submissions/'.$batch->id.'/download')
            ->assertOk();
    }

    public function test_power_admin_acting_on_wired_white_label_sees_remote_pending_submissions(): void
    {
        $matrix = app(CapabilitiesMatrixService::class);

        Hub::query()->create([
            'name' => 'Central',
            'slug' => 'central',
            'type' => Hub::TYPE_CENTRAL,
            'is_active' => true,
            'checklist' => Hub::defaultChecklist(Hub::TYPE_CENTRAL),
            'role_capabilities' => $matrix->defaultRoleCapabilities(Hub::TYPE_CENTRAL),
        ]);

        $hub = $this->makeWiredPrivateWhiteLabelHub();

        $roleCaps = $matrix->resolvedRoleCapabilities($hub);
        $roleCaps[User::ROLE_POWER_ADMIN]['advisor_excel_import'] = false;
        $roleCaps[User::ROLE_POWER_ADMIN]['advisor_excel_template'] = false;
        $roleCaps[User::ROLE_POWER_ADMIN]['advisor_excel_submit'] = false;
        $roleCaps[User::ROLE_POWER_ADMIN]['advisor_excel_view_submissions'] = true;
        $hub->role_capabilities = $roleCaps;
        $hub->save();
        $matrix->forgetResolvedCaches();
        app(HubService::class)->forgetCurrentCache();

        $admin = User::factory()->powerAdmin()->create([
            'acting_hub_id' => $hub->id,
        ]);

        $remote = app(WhiteLabelDatabaseService::class);
        $connection = $remote->connect($hub);
        try {
            $remoteHubId = (int) DB::connection($connection)->table('hubs')->where('slug', 'myhub')->value('id');
            $this->assertGreaterThan(0, $remoteHubId);

            $batchId = DB::connection($connection)->table('advisor_import_batches')->insertGetId([
                'hub_id' => $remoteHubId,
                'imported_by_user_id' => 99,
                'imported_by_name' => 'WL Manager',
                'imported_by_email' => 'manager@myhub.test',
                'original_filename' => 'pending-users.xlsx',
                'stored_path' => 'advisor-imports/submissions/remote-pending.xlsx',
                'status' => AdvisorImportBatch::STATUS_PENDING,
                'kind' => AdvisorImportBatch::KIND_SUBMISSION,
                'created_count' => 1,
                'updated_count' => 0,
                'reactivated_count' => 0,
                'skipped_count' => 0,
                'submitted_user_count' => 1,
                'created_users' => json_encode([
                    ['name' => 'Remote Sam', 'email' => 'remote-sam@example.com', 'role' => 'User', 'firm' => 'Acme'],
                ]),
                'updated_users' => json_encode([]),
                'reactivated_users' => json_encode([]),
                'skipped_rows' => json_encode([]),
                'message' => 'Excel sheet sent for import (1 user pending).',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } finally {
            $remote->disconnect($hub);
        }

        // Pending lives only on the remote hub DB — Central has none.
        $this->assertSame(0, AdvisorImportBatch::query()->count());

        Sanctum::actingAs($admin);

        $this->getJson('/api/power-admin/advisors/import-history')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('batches.0.id', $batchId)
            ->assertJsonPath('batches.0.status', 'pending')
            ->assertJsonPath('batches.0.pending_users.0.email', 'remote-sam@example.com')
            ->assertJsonPath('capabilities.can_view_submissions', true)
            ->assertJsonPath('target_hub.slug', 'myhub');
    }

    /**
     * @param  array<string, bool>  $paCaps
     * @return array{0: Hub, 1: User}
     */
    private function actingPowerAdminOnPrivateShared(array $paCaps): array
    {
        $matrix = app(CapabilitiesMatrixService::class);

        $central = Hub::query()->create([
            'name' => 'Central',
            'slug' => 'central',
            'type' => Hub::TYPE_CENTRAL,
            'is_active' => true,
            'checklist' => Hub::defaultChecklist(Hub::TYPE_CENTRAL),
            'role_capabilities' => $matrix->defaultRoleCapabilities(Hub::TYPE_CENTRAL),
        ]);

        $hub = $this->makeWiredPrivateSharedHub();

        $roleCaps = $matrix->resolvedRoleCapabilities($hub);
        foreach ($paCaps as $key => $value) {
            $roleCaps[User::ROLE_POWER_ADMIN][$key] = $value;
        }
        $hub->role_capabilities = $roleCaps;
        $hub->save();

        $centralCaps = $matrix->resolvedRoleCapabilities($central);
        $centralCaps[User::ROLE_POWER_ADMIN][ActingHubService::CAPABILITY] = true;
        $central->role_capabilities = $centralCaps;
        $central->save();

        $matrix->forgetResolvedCaches();
        app(HubService::class)->forgetCurrentCache();

        $admin = User::factory()->powerAdmin()->create([
            'acting_hub_id' => $hub->id,
        ]);

        return [$hub, $admin];
    }

    /**
     * @param  array{email: string, name: string, filename: string}  $user
     */
    private function insertRemotePendingSubmission(Hub $hub, array $user): int
    {
        $remote = app(WhiteLabelDatabaseService::class);
        $connection = $remote->connect($hub);
        try {
            $remoteHubId = (int) DB::connection($connection)->table('hubs')->where('slug', $hub->slug)->value('id');
            $this->assertGreaterThan(0, $remoteHubId);

            return (int) DB::connection($connection)->table('advisor_import_batches')->insertGetId([
                'hub_id' => $remoteHubId,
                'imported_by_user_id' => 99,
                'imported_by_name' => 'Shared Manager',
                'imported_by_email' => 'manager@'.$hub->slug.'.test',
                'original_filename' => $user['filename'],
                'stored_path' => 'advisor-imports/submissions/'.$user['filename'],
                'status' => AdvisorImportBatch::STATUS_PENDING,
                'kind' => AdvisorImportBatch::KIND_SUBMISSION,
                'created_count' => 1,
                'updated_count' => 0,
                'reactivated_count' => 0,
                'skipped_count' => 0,
                'submitted_user_count' => 1,
                'created_users' => json_encode([
                    ['name' => $user['name'], 'email' => $user['email'], 'role' => 'User', 'firm' => 'Acme'],
                ]),
                'updated_users' => json_encode([]),
                'reactivated_users' => json_encode([]),
                'skipped_rows' => json_encode([]),
                'message' => 'Excel sheet sent for import (1 user pending).',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } finally {
            $remote->disconnect($hub);
        }
    }

    private function makeWiredPrivateSharedHub(): Hub
    {
        $this->remoteSqlitePath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'shared-import-'.uniqid('', true).'.sqlite';
        touch($this->remoteSqlitePath);

        $checklist = array_merge(Hub::defaultChecklist(Hub::TYPE_SHARED), [
            'private_invite_only' => true,
            'public_subscribe' => false,
        ]);

        $hub = Hub::query()->create([
            'name' => 'Shared Private',
            'slug' => 'shared',
            'type' => Hub::TYPE_SHARED,
            'is_active' => true,
            'checklist' => $checklist,
            'frontend_url' => 'https://shared.fin-proms.com',
            'db_driver' => 'sqlite',
            'db_host' => 'localhost',
            'db_port' => 3306,
            'db_database' => $this->remoteSqlitePath,
            'db_username' => 'test',
            'db_password' => 'secret',
        ]);

        $this->bootstrapRemoteImportSchema($hub, $checklist);

        return $hub;
    }

    /**
     * @param  array<string, mixed>  $checklist
     */
    private function bootstrapRemoteImportSchema(Hub $hub, array $checklist): void
    {
        $remote = app(WhiteLabelDatabaseService::class);
        $connection = $remote->connect($hub);

        Schema::connection($connection)->create('hubs', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('type')->default('shared');
            $table->boolean('is_active')->default(true);
            $table->json('checklist')->nullable();
            $table->json('role_capabilities')->nullable();
            $table->timestamps();
        });

        Schema::connection($connection)->create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role')->default('user');
            $table->timestamps();
        });

        Schema::connection($connection)->create('advisor_import_batches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('hub_id');
            $table->unsignedBigInteger('imported_by_user_id')->nullable();
            $table->string('imported_by_name')->nullable();
            $table->string('imported_by_email')->nullable();
            $table->string('original_filename')->nullable();
            $table->string('stored_path')->nullable();
            $table->string('status', 32)->default('completed');
            $table->string('kind', 32)->default('import');
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('reactivated_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->unsignedInteger('submitted_user_count')->default(0);
            $table->json('created_users')->nullable();
            $table->json('updated_users')->nullable();
            $table->json('reactivated_users')->nullable();
            $table->json('skipped_rows')->nullable();
            $table->text('message')->nullable();
            $table->timestamps();
        });

        DB::connection($connection)->table('hubs')->insert([
            'name' => $hub->name,
            'slug' => $hub->slug,
            'type' => $hub->type,
            'is_active' => 1,
            'checklist' => json_encode($checklist),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $remote->disconnect($hub);
    }

    private function makeWiredPrivateWhiteLabelHub(): Hub
    {
        $this->remoteSqlitePath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wl-import-'.uniqid('', true).'.sqlite';
        touch($this->remoteSqlitePath);

        $checklist = array_merge(Hub::defaultChecklist(Hub::TYPE_WHITE_LABEL), [
            'private_invite_only' => true,
            'public_subscribe' => false,
        ]);

        $hub = Hub::query()->create([
            'name' => 'My Hub',
            'slug' => 'myhub',
            'type' => Hub::TYPE_WHITE_LABEL,
            'is_active' => true,
            'checklist' => $checklist,
            'frontend_url' => 'https://myhub.fin-proms.com',
            'db_driver' => 'sqlite',
            'db_host' => 'localhost',
            'db_port' => 3306,
            'db_database' => $this->remoteSqlitePath,
            'db_username' => 'test',
            'db_password' => 'secret',
        ]);

        $this->bootstrapRemoteImportSchema($hub, $checklist);

        return $hub;
    }
}