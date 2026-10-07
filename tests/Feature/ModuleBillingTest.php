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
        $invoices = collect($response->json('module_invoices'));
        $this->assertNotEmpty($invoices);

        // White Label Hub (£14,500) + Social Media Template Library (£0 included).
        $this->assertCount(2, $invoices);
        $wl = $invoices->first(fn ($row) => str_contains($row['description'], 'White Label Hub'));
        $smtl = $invoices->first(fn ($row) => str_contains($row['description'], 'Social Media Template Library'));
        $this->assertNotNull($wl);
        $this->assertNotNull($smtl);
        $this->assertSame(14500.0, (float) $wl['amount']);
        $this->assertSame(0.0, (float) $smtl['amount']);
        $this->assertSame(Invoice::TYPE_MODULE_BILLING, $wl['type']);
        $this->assertSame(Invoice::TYPES_ONE_TIME, $wl['types']);
        $this->assertSame('unpaid', $wl['status'] ?? Invoice::query()->find($wl['id'])->status);
        $this->assertSame('paid', $smtl['status'] ?? Invoice::query()->find($smtl['id'])->status);

        $this->assertDatabaseHas('invoices', [
            'type' => Invoice::TYPE_MODULE_BILLING,
            'types' => Invoice::TYPES_ONE_TIME,
            'status' => 'unpaid',
        ]);
        $this->assertDatabaseHas('hub_module_billings', [
            'module_key' => 'module_white_label_hub',
            'status' => 'unpaid',
        ]);
        $this->assertDatabaseHas('hub_module_billings', [
            'module_key' => 'module_social_media_template_library',
            'status' => 'paid',
            'amount' => 0,
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
        $this->assertSame(
            $hub->nextModuleInvoiceDueDate()->toDateString(),
            Invoice::query()->find($created->first()['id'])->due_on?->toDateString()
        );

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

    public function test_grace_penalties_do_not_uncheck_modules_before_renew_due_date(): void
    {
        $hub = Hub::query()->create([
            'name' => 'WL Hub',
            'slug' => 'wl-grace-mid-month',
            'type' => Hub::TYPE_WHITE_LABEL,
            'is_active' => true,
            'advisor_billing_renew_day' => 1,
            'billing_grace_day' => 4,
            'checklist' => array_merge(Hub::defaultChecklist(Hub::TYPE_WHITE_LABEL), [
                'charge_amount_per_module' => true,
                'charge_recurring_per_module' => true,
                'module_social_media_template_library' => true,
                'module_social_media_compliance' => true,
            ]),
        ]);

        $admin = User::factory()->powerAdmin()->create();
        app(ModulePricingService::class)->seedDefaultsIfEmpty();

        // Simulate a mid-month enable (7 Oct) with a legacy due_on = today invoice.
        $on = \Carbon\Carbon::parse('2026-10-07')->startOfDay();
        $billing = \App\Models\HubModuleBilling::query()->create([
            'hub_id' => $hub->id,
            'module_key' => 'module_social_media_compliance',
            'billed_user_id' => $admin->id,
            'amount' => 5000,
            'currency' => 'gbp',
            'status' => \App\Models\HubModuleBilling::STATUS_UNPAID,
            'payment_status' => \App\Models\HubModuleBilling::STATUS_UNPAID,
            'meta' => ['module_enable_invoice' => true],
        ]);
        Invoice::query()->create([
            'invoice_number' => 'INV-TEST-GRACE-001',
            'user_id' => $admin->id,
            'type' => Invoice::TYPE_MODULE_BILLING,
            'types' => Invoice::TYPES_ONE_TIME,
            'hub_module_billing_id' => $billing->id,
            'description' => 'Module (one time) — SMC',
            'amount' => 5000,
            'credits' => 0,
            'currency' => 'gbp',
            'billing_name' => $admin->name,
            'billing_email' => $admin->email,
            'status' => 'unpaid',
            'due_on' => $on->toDateString(),
            'issued_at' => $on,
        ]);

        $result = app(\App\Services\ModuleRecurringBillingService::class)->enforceGracePenalties($on);

        $this->assertSame(0, $result['disabled_modules']);
        $hub->refresh();
        $this->assertTrue((bool) $hub->resolvedChecklist()['module_social_media_compliance']);
        $this->assertSame(
            '2026-11-01',
            Invoice::query()->where('hub_module_billing_id', $billing->id)->value('due_on')
        );
    }

    public function test_zero_pound_module_still_creates_paid_invoice_on_enable(): void
    {
        $hub = Hub::query()->create([
            'name' => 'WL Hub',
            'slug' => 'wl-smtl-zero',
            'type' => Hub::TYPE_WHITE_LABEL,
            'is_active' => true,
            'checklist' => array_merge(Hub::defaultChecklist(Hub::TYPE_WHITE_LABEL), [
                'charge_amount_per_module' => true,
                'module_social_media_template_library' => false,
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
                'module_social_media_template_library' => true,
            ],
        ]);

        $response->assertOk();
        $created = collect($response->json('module_invoices'));
        $this->assertCount(1, $created);
        $this->assertSame(0.0, (float) $created->first()['amount']);
        $this->assertSame('paid', $created->first()['status']);
        $this->assertStringContainsString('Social Media Template Library', $created->first()['description']);
        $this->assertDatabaseHas('hub_module_billings', [
            'hub_id' => $hub->id,
            'module_key' => 'module_social_media_template_library',
            'status' => 'paid',
            'amount' => 0,
        ]);
    }

    public function test_website_template_library_invoices_catalogue_templates_on_enable(): void
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

        \App\Models\WebsiteCompliance\Template::query()->create([
            'name' => 'Classic',
            'slug' => 'classic',
            'is_active' => true,
        ]);
        \App\Models\WebsiteCompliance\Template::query()->create([
            'name' => 'Modern',
            'slug' => 'modern',
            'is_active' => true,
        ]);
        // Deployed template requests must NOT affect the enable invoice count.
        \App\Models\WebsiteCompliance\TemplateRequest::query()->create([
            'template_name' => 'classic',
            'request_type' => 'advisor_website',
            'domain_name' => 'one.example.test',
            'status' => 'deployed',
            'cpanel_domain' => 'one.example.test',
        ]);
        \App\Models\WebsiteCompliance\TemplateRequest::query()->create([
            'template_name' => 'classic',
            'request_type' => 'advisor_website',
            'domain_name' => 'two.example.test',
            'status' => 'deployed',
            'cpanel_domain' => 'two.example.test',
        ]);
        \App\Models\WebsiteCompliance\TemplateRequest::query()->create([
            'template_name' => 'classic',
            'request_type' => 'advisor_website',
            'domain_name' => 'three.example.test',
            'status' => 'deployed',
            'cpanel_domain' => 'three.example.test',
        ]);

        $response = $this->putJson('/api/power-admin/modules?hub_id='.$hub->id, [
            'hub_id' => $hub->id,
            'modules' => [
                'module_website_template_library' => true,
            ],
        ]);

        $response->assertOk();
        $created = collect($response->json('module_invoices'));
        $this->assertCount(1, $created);
        $this->assertSame(600.0, (float) $created->first()['amount']);
        $this->assertStringContainsString('2 templates', $created->first()['description']);
        $this->assertTrue($hub->fresh()->hasWebsiteTemplateLibraryModule());
        $this->assertSame(1, \App\Models\HubModuleBilling::query()
            ->where('hub_id', $hub->id)
            ->where('module_key', 'module_website_template_library')
            ->count());

        // Saving while already enabled does not create another invoice.
        $again = $this->putJson('/api/power-admin/modules?hub_id='.$hub->id, [
            'hub_id' => $hub->id,
            'modules' => [
                'module_website_template_library' => true,
            ],
        ]);
        $again->assertOk();
        $this->assertSame([], $again->json('module_invoices'));
        $this->assertSame(1, \App\Models\HubModuleBilling::query()
            ->where('hub_id', $hub->id)
            ->where('module_key', 'module_website_template_library')
            ->count());

        $firstInvoiceId = $created->first()['id'];

        // Uncheck then re-check MUST create a new enable invoice and delete the previous one.
        $this->putJson('/api/power-admin/modules?hub_id='.$hub->id, [
            'hub_id' => $hub->id,
            'modules' => [
                'module_website_template_library' => false,
            ],
        ])->assertOk();
        $reenable = $this->putJson('/api/power-admin/modules?hub_id='.$hub->id, [
            'hub_id' => $hub->id,
            'modules' => [
                'module_website_template_library' => true,
            ],
        ]);
        $reenable->assertOk();
        $createdAgain = collect($reenable->json('module_invoices'));
        $this->assertCount(1, $createdAgain);
        $this->assertSame(600.0, (float) $createdAgain->first()['amount']);
        $this->assertNotSame($firstInvoiceId, $createdAgain->first()['id']);
        $this->assertSame(1, \App\Models\HubModuleBilling::query()
            ->where('hub_id', $hub->id)
            ->where('module_key', 'module_website_template_library')
            ->count());
        $this->assertDatabaseMissing('invoices', ['id' => $firstInvoiceId]);

        // No catalogue templates → enabling creates no WTL invoices.
        $hub2 = Hub::query()->create([
            'name' => 'WL Empty',
            'slug' => 'wl-wtl-empty',
            'type' => Hub::TYPE_WHITE_LABEL,
            'is_active' => true,
            'checklist' => array_merge(Hub::defaultChecklist(Hub::TYPE_WHITE_LABEL), [
                'charge_amount_per_module' => true,
                'module_website_template_library' => false,
            ]),
        ]);
        $caps2 = $matrix->resolvedRoleCapabilities($hub2);
        $caps2[User::ROLE_POWER_ADMIN]['dashboard_manage_modules'] = true;
        $hub2->role_capabilities = $caps2;
        $hub2->save();

        $empty = $this->putJson('/api/power-admin/modules?hub_id='.$hub2->id, [
            'hub_id' => $hub2->id,
            'modules' => [
                'module_website_template_library' => true,
            ],
        ]);
        $empty->assertOk();
        $this->assertSame([], $empty->json('module_invoices'));
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
        $this->assertSame('module_pricing_charge_off', $pricingRow['inactive_reason']);
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

    public function test_deployed_website_creates_one_time_invoice_and_recurring_uses_count(): void
    {
        $hub = Hub::query()->create([
            'name' => 'WL Hub',
            'slug' => 'wl-websites',
            'type' => Hub::TYPE_WHITE_LABEL,
            'is_active' => true,
            'advisor_billing_renew_day' => (int) now()->day,
            'checklist' => array_merge(Hub::defaultChecklist(Hub::TYPE_WHITE_LABEL), [
                'charge_amount_per_module' => true,
                'charge_recurring_per_module' => true,
                'module_website_template_library' => true,
            ]),
        ]);

        $admin = User::factory()->powerAdmin()->create();
        app(ModulePricingService::class)->seedDefaultsIfEmpty();

        $billing = app(\App\Services\ModuleBillingService::class);
        $recurring = app(\App\Services\ModuleRecurringBillingService::class);

        $this->assertSame(0, $billing->countDeployedWebsites($hub));

        // No websites yet → no flat WTL recurring invoice.
        $none = $recurring->invoiceFlatRecurringForMonth($hub, $admin, now());
        $this->assertDatabaseMissing('hub_module_recurring_billings', [
            'hub_id' => $hub->id,
            'module_key' => 'module_website_template_library',
        ]);
        unset($none);

        $first = \App\Models\WebsiteCompliance\TemplateRequest::query()->create([
            'template_name' => 'classic',
            'request_type' => 'advisor_website',
            'domain_name' => 'one.example.test',
            'status' => 'deployed',
            'cpanel_domain' => 'one.example.test',
        ]);
        $second = \App\Models\WebsiteCompliance\TemplateRequest::query()->create([
            'template_name' => 'classic',
            'request_type' => 'advisor_website',
            'domain_name' => 'two.example.test',
            'status' => 'deployed',
            'cpanel_domain' => 'two.example.test',
        ]);
        // Pending should not count.
        \App\Models\WebsiteCompliance\TemplateRequest::query()->create([
            'template_name' => 'classic',
            'request_type' => 'advisor_website',
            'domain_name' => 'pending.example.test',
            'status' => 'pending',
        ]);

        $this->assertSame(2, $billing->countDeployedWebsites($hub));

        // Deploy no longer creates one-time invoices (enable uses wc_templates instead).
        $invoice1 = $billing->invoiceWebsiteDeploy($hub, $first, $admin);
        $invoice2 = $billing->invoiceWebsiteDeploy($hub, $second, $admin);
        $this->assertNull($invoice1);
        $this->assertNull($invoice2);
        $this->assertSame(0, \App\Models\HubModuleBilling::query()
            ->where('hub_id', $hub->id)
            ->where('module_key', 'module_website_template_library')
            ->count());

        $flat = $recurring->invoiceFlatRecurringForMonth($hub, $admin, now());
        $this->assertNotEmpty($flat);
        $this->assertDatabaseHas('hub_module_recurring_billings', [
            'hub_id' => $hub->id,
            'module_key' => 'module_website_template_library',
            'user_count' => 2,
            'amount' => 250.00,
            'status' => 'unpaid',
        ]);

        // Idempotent for the calendar month.
        $beforeCount = \App\Models\HubModuleRecurringBilling::query()
            ->where('hub_id', $hub->id)
            ->where('module_key', 'module_website_template_library')
            ->count();
        $recurring->invoiceFlatRecurringForMonth($hub, $admin, now());
        $this->assertSame(
            $beforeCount,
            \App\Models\HubModuleRecurringBilling::query()
                ->where('hub_id', $hub->id)
                ->where('module_key', 'module_website_template_library')
                ->count()
        );
    }
}
