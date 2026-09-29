<?php

namespace Tests\Feature;

use App\Models\Hub;
use App\Models\Invoice;
use App\Models\User;
use App\Services\CapabilitiesMatrixService;
use App\Services\ModulePricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ModuleBillingTest extends TestCase
{
    use RefreshDatabase;

    public function test_charge_amount_per_module_defaults_on_for_white_label_off_for_shared(): void
    {
        $shared = Hub::defaultChecklist(Hub::TYPE_SHARED);
        $whiteLabel = Hub::defaultChecklist(Hub::TYPE_WHITE_LABEL);

        $this->assertFalse($shared['charge_amount_per_module']);
        $this->assertTrue($whiteLabel['charge_amount_per_module']);
        $this->assertTrue($whiteLabel['dashboard_manage_module_pricing']);
        $this->assertTrue($whiteLabel['dashboard_view_module_invoices']);
        $this->assertTrue($whiteLabel['dashboard_mark_module_invoices_paid']);
        $this->assertFalse($shared['dashboard_manage_module_pricing']);
        $this->assertFalse($shared['dashboard_mark_module_invoices_paid']);
    }

    public function test_creating_white_label_hub_generates_one_time_invoices_for_enabled_modules(): void
    {
        $admin = User::factory()->powerAdmin()->create();
        Sanctum::actingAs($admin);

        app(ModulePricingService::class)->seedDefaultsIfEmpty();

        $response = $this->postJson('/api/power-admin/hubs', [
            'name' => 'Client Hub',
            'slug' => 'client-hub',
        ]);

        $response->assertCreated();
        $invoices = $response->json('module_invoices');
        $this->assertNotEmpty($invoices);

        // White Label Hub (£14,500). Social Media Template Library is £0 so skipped.
        $this->assertCount(1, $invoices);
        $this->assertStringContainsString('White Label Hub', $invoices[0]['description']);
        $this->assertSame(14500.0, (float) $invoices[0]['amount']);
        $this->assertSame(Invoice::TYPE_MODULE_BILLING, $invoices[0]['type']);
        $this->assertSame(Invoice::TYPES_ONE_TIME, $invoices[0]['types']);
        $this->assertSame('paid', $invoices[0]['status'] ?? Invoice::query()->find($invoices[0]['id'])->status);

        $this->assertDatabaseHas('invoices', [
            'type' => Invoice::TYPE_MODULE_BILLING,
            'types' => Invoice::TYPES_ONE_TIME,
            'status' => 'paid',
        ]);
    }

    public function test_enabling_a_module_generates_a_one_time_invoice_when_functionality_on(): void
    {
        $hub = Hub::query()->create([
            'name' => 'WL Hub',
            'slug' => 'wl-hub',
            'type' => Hub::TYPE_WHITE_LABEL,
            'is_active' => true,
            'checklist' => array_merge(Hub::defaultChecklist(Hub::TYPE_WHITE_LABEL), [
                'charge_amount_per_module' => true,
                'module_social_media_template_library' => true,
                'module_social_media_compliance' => false,
            ]),
        ]);

        $matrix = app(CapabilitiesMatrixService::class);
        $caps = $matrix->resolvedRoleCapabilities($hub);
        $caps[User::ROLE_POWER_ADMIN]['dashboard_manage_modules'] = true;
        $hub->role_capabilities = $caps;
        $hub->save();

        $admin = User::factory()->powerAdmin()->create();
        Sanctum::actingAs($admin);

        app(ModulePricingService::class)->seedDefaultsIfEmpty();

        $this->assertFalse($hub->hasSocialMediaComplianceModule());

        $response = $this->putJson('/api/power-admin/modules?hub_id='.$hub->id, [
            'hub_id' => $hub->id,
            'modules' => [
                'module_social_media_compliance' => true,
            ],
        ]);

        $response->assertOk();
        $created = collect($response->json('module_invoices'));
        $this->assertCount(1, $created);
        $this->assertSame(Invoice::TYPES_ONE_TIME, $created->first()['types']);
        $this->assertSame(5000.0, (float) $created->first()['amount']);
        $this->assertStringContainsString('Social Media Pre Approval', $created->first()['description']);
        $this->assertDatabaseHas('invoices', [
            'id' => $created->first()['id'],
            'status' => 'unpaid',
        ]);
        $this->assertDatabaseHas('hub_module_billings', [
            'hub_id' => $hub->id,
            'module_key' => 'module_social_media_compliance',
            'status' => 'unpaid',
        ]);

        // Idempotent — enabling again does not duplicate.
        $again = $this->putJson('/api/power-admin/modules?hub_id='.$hub->id, [
            'hub_id' => $hub->id,
            'modules' => [
                'module_social_media_compliance' => true,
            ],
        ]);
        $again->assertOk();
        $this->assertSame([], $again->json('module_invoices'));
    }

    public function test_website_template_library_does_not_invoice_on_enable_because_per_website(): void
    {
        $hub = Hub::query()->create([
            'name' => 'WL Hub',
            'slug' => 'wl-wtl',
            'type' => Hub::TYPE_WHITE_LABEL,
            'is_active' => true,
            'checklist' => array_merge(Hub::defaultChecklist(Hub::TYPE_WHITE_LABEL), [
                'charge_amount_per_module' => true,
                'module_website_template_library' => false,
            ]),
        ]);

        $matrix = app(CapabilitiesMatrixService::class);
        $caps = $matrix->resolvedRoleCapabilities($hub);
        $caps[User::ROLE_POWER_ADMIN]['dashboard_manage_modules'] = true;
        $hub->role_capabilities = $caps;
        $hub->save();

        $admin = User::factory()->powerAdmin()->create();
        Sanctum::actingAs($admin);
        app(ModulePricingService::class)->seedDefaultsIfEmpty();

        $response = $this->putJson('/api/power-admin/modules?hub_id='.$hub->id, [
            'hub_id' => $hub->id,
            'modules' => [
                'module_website_template_library' => true,
            ],
        ]);

        $response->assertOk();
        $this->assertSame([], $response->json('module_invoices'));
        $this->assertTrue($hub->fresh()->hasWebsiteTemplateLibraryModule());
    }

    public function test_shared_hub_does_not_invoice_modules_by_default(): void
    {
        $hub = Hub::query()->create([
            'name' => 'Shared Hub',
            'slug' => 'shared',
            'type' => Hub::TYPE_SHARED,
            'is_active' => true,
            'checklist' => Hub::defaultChecklist(Hub::TYPE_SHARED),
        ]);

        $matrix = app(CapabilitiesMatrixService::class);
        $caps = $matrix->resolvedRoleCapabilities($hub);
        $caps[User::ROLE_POWER_ADMIN]['dashboard_manage_modules'] = true;
        $hub->role_capabilities = $caps;
        $hub->save();

        $admin = User::factory()->powerAdmin()->create();
        Sanctum::actingAs($admin);
        app(ModulePricingService::class)->seedDefaultsIfEmpty();

        $response = $this->putJson('/api/power-admin/modules', [
            'modules' => [
                'module_website_template_library' => true,
            ],
        ]);

        $response->assertOk();
        $this->assertSame([], $response->json('module_invoices'));
        $this->assertDatabaseMissing('invoices', ['type' => Invoice::TYPE_MODULE_BILLING]);
    }

    public function test_module_invoices_endpoint_backfills_missing_invoices_when_charging_on(): void
    {
        $shared = Hub::query()->create([
            'name' => 'Shared Hub',
            'slug' => 'shared',
            'type' => Hub::TYPE_SHARED,
            'is_active' => true,
            'checklist' => Hub::defaultChecklist(Hub::TYPE_SHARED),
        ]);

        $hub = Hub::query()->create([
            'name' => 'WL Hub',
            'slug' => 'wl-backfill',
            'type' => Hub::TYPE_WHITE_LABEL,
            'is_active' => true,
            'checklist' => Hub::defaultChecklist(Hub::TYPE_WHITE_LABEL),
        ]);

        $matrix = app(CapabilitiesMatrixService::class);

        $sharedCaps = $matrix->resolvedRoleCapabilities($shared);
        $sharedCaps[User::ROLE_POWER_ADMIN][\App\Services\ActingHubService::CAPABILITY] = true;
        $shared->role_capabilities = $sharedCaps;
        $shared->save();

        $caps = $matrix->resolvedRoleCapabilities($hub);
        $caps[User::ROLE_POWER_ADMIN]['dashboard_view_module_invoices'] = true;
        $hub->role_capabilities = $caps;
        $hub->save();

        $admin = User::factory()->powerAdmin()->create([
            'acting_hub_id' => $hub->id,
        ]);
        Sanctum::actingAs($admin);
        app(ModulePricingService::class)->seedDefaultsIfEmpty();

        $this->assertDatabaseMissing('invoices', ['type' => Invoice::TYPE_MODULE_BILLING]);

        $response = $this->getJson('/api/power-admin/module-invoices');
        $response->assertOk();
        $this->assertNotEmpty($response->json('data'));
        $this->assertNotEmpty($response->json('module_invoices_created'));
        $this->assertTrue($response->json('target_hub.charge_amount_per_module'));
        $this->assertSame(14500.0, (float) $response->json('data.0.amount'));

        // Second load is idempotent — no new creates.
        $again = $this->getJson('/api/power-admin/module-invoices');
        $again->assertOk();
        $this->assertSame([], $again->json('module_invoices_created'));
        $this->assertCount(count($response->json('data')), $again->json('data'));
    }

    public function test_module_pricing_capabilities_inactive_when_functionality_off(): void
    {
        $hub = Hub::query()->create([
            'name' => 'WL Hub',
            'slug' => 'wl-caps',
            'type' => Hub::TYPE_WHITE_LABEL,
            'is_active' => true,
            'checklist' => array_merge(Hub::defaultChecklist(Hub::TYPE_WHITE_LABEL), [
                'charge_amount_per_module' => false,
            ]),
        ]);

        $matrix = app(CapabilitiesMatrixService::class);
        $caps = $matrix->resolvedRoleCapabilities($hub);
        $caps[User::ROLE_POWER_ADMIN]['dashboard_manage_module_pricing'] = true;
        $caps[User::ROLE_POWER_ADMIN]['dashboard_view_module_invoices'] = true;
        $hub->role_capabilities = $caps;
        $hub->save();

        $payload = $matrix->matrix($hub->fresh());
        $pricingRow = collect($payload['rows'])->firstWhere('key', 'dashboard_manage_module_pricing');
        $this->assertTrue($pricingRow['inactive']);
        $this->assertSame('charge_amount_per_module_off', $pricingRow['inactive_reason']);
        $this->assertFalse(
            $matrix->roleCan($hub->fresh(), User::ROLE_POWER_ADMIN, 'dashboard_manage_module_pricing')
        );
    }

    public function test_mark_module_invoice_paid_with_details_and_attachment(): void
    {
        $shared = Hub::query()->create([
            'name' => 'Shared Hub',
            'slug' => 'shared-mark-paid',
            'type' => Hub::TYPE_SHARED,
            'is_active' => true,
            'checklist' => Hub::defaultChecklist(Hub::TYPE_SHARED),
        ]);

        $hub = Hub::query()->create([
            'name' => 'WL Hub',
            'slug' => 'wl-mark-paid',
            'type' => Hub::TYPE_WHITE_LABEL,
            'is_active' => true,
            'checklist' => array_merge(Hub::defaultChecklist(Hub::TYPE_WHITE_LABEL), [
                'charge_amount_per_module' => true,
                'module_social_media_template_library' => true,
                'module_social_media_compliance' => false,
            ]),
        ]);

        $matrix = app(CapabilitiesMatrixService::class);

        $sharedCaps = $matrix->resolvedRoleCapabilities($shared);
        $sharedCaps[User::ROLE_POWER_ADMIN][\App\Services\ActingHubService::CAPABILITY] = true;
        $shared->role_capabilities = $sharedCaps;
        $shared->save();

        $caps = $matrix->resolvedRoleCapabilities($hub);
        $caps[User::ROLE_POWER_ADMIN]['dashboard_manage_modules'] = true;
        $caps[User::ROLE_POWER_ADMIN]['dashboard_view_module_invoices'] = true;
        $caps[User::ROLE_POWER_ADMIN]['dashboard_mark_module_invoices_paid'] = true;
        $hub->role_capabilities = $caps;
        $hub->save();

        $admin = User::factory()->powerAdmin()->create([
            'acting_hub_id' => $hub->id,
        ]);
        Sanctum::actingAs($admin);
        app(ModulePricingService::class)->seedDefaultsIfEmpty();

        $enable = $this->putJson('/api/power-admin/modules?hub_id='.$hub->id, [
            'hub_id' => $hub->id,
            'modules' => [
                'module_social_media_compliance' => true,
            ],
        ]);
        $enable->assertOk();
        $invoiceId = $enable->json('module_invoices.0.id');
        $this->assertNotEmpty($invoiceId);
        $this->assertSame('unpaid', Invoice::query()->find($invoiceId)->status);

        $file = \Illuminate\Http\UploadedFile::fake()->create('receipt.pdf', 120, 'application/pdf');

        $response = $this->post('/api/power-admin/module-invoices/'.$invoiceId.'/mark-paid', [
            'payment_method' => 'bank_transfer',
            'payment_reference' => 'TX-9911',
            'payment_notes' => 'Paid by client via BACS on 28 Sep 2026.',
            'attachment' => $file,
        ], [
            'Accept' => 'application/json',
        ]);

        $response->assertOk();
        $response->assertJsonPath('invoice.status', 'paid');
        $response->assertJsonPath('payment.payment_method', 'bank_transfer');
        $response->assertJsonPath('payment.payment_reference', 'TX-9911');
        $response->assertJsonPath('payment.payment_notes', 'Paid by client via BACS on 28 Sep 2026.');
        $this->assertNotEmpty($response->json('payment.attachment.url'));
        $this->assertSame($admin->id, (int) $response->json('payment.paid_by.id'));

        $this->assertDatabaseHas('invoices', [
            'id' => $invoiceId,
            'status' => 'paid',
        ]);
        $this->assertDatabaseHas('hub_module_billings', [
            'hub_id' => $hub->id,
            'module_key' => 'module_social_media_compliance',
            'status' => 'paid',
            'payment_method' => 'bank_transfer',
            'payment_reference' => 'TX-9911',
            'paid_by_user_id' => $admin->id,
        ]);

        $show = $this->getJson('/api/power-admin/module-invoices/'.$invoiceId);
        $show->assertOk();
        $show->assertJsonPath('invoice.status', 'paid');
        $show->assertJsonPath('payment.payment_reference', 'TX-9911');
        $show->assertJsonPath('can_mark_paid', false);
    }

    public function test_mark_module_invoice_paid_requires_capability(): void
    {
        $shared = Hub::query()->create([
            'name' => 'Shared Hub',
            'slug' => 'shared-mark-denied',
            'type' => Hub::TYPE_SHARED,
            'is_active' => true,
            'checklist' => Hub::defaultChecklist(Hub::TYPE_SHARED),
        ]);

        $hub = Hub::query()->create([
            'name' => 'WL Hub',
            'slug' => 'wl-mark-denied',
            'type' => Hub::TYPE_WHITE_LABEL,
            'is_active' => true,
            'checklist' => array_merge(Hub::defaultChecklist(Hub::TYPE_WHITE_LABEL), [
                'charge_amount_per_module' => true,
                'module_social_media_compliance' => false,
            ]),
        ]);

        $matrix = app(CapabilitiesMatrixService::class);

        $sharedCaps = $matrix->resolvedRoleCapabilities($shared);
        $sharedCaps[User::ROLE_POWER_ADMIN][\App\Services\ActingHubService::CAPABILITY] = true;
        $shared->role_capabilities = $sharedCaps;
        $shared->save();

        $caps = $matrix->resolvedRoleCapabilities($hub);
        $caps[User::ROLE_POWER_ADMIN]['dashboard_manage_modules'] = true;
        $caps[User::ROLE_POWER_ADMIN]['dashboard_view_module_invoices'] = true;
        $caps[User::ROLE_POWER_ADMIN]['dashboard_mark_module_invoices_paid'] = false;
        $hub->role_capabilities = $caps;
        $hub->save();

        $admin = User::factory()->powerAdmin()->create([
            'acting_hub_id' => $hub->id,
        ]);
        Sanctum::actingAs($admin);
        app(ModulePricingService::class)->seedDefaultsIfEmpty();

        $enable = $this->putJson('/api/power-admin/modules?hub_id='.$hub->id, [
            'hub_id' => $hub->id,
            'modules' => [
                'module_social_media_compliance' => true,
            ],
        ]);
        $enable->assertOk();
        $invoiceId = $enable->json('module_invoices.0.id');

        $denied = $this->postJson('/api/power-admin/module-invoices/'.$invoiceId.'/mark-paid', [
            'payment_method' => 'manual',
            'payment_notes' => 'Should be forbidden.',
        ]);
        $denied->assertForbidden();
    }
}
