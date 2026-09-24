<?php

namespace Tests\Feature;

use App\Models\Firm;
use App\Models\Hub;
use App\Models\User;
use App\Services\AdvisorImportService;
use App\Services\CapabilitiesMatrixService;
use App\Services\HubService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FirmFeatureTest extends TestCase
{
    use RefreshDatabase;

    private function createSharedHub(array $checklistOverrides = []): Hub
    {
        $checklist = array_merge(Hub::defaultChecklist(Hub::TYPE_SHARED), $checklistOverrides);

        $hub = Hub::query()->create([
            'name' => 'Shared Hub',
            'slug' => 'shared',
            'type' => Hub::TYPE_SHARED,
            'is_active' => true,
            'checklist' => $checklist,
        ]);

        app(HubService::class)->forgetCurrentCache();

        return $hub;
    }

    private function enableManageFirms(Hub $hub, string $role = User::ROLE_POWER_ADMIN): void
    {
        $matrix = app(CapabilitiesMatrixService::class);
        $caps = $matrix->resolvedRoleCapabilities($hub);
        $caps[$role]['dashboard_manage_firms'] = true;
        $hub->role_capabilities = $caps;
        $hub->save();
        app(HubService::class)->forgetCurrentCache();
    }

    public function test_power_admin_can_crud_firms_when_capability_enabled(): void
    {
        $hub = $this->createSharedHub();
        $this->enableManageFirms($hub);

        $admin = User::factory()->powerAdmin()->create();
        Sanctum::actingAs($admin);

        $create = $this->postJson('/api/power-admin/firms', ['name' => 'Acme Wealth']);
        $create->assertCreated()
            ->assertJsonPath('firm.name', 'Acme Wealth');

        $firmId = (int) $create->json('firm.id');

        $this->getJson('/api/power-admin/firms')
            ->assertOk()
            ->assertJsonFragment(['name' => 'Acme Wealth'])
            ->assertJsonStructure([
                'firms',
                'firm_options',
                'central_firm_id',
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ]);

        $this->putJson('/api/power-admin/firms/'.$firmId, ['name' => 'Acme Partners'])
            ->assertOk()
            ->assertJsonPath('firm.name', 'Acme Partners');
    }

    public function test_firms_index_supports_search_pagination_and_latest_first(): void
    {
        $hub = $this->createSharedHub();
        $this->enableManageFirms($hub);

        $admin = User::factory()->powerAdmin()->create();
        Sanctum::actingAs($admin);

        Firm::central();

        $older = Firm::query()->create(['name' => 'Alpha Partners']);
        $older->forceFill(['created_at' => now()->subDay(), 'updated_at' => now()->subDay()])->save();

        $newer = Firm::query()->create(['name' => 'Zeta Advisors']);
        $newer->forceFill(['created_at' => now(), 'updated_at' => now()])->save();

        $index = $this->getJson('/api/power-admin/firms?per_page=10')->assertOk();
        $names = collect($index->json('firms'))->pluck('name')->values()->all();
        $this->assertSame('Zeta Advisors', $names[0]);
        $this->assertLessThan(
            array_search('Alpha Partners', $names, true),
            array_search('Zeta Advisors', $names, true)
        );

        $this->getJson('/api/power-admin/firms?q=Zeta')
            ->assertOk()
            ->assertJsonCount(1, 'firms')
            ->assertJsonPath('firms.0.name', 'Zeta Advisors')
            ->assertJsonPath('meta.total', 1);
    }

    public function test_user_create_assigns_firm_and_lists_firms_for_dropdown(): void
    {
        $this->createSharedHub();
        $firm = Firm::query()->create(['name' => 'Northstar']);
        $admin = User::factory()->powerAdmin()->create();
        Sanctum::actingAs($admin);

        $index = $this->getJson('/api/power-admin/users');
        $index->assertOk();
        $this->assertTrue(collect($index->json('firms'))->contains(fn ($f) => $f['name'] === 'Northstar'));

        $created = $this->postJson('/api/power-admin/users', [
            'name' => 'Advisor One',
            'email' => 'advisor.one@example.com',
            'password' => 'password12',
            'role' => User::ROLE_USER,
            'firm_id' => $firm->id,
        ]);

        $created->assertCreated()
            ->assertJsonPath('user.firm_id', $firm->id)
            ->assertJsonPath('user.firm.name', 'Northstar');
    }

    public function test_public_registration_does_not_require_firm(): void
    {
        Hub::query()->create([
            'name' => 'Shared Hub',
            'slug' => 'shared',
            'type' => Hub::TYPE_SHARED,
            'is_active' => true,
            'checklist' => Hub::defaultChecklist(Hub::TYPE_SHARED),
        ]);
        app(HubService::class)->forgetCurrentCache();

        $response = $this->postJson('/api/auth/register', [
            'name' => 'Public User',
            'email' => 'public.user@example.com',
            'password' => 'password12',
            'password_confirmation' => 'password12',
        ]);

        $response->assertCreated();
        $this->assertNull(User::query()->where('email', 'public.user@example.com')->value('firm_id'));
    }

    public function test_advisor_import_assigns_firm_from_sheet(): void
    {
        $this->createSharedHub([
            'private_invite_only' => true,
            'public_subscribe' => false,
        ]);
        $firm = Firm::query()->create(['name' => 'Acme Wealth']);

        $csv = "name,email,password,firm\nJane Advisor,jane.firm@example.com,,Acme Wealth\n";
        $file = UploadedFile::fake()->createWithContent('advisors.csv', $csv);

        $plan = app(AdvisorImportService::class)->buildPlan($file);

        $this->assertSame(1, $plan['summary']['created']);
        $this->assertSame($firm->id, $plan['pending'][0]['firm_id']);
        $this->assertSame('Acme Wealth', $plan['pending'][0]['firm']);
        $this->assertSame('Acme Wealth', $plan['preview']['created'][0]['firm']);
    }

    public function test_advisor_import_skips_unknown_firm(): void
    {
        $this->createSharedHub([
            'private_invite_only' => true,
            'public_subscribe' => false,
        ]);

        $csv = "name,email,password,firm\nJane Advisor,jane.missing@example.com,,Missing Firm\n";
        $file = UploadedFile::fake()->createWithContent('advisors.csv', $csv);

        $plan = app(AdvisorImportService::class)->buildPlan($file);

        $this->assertSame(0, $plan['summary']['created']);
        $this->assertSame(1, $plan['summary']['skipped']);
        $this->assertStringContainsString('Unknown firm', $plan['skipped'][0]['reason']);
    }
}
