<?php

namespace Tests\Feature;

use App\Models\Firm;
use App\Models\Hub;
use App\Models\User;
use App\Services\AdvisorImportService;
use App\Services\HubService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\ExcelUploadFactory;
use Tests\TestCase;

class AdvisorImportExcelTest extends TestCase
{
    use RefreshDatabase;

    private function createPrivateHub(array $overrides = []): Hub
    {
        $checklist = array_merge(Hub::defaultChecklist(Hub::TYPE_SHARED), [
            'private_invite_only' => true,
            'public_subscribe' => false,
            'advisor_excel_import' => true,
        ], $overrides);

        $hub = Hub::query()->create([
            'name' => 'Private Hub',
            'slug' => 'shared',
            'type' => Hub::TYPE_SHARED,
            'is_active' => true,
            'checklist' => $checklist,
            'role_display_names' => [
                'advisor' => 'IFA',
                'manager' => 'Team Lead',
            ],
        ]);

        app(HubService::class)->forgetCurrentCache();

        return $hub;
    }

    public function test_template_downloads_as_xlsx_with_role_and_firm_columns(): void
    {
        $hub = $this->createPrivateHub();
        Firm::query()->create(['name' => 'Acme Wealth']);

        $admin = User::factory()->create(['role' => User::ROLE_CLIENT_ADMIN]);
        $matrix = app(\App\Services\CapabilitiesMatrixService::class);
        $roleCaps = $matrix->resolvedRoleCapabilities($hub);
        $roleCaps[User::ROLE_CLIENT_ADMIN]['advisor_excel_import'] = true;
        $hub->role_capabilities = $roleCaps;
        $hub->save();
        app(HubService::class)->forgetCurrentCache();

        Sanctum::actingAs($admin);

        $response = $this->get('/api/client-admin/advisors/template');
        $response->assertOk();
        $this->assertStringContainsString(
            'spreadsheetml.sheet',
            (string) $response->headers->get('content-type')
        );
        $this->assertStringContainsString(
            'advisor-import-template.xlsx',
            (string) $response->headers->get('content-disposition')
        );

        $binary = $response->streamedContent();
        $this->assertNotSame('', $binary);
        $this->assertStringContainsString('PK', substr($binary, 0, 2));
    }

    public function test_import_resolves_renamed_role_labels(): void
    {
        $hub = $this->createPrivateHub();
        Firm::query()->create(['name' => 'Acme Wealth']);

        $file = ExcelUploadFactory::make([
            ['name', 'email', 'password', 'role', 'firm'],
            ['Jane', 'jane.ifa@example.com', '', 'IFA', 'Acme Wealth'],
        ]);

        $plan = app(AdvisorImportService::class)->buildPlan($file, $hub);

        $this->assertSame(1, $plan['summary']['created']);
        $this->assertSame(User::ROLE_ADVISOR, $plan['pending'][0]['role']);
        $this->assertSame(1, $plan['summary']['billable_batch']);
    }

    public function test_import_rejects_power_admin_and_finproms_admin_roles(): void
    {
        $hub = $this->createPrivateHub();
        Firm::query()->create(['name' => 'Acme Wealth']);

        $file = ExcelUploadFactory::make([
            ['name', 'email', 'password', 'role', 'firm'],
            ['Bad Power', 'bad.power@example.com', '', 'power_admin', 'Acme Wealth'],
            ['Bad Fin', 'bad.fin@example.com', '', 'FinProms Admin', 'Acme Wealth'],
        ]);

        $plan = app(AdvisorImportService::class)->buildPlan($file, $hub);

        $this->assertSame(0, $plan['summary']['created']);
        $this->assertSame(2, $plan['summary']['skipped']);
        $this->assertStringContainsString('not allowed', $plan['skipped'][0]['reason']);
        $this->assertStringContainsString('not allowed', $plan['skipped'][1]['reason']);
    }

    public function test_import_rejects_csv_uploads(): void
    {
        $this->createPrivateHub();

        $file = \Illuminate\Http\UploadedFile::fake()->createWithContent(
            'advisors.csv',
            "name,email,password,role,firm\nJane,jane@example.com,,advisor,Acme\n"
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('CSV is no longer supported');

        app(AdvisorImportService::class)->parseFile($file);
    }

    public function test_non_advisor_roles_are_not_billable(): void
    {
        $hub = $this->createPrivateHub();
        Firm::query()->create(['name' => 'Acme Wealth']);

        $file = ExcelUploadFactory::make([
            ['name', 'email', 'password', 'role', 'firm'],
            ['Lead', 'lead@example.com', '', 'Team Lead', 'Acme Wealth'],
        ]);

        $plan = app(AdvisorImportService::class)->buildPlan($file, $hub);

        $this->assertSame(1, $plan['summary']['created']);
        $this->assertSame(User::ROLE_MANAGER, $plan['pending'][0]['role']);
        $this->assertSame(0, $plan['summary']['billable_batch']);
    }

    public function test_import_assigns_multiple_modules_and_ignores_disabled(): void
    {
        $hub = $this->createPrivateHub([
            'module_social_media_template_library' => true,
            'module_social_media_compliance' => true,
            'module_website_template_library' => false,
            'module_website_compliance' => false,
            'module_general_compliance' => true,
        ]);
        Firm::query()->create(['name' => 'Acme Wealth']);

        $file = ExcelUploadFactory::make([
            ['name', 'email', 'password', 'role', 'firm', 'modules'],
            [
                'Jane',
                'jane.modules@example.com',
                'Secret123!',
                'Team Lead',
                'Acme Wealth',
                'Social Media Pre Approval Workflow, Generic Content Pre Approval Workflow, Website Template Library',
            ],
        ]);

        $result = app(AdvisorImportService::class)->import($file, $hub);

        $this->assertSame(1, $result['summary']['created']);
        $user = User::query()->where('email', 'jane.modules@example.com')->first();
        $this->assertNotNull($user);
        $this->assertContains('module_shared_hub', $user->modules);
        $this->assertContains('module_social_media_template_library', $user->modules); // dep of SMC
        $this->assertContains('module_social_media_compliance', $user->modules);
        $this->assertContains('module_general_compliance', $user->modules);
        $this->assertNotContains('module_website_template_library', $user->modules);

        $matrix = app(\App\Services\CapabilitiesMatrixService::class);
        $this->assertTrue($user->hasModuleAccess($hub, 'module_social_media_compliance'));
        $this->assertFalse($user->hasModuleAccess($hub, 'module_website_template_library'));
        $this->assertTrue($matrix->userCan($hub, $user, 'module_general_compliance'));
        $this->assertFalse($matrix->userCan($hub, $user, 'module_website_template_library'));
    }

    public function test_import_without_modules_column_assigns_only_base_module(): void
    {
        $hub = $this->createPrivateHub([
            'module_social_media_template_library' => true,
            'module_general_compliance' => true,
        ]);
        Firm::query()->create(['name' => 'Acme Wealth']);

        $file = ExcelUploadFactory::make([
            ['name', 'email', 'password', 'role', 'firm'],
            ['Lead', 'lead.base@example.com', '', 'Team Lead', 'Acme Wealth'],
        ]);

        $result = app(AdvisorImportService::class)->import($file, $hub);
        $this->assertSame(1, $result['summary']['created']);

        $user = User::query()->where('email', 'lead.base@example.com')->first();
        $this->assertSame(['module_shared_hub'], $user->modules);
        $this->assertTrue($user->hasModuleAccess($hub, 'module_shared_hub'));
        $this->assertFalse($user->hasModuleAccess($hub, 'module_general_compliance'));
    }

    public function test_template_includes_enabled_module_labels(): void
    {
        $hub = $this->createPrivateHub([
            'module_social_media_template_library' => true,
            'module_social_media_compliance' => true,
            'module_general_compliance' => true,
            'module_website_template_library' => false,
        ]);

        $importable = array_column($hub->importableModuleOptions(), 'label');
        $this->assertNotContains('Shared Hub', $importable);
        $this->assertNotContains('White Label Hub', $importable);
        $this->assertContains('Social Media Template Library', $importable);
        $this->assertContains('Social Media Pre Approval Workflow', $importable);
        $this->assertContains('Generic Content Pre Approval Workflow', $importable);
        $this->assertNotContains('Website Template Library', $importable);

        $packages = $hub->importModulePackageLabels();
        $this->assertContains('Social Media Template Library', $packages);
        $this->assertContains(
            'Social Media Template Library, Social Media Pre Approval Workflow',
            $packages
        );
        $this->assertContains('Generic Content Pre Approval Workflow', $packages);
        $this->assertContains(
            'Social Media Template Library, Social Media Pre Approval Workflow, Generic Content Pre Approval Workflow',
            $packages
        );
        // Dependent module never appears alone (dependency rule).
        $this->assertNotContains('Social Media Pre Approval Workflow', $packages);
        foreach ($packages as $package) {
            $this->assertStringNotContainsString('Shared Hub', $package);
            $this->assertStringNotContainsString('White Label Hub', $package);
        }

        $binary = app(AdvisorImportService::class)->templateXlsx($hub);
        $this->assertNotSame('', $binary);
        $this->assertStringContainsString('PK', substr($binary, 0, 2));
    }

    public function test_resolve_modules_adds_dependencies_and_ignores_base_label(): void
    {
        $hub = $this->createPrivateHub([
            'module_social_media_template_library' => true,
            'module_social_media_compliance' => true,
            'module_general_compliance' => true,
        ]);

        $modules = app(AdvisorImportService::class)->resolveModules(
            $hub,
            'Social Media Pre Approval Workflow, White Label Hub'
        );

        $this->assertContains('module_shared_hub', $modules);
        $this->assertContains('module_social_media_template_library', $modules);
        $this->assertContains('module_social_media_compliance', $modules);
        $this->assertNotContains('module_white_label_hub', $modules);
    }
}
