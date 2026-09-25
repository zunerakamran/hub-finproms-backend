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
}
