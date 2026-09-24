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

    public function test_settings_logo_sync_pushes_absolute_url_to_white_label_database(): void
    {
        config(['app.url' => 'https://sharedhub.fin-proms.com']);
        config(['filesystems.disks.public.url' => 'https://sharedhub.fin-proms.com/api/media']);

        [$admin, $hub] = $this->actingPowerAdminOnWiredHub();

        $hub->logo_url = 'hubs/logos/brand-logo.png';
        $hub->white_logo_url = 'hubs/white-logos/brand-logo-white.png';
        $hub->favicon_url = 'hubs/favicons/brand.ico';
        $hub->save();

        app(\App\Services\WhiteLabelHubSyncService::class)->pushSettings($hub->fresh());

        $remote = app(WhiteLabelDatabaseService::class);
        $connection = $remote->connect($hub);
        try {
            $row = DB::connection($connection)->table('hubs')->where('slug', 'myhub')->first();
            $this->assertNotNull($row);
            $this->assertSame(
                'https://sharedhub.fin-proms.com/api/media/hubs/logos/brand-logo.png',
                $row->logo_url
            );
            $this->assertSame(
                'https://sharedhub.fin-proms.com/api/media/hubs/white-logos/brand-logo-white.png',
                $row->white_logo_url
            );
            $this->assertSame(
                'https://sharedhub.fin-proms.com/api/media/hubs/favicons/brand.ico',
                $row->favicon_url
            );
        } finally {
            $remote->disconnect($hub);
        }
    }

    public function test_website_compliance_templates_are_read_from_acting_white_label_database(): void
    {
        config([
            'services.website_compliance.hub_showcase_templates' => [
                'myhub' => ['template4'],
                'shared' => [],
            ],
        ]);

        [$admin, $hub] = $this->actingPowerAdminOnWiredHub();

        $checklist = $hub->resolvedChecklist();
        $checklist['module_website_compliance'] = true;
        $checklist['wc_manage_templates'] = true;
        $checklist['wc_view_all_deployments'] = true;
        $roleCaps = is_array($hub->role_capabilities) ? $hub->role_capabilities : [];
        $roleCaps[User::ROLE_POWER_ADMIN]['wc_manage_templates'] = true;
        $roleCaps[User::ROLE_POWER_ADMIN]['wc_view_all_deployments'] = true;
        $hub->forceFill([
            'checklist' => $checklist,
            'role_capabilities' => $roleCaps,
        ])->save();

        $remote = app(WhiteLabelDatabaseService::class);
        $connection = $remote->connect($hub);
        try {
            Schema::connection($connection)->create('wc_templates', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('slug')->unique();
                $table->text('description')->nullable();
                $table->string('thumbnail_url', 500)->nullable();
                $table->string('preview_url', 500)->nullable();
                $table->longText('dummy_content')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });

            DB::connection($connection)->table('wc_templates')->insert([
                'name' => 'Template 4 (Complete Financial Centre)',
                'slug' => 'template4',
                'description' => 'myhub showcase',
                'preview_url' => 'https://myhub.fin-proms.com/template4/',
                'is_active' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } finally {
            $remote->disconnect($hub);
        }

        $this->assertDatabaseMissing('wc_templates', ['slug' => 'template4']);

        $this->getJson('/api/website-compliance/templates?all=1')
            ->assertOk()
            ->assertJsonFragment(['slug' => 'template4'])
            ->assertJsonFragment(['preview_url' => 'https://myhub.fin-proms.com/template4/']);
    }

    public function test_power_admin_can_register_template_on_acting_white_label_hub(): void
    {
        config([
            'services.website_compliance.hub_showcase_templates' => [
                'myhub' => ['template4'],
                'shared' => [],
            ],
        ]);

        [$admin, $hub] = $this->actingPowerAdminOnWiredHub();

        $checklist = $hub->resolvedChecklist();
        $checklist['module_website_compliance'] = true;
        $hub->forceFill(['checklist' => $checklist])->save();

        $remote = app(WhiteLabelDatabaseService::class);
        $connection = $remote->connect($hub);
        try {
            Schema::connection($connection)->create('wc_templates', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('slug')->unique();
                $table->text('description')->nullable();
                $table->string('thumbnail_url', 500)->nullable();
                $table->string('preview_url', 500)->nullable();
                $table->longText('dummy_content')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        } finally {
            $remote->disconnect($hub);
        }

        // Shared DB must not be used for uniqueness — leave shared without this slug.
        $this->assertDatabaseMissing('wc_templates', ['slug' => 'template-new']);

        $this->postJson('/api/website-compliance/templates', [
            'name' => 'Template New',
            'slug' => 'template-new',
            'description' => 'Registered remotely onto myhub',
            'is_active' => true,
        ])
            ->assertCreated()
            ->assertJsonFragment(['slug' => 'template-new', 'name' => 'Template New']);

        $this->assertDatabaseMissing('wc_templates', ['slug' => 'template-new']);

        $connection = $remote->connect($hub);
        try {
            $this->assertDatabaseHas('wc_templates', [
                'slug' => 'template-new',
                'name' => 'Template New',
            ], $connection);
        } finally {
            $remote->disconnect($hub);
        }
    }

    public function test_power_admin_sees_white_label_deployment_requests_and_platform_summary_remotely(): void
    {
        config([
            'services.website_compliance.hub_showcase_templates' => [
                'myhub' => ['template4'],
                'shared' => [],
            ],
        ]);

        [$admin, $hub] = $this->actingPowerAdminOnWiredHub();

        $checklist = $hub->resolvedChecklist();
        $checklist['module_website_compliance'] = true;
        $hub->forceFill(['checklist' => $checklist])->save();

        $remote = app(WhiteLabelDatabaseService::class);
        $connection = $remote->connect($hub);
        try {
            $advisorId = (int) DB::connection($connection)->table('users')->insertGetId([
                'name' => 'WL Advisor',
                'email' => 'advisor@myhub.test',
                'password' => bcrypt('password'),
                'role' => User::ROLE_ADVISOR,
                'is_advisor' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            Schema::connection($connection)->create('wc_template_requests', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('advisor_id')->nullable();
                $table->unsignedBigInteger('requested_by_id')->nullable();
                $table->unsignedBigInteger('assigned_advisor_id')->nullable();
                $table->string('template_name');
                $table->string('request_type')->default('advisor_website');
                $table->string('domain_name')->nullable();
                $table->string('logo_url', 500)->nullable();
                $table->string('favicon_url', 1000)->nullable();
                $table->string('primary_color', 50)->nullable();
                $table->string('secondary_color', 50)->nullable();
                $table->string('status')->default('pending');
                $table->text('rejection_reason')->nullable();
                $table->string('cpanel_domain')->nullable();
                $table->string('cpanel_db_host')->nullable();
                $table->string('cpanel_db_name')->nullable();
                $table->string('cpanel_db_user')->nullable();
                $table->string('cpanel_db_password')->nullable();
                $table->string('cpanel_api_key')->nullable();
                $table->timestamps();
            });

            Schema::connection($connection)->create('wc_templates', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('slug')->unique();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });

            Schema::connection($connection)->create('wc_platform_reports', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('templates_total')->default(0);
                $table->unsignedInteger('templates_active')->default(0);
                $table->unsignedInteger('templates_inactive')->default(0);
                $table->unsignedInteger('users_total')->default(0);
                $table->unsignedInteger('advisors_count')->default(0);
                $table->unsignedInteger('approvers_count')->default(0);
                $table->unsignedInteger('managers_count')->default(0);
                $table->unsignedInteger('client_admins_count')->default(0);
                $table->unsignedInteger('power_admins_count')->default(0);
                $table->unsignedInteger('template_requests_total')->default(0);
                $table->unsignedInteger('template_requests_pending')->default(0);
                $table->unsignedInteger('template_requests_deployed')->default(0);
                $table->unsignedInteger('template_requests_rejected')->default(0);
                $table->unsignedInteger('template_requests_advisor_website')->default(0);
                $table->unsignedInteger('template_requests_hub_main_website')->default(0);
                $table->json('template_requests_by_template')->nullable();
                $table->unsignedInteger('change_requests_total')->default(0);
                $table->unsignedInteger('change_requests_pending')->default(0);
                $table->unsignedInteger('change_requests_under_review')->default(0);
                $table->unsignedInteger('change_requests_scheduled')->default(0);
                $table->unsignedInteger('change_requests_approved')->default(0);
                $table->unsignedInteger('change_requests_rejected')->default(0);
                $table->unsignedInteger('change_requests_approved_with_feedback')->default(0);
                $table->unsignedBigInteger('generated_by')->nullable();
                $table->timestamp('generated_at')->nullable();
                $table->timestamps();
            });

            Schema::connection($connection)->create('wc_change_requests', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('section_id')->nullable();
                $table->unsignedBigInteger('editor_id')->nullable();
                $table->unsignedBigInteger('approver_id')->nullable();
                $table->longText('proposed_content')->nullable();
                $table->string('status')->default('pending');
                $table->unsignedInteger('current_version')->default(1);
                $table->timestamps();
            });

            DB::connection($connection)->table('wc_templates')->insert([
                'name' => 'Template 4',
                'slug' => 'template4',
                'is_active' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::connection($connection)->table('wc_template_requests')->insert([
                'advisor_id' => $advisorId,
                'requested_by_id' => $advisorId,
                'template_name' => 'template4',
                'request_type' => 'advisor_website',
                'domain_name' => 'advisor.myhub.test',
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Stale snapshot must not win over live counts on GET summary.
            DB::connection($connection)->table('wc_platform_reports')->insert([
                'templates_total' => 0,
                'templates_active' => 0,
                'templates_inactive' => 0,
                'users_total' => 0,
                'advisors_count' => 0,
                'approvers_count' => 0,
                'managers_count' => 0,
                'client_admins_count' => 0,
                'power_admins_count' => 0,
                'template_requests_total' => 0,
                'template_requests_pending' => 0,
                'template_requests_deployed' => 0,
                'template_requests_rejected' => 0,
                'template_requests_advisor_website' => 0,
                'template_requests_hub_main_website' => 0,
                'template_requests_by_template' => json_encode([]),
                'change_requests_total' => 0,
                'change_requests_pending' => 0,
                'change_requests_under_review' => 0,
                'change_requests_scheduled' => 0,
                'change_requests_approved' => 0,
                'change_requests_rejected' => 0,
                'change_requests_approved_with_feedback' => 0,
                'generated_by' => null,
                'generated_at' => now()->subDay(),
                'created_at' => now()->subDay(),
                'updated_at' => now()->subDay(),
            ]);
        } finally {
            $remote->disconnect($hub);
        }

        $this->assertDatabaseMissing('wc_template_requests', ['domain_name' => 'advisor.myhub.test']);

        $this->getJson('/api/website-compliance/template-requests')
            ->assertOk()
            ->assertJsonFragment(['domain_name' => 'advisor.myhub.test'])
            ->assertJsonFragment(['status' => 'pending']);

        $this->getJson('/api/website-compliance/reports/summary')
            ->assertOk()
            ->assertJsonPath('template_requests.total', 1)
            ->assertJsonPath('template_requests.by_status.pending', 1)
            ->assertJsonPath('deployments.total', 1)
            ->assertJsonPath('deployments.by_status.pending', 1)
            ->assertJsonPath('templates.total', 1)
            ->assertJsonPath('templates.active', 1)
            ->assertJsonPath('deployments.awaiting_advisor', 1)
            ->assertJsonPath('change_requests.avg_version', 1)
            ->assertJsonPath('change_requests.resubmitted', 0);
    }

    public function test_social_media_compliance_reports_are_read_from_acting_white_label_database(): void
    {
        [$admin, $hub] = $this->actingPowerAdminOnWiredHub();

        $checklist = $hub->resolvedChecklist();
        $checklist['module_social_media_compliance'] = true;
        $roleCaps = is_array($hub->role_capabilities) ? $hub->role_capabilities : [];
        $roleCaps[User::ROLE_POWER_ADMIN]['smc_view_reports'] = true;
        $hub->forceFill([
            'checklist' => $checklist,
            'role_capabilities' => $roleCaps,
        ])->save();

        $remote = app(WhiteLabelDatabaseService::class);
        $connection = $remote->connect($hub);
        try {
            Schema::connection($connection)->create('social_media_compliance_requests', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('post_id')->nullable();
                $table->string('name');
                $table->unsignedInteger('current_version')->default(1);
                $table->timestamp('submission_date')->useCurrent();
                $table->unsignedBigInteger('assigned_to')->nullable();
                $table->timestamp('assigned_date')->nullable();
                $table->unsignedBigInteger('assigned_by')->nullable();
                $table->timestamps();
            });

            Schema::connection($connection)->create('social_media_compliance_request_versions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('request_id');
                $table->unsignedTinyInteger('version_number')->default(1);
                $table->text('description')->nullable();
                $table->string('image_path', 500)->nullable();
                $table->string('image_url', 500)->nullable();
                $table->unsignedBigInteger('submitted_by');
                $table->timestamp('submitted_at')->useCurrent();
                $table->string('status', 50)->nullable();
                $table->text('feedback')->nullable();
                $table->string('reviewed_by')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->timestamps();
            });

            $requestId = DB::connection($connection)->table('social_media_compliance_requests')->insertGetId([
                'user_id' => 1,
                'post_id' => null,
                'name' => 'WL Advisor',
                'current_version' => 1,
                'submission_date' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::connection($connection)->table('social_media_compliance_request_versions')->insert([
                'request_id' => $requestId,
                'version_number' => 1,
                'description' => 'Remote white-label SMC submission',
                'submitted_by' => 1,
                'submitted_at' => now(),
                'status' => 'Pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } finally {
            $remote->disconnect($hub);
        }

        $this->assertDatabaseMissing('social_media_compliance_requests', ['name' => 'WL Advisor']);

        $this->getJson('/api/power-admin/social-media-compliance/reports')
            ->assertOk()
            ->assertJsonPath('report.summary.total', 1)
            ->assertJsonPath('report.rows.0.submitted_by', 'WL Advisor')
            ->assertJsonPath('report.rows.0.status', 'Pending');
    }

    public function test_general_compliance_reports_are_read_from_acting_white_label_database(): void
    {
        [$admin, $hub] = $this->actingPowerAdminOnWiredHub();

        $checklist = $hub->resolvedChecklist();
        $checklist['module_general_compliance'] = true;
        $roleCaps = is_array($hub->role_capabilities) ? $hub->role_capabilities : [];
        $roleCaps[User::ROLE_POWER_ADMIN]['gc_view_reports'] = true;
        $hub->forceFill([
            'checklist' => $checklist,
            'role_capabilities' => $roleCaps,
        ])->save();

        $remote = app(WhiteLabelDatabaseService::class);
        $connection = $remote->connect($hub);
        try {
            Schema::connection($connection)->create('general_compliance_requests', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->string('name');
                $table->unsignedInteger('current_version')->default(1);
                $table->timestamp('submission_date')->useCurrent();
                $table->unsignedBigInteger('assigned_to')->nullable();
                $table->timestamp('assigned_date')->nullable();
                $table->unsignedBigInteger('assigned_by')->nullable();
                $table->timestamps();
            });

            Schema::connection($connection)->create('general_compliance_request_versions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('request_id');
                $table->unsignedTinyInteger('version_number')->default(1);
                $table->text('description')->nullable();
                $table->unsignedBigInteger('submitted_by');
                $table->timestamp('submitted_at')->useCurrent();
                $table->string('status', 50)->nullable();
                $table->text('feedback')->nullable();
                $table->string('reviewed_by')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->timestamps();
            });

            Schema::connection($connection)->create('general_compliance_request_attachments', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('version_id');
                $table->string('original_name');
                $table->string('file_path', 500)->nullable();
                $table->string('file_url', 500)->nullable();
                $table->string('mime_type')->nullable();
                $table->unsignedBigInteger('size_bytes')->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
            });

            $requestId = DB::connection($connection)->table('general_compliance_requests')->insertGetId([
                'user_id' => 1,
                'name' => 'WL GC Advisor',
                'current_version' => 1,
                'submission_date' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::connection($connection)->table('general_compliance_request_versions')->insert([
                'request_id' => $requestId,
                'version_number' => 1,
                'description' => 'Remote white-label GC submission',
                'submitted_by' => 1,
                'submitted_at' => now(),
                'status' => 'Pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } finally {
            $remote->disconnect($hub);
        }

        $this->assertDatabaseMissing('general_compliance_requests', ['name' => 'WL GC Advisor']);

        $this->getJson('/api/power-admin/general-compliance/reports')
            ->assertOk()
            ->assertJsonPath('report.summary.total', 1)
            ->assertJsonPath('report.rows.0.submitted_by', 'WL GC Advisor')
            ->assertJsonPath('report.rows.0.status', 'Pending');
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
            $table->string('white_logo_url')->nullable();
            $table->string('favicon_url')->nullable();
            $table->string('from_email')->nullable();
            $table->string('frontend_url')->nullable();
            $table->json('checklist')->nullable();
            $table->json('role_capabilities')->nullable();
            $table->json('role_display_names')->nullable();
            $table->json('compliance_status_display_names')->nullable();
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
