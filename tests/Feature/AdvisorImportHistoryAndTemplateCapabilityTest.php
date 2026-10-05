<?php

namespace Tests\Feature;

use App\Models\Firm;
use App\Models\Hub;
use App\Models\User;
use App\Services\AdvisorImportHistoryService;
use App\Services\AdvisorImportService;
use App\Services\HubService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\ExcelUploadFactory;
use Tests\TestCase;

class AdvisorImportHistoryAndTemplateCapabilityTest extends TestCase
{
    use RefreshDatabase;

    private function createPrivateHub(array $overrides = []): Hub
    {
        $checklist = array_merge(Hub::defaultChecklist(Hub::TYPE_SHARED), [
            'private_invite_only' => true,
            'public_subscribe' => false,
            'advisor_excel_import' => true,
            'advisor_excel_template' => true,
        ], $overrides);

        $hub = Hub::query()->create([
            'name' => 'Private Hub',
            'slug' => 'shared',
            'type' => Hub::TYPE_SHARED,
            'is_active' => true,
            'checklist' => $checklist,
        ]);

        app(HubService::class)->forgetCurrentCache();

        return $hub;
    }

    private function grant(Hub $hub, string $role, array $caps): User
    {
        $user = User::factory()->create(['role' => $role]);
        $matrix = app(\App\Services\CapabilitiesMatrixService::class);
        $roleCaps = $matrix->resolvedRoleCapabilities($hub);
        foreach ($caps as $key => $value) {
            $roleCaps[$role][$key] = $value;
        }
        $hub->role_capabilities = $roleCaps;
        $hub->save();
        app(HubService::class)->forgetCurrentCache();
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_template_download_allowed_with_template_capability_only(): void
    {
        $hub = $this->createPrivateHub([
            'advisor_excel_import' => false,
            'advisor_excel_template' => true,
        ]);
        Firm::query()->create(['name' => 'Acme Wealth']);

        $this->grant($hub, User::ROLE_MANAGER, [
            'advisor_excel_import' => false,
            'advisor_excel_template' => true,
        ]);

        $response = $this->get('/api/client-admin/advisors/template');
        $response->assertOk();
        $this->assertStringContainsString(
            'advisor-import-template.xlsx',
            (string) $response->headers->get('content-disposition')
        );
    }

    public function test_import_upload_forbidden_with_template_capability_only(): void
    {
        $hub = $this->createPrivateHub([
            'advisor_excel_import' => false,
            'advisor_excel_template' => true,
        ]);

        $this->grant($hub, User::ROLE_MANAGER, [
            'advisor_excel_import' => false,
            'advisor_excel_template' => true,
        ]);

        $file = ExcelUploadFactory::make([
            ['name', 'email', 'password', 'role', 'firm'],
            ['Jane', 'jane@example.com', '', 'User', 'Acme'],
        ]);

        $this->post('/api/client-admin/advisors/import', [
            'file' => $file,
        ])->assertForbidden();
    }

    public function test_import_history_lists_recorded_batches(): void
    {
        $hub = $this->createPrivateHub();
        Firm::query()->create(['name' => 'Acme Wealth']);

        $admin = $this->grant($hub, User::ROLE_CLIENT_ADMIN, [
            'advisor_excel_import' => true,
            'advisor_excel_template' => true,
        ]);

        $file = ExcelUploadFactory::make([
            ['name', 'email', 'password', 'role', 'firm'],
            ['Pat', 'pat@example.com', 'Secret123!', 'User', 'Acme Wealth'],
        ]);

        $result = app(AdvisorImportService::class)->import($file, $hub);
        app(AdvisorImportHistoryService::class)->record(
            $hub,
            $admin,
            $result,
            'users.xlsx'
        );

        $response = $this->getJson('/api/client-admin/advisors/import-history');
        $response->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('batches.0.original_filename', 'users.xlsx')
            ->assertJsonPath('batches.0.summary.created', 1)
            ->assertJsonPath('batches.0.created.0.email', 'pat@example.com');
    }
}
