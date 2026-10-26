<?php

namespace Tests\Feature;

use App\Models\Firm;
use App\Models\Hub;
use App\Models\HubModuleBilling;
use App\Models\HubModuleInvoiceSettings;
use App\Models\Invoice;
use App\Models\User;
use App\Services\CapabilitiesMatrixService;
use App\Services\HubService;
use App\Services\ModuleInvoiceSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ModuleInvoiceVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function whiteLabelHub(): Hub
    {
        config([
            'hub.current_slug' => 'wl-invoices',
            'hub.type' => 'white_label',
            'hub.is_control_plane' => false,
        ]);

        $hub = Hub::query()->create([
            'name' => 'WL Invoices',
            'slug' => 'wl-invoices',
            'type' => Hub::TYPE_WHITE_LABEL,
            'is_active' => true,
            'checklist' => array_merge(Hub::defaultChecklist(Hub::TYPE_WHITE_LABEL), [
                'charge_amount_per_module' => true,
            ]),
        ]);
        app(HubService::class)->forgetCurrentCache();
        app(CapabilitiesMatrixService::class)->forgetResolvedCaches();

        return $hub;
    }

    private function enableFirmsPayOwn(Hub $hub): void
    {
        HubModuleInvoiceSettings::query()->updateOrCreate(
            ['hub_id' => $hub->id],
            [
                'network_pays_all' => false,
                'firms_pay_own' => true,
                'users_pay_own' => false,
                'network_resells' => false,
            ]
        );
    }

    private function grantCaps(Hub $hub, string $role, array $caps): void
    {
        $matrix = app(CapabilitiesMatrixService::class);
        $roleCaps = $matrix->resolvedRoleCapabilities($hub);
        foreach ($caps as $key => $on) {
            $roleCaps[$role][$key] = $on;
        }
        $hub->role_capabilities = $roleCaps;
        $hub->save();
        $matrix->forgetResolvedCaches();
    }

    private function makeModuleInvoice(
        Hub $hub,
        User $billedUser,
        string $payerScope,
        ?int $firmId,
        string $number
    ): Invoice {
        $billing = HubModuleBilling::query()->create([
            'hub_id' => $hub->id,
            'module_key' => 'module_social_media_compliance',
            'billed_user_id' => $billedUser->id,
            'payer_scope' => $payerScope,
            'firm_id' => $firmId,
            'amount' => 100,
            'currency' => 'gbp',
            'status' => HubModuleBilling::STATUS_UNPAID,
            'payment_status' => HubModuleBilling::STATUS_UNPAID,
            'meta' => ['payer_scope' => $payerScope],
        ]);

        return Invoice::query()->create([
            'invoice_number' => $number,
            'user_id' => $billedUser->id,
            'type' => Invoice::TYPE_MODULE_BILLING,
            'types' => Invoice::TYPES_ONE_TIME,
            'hub_module_billing_id' => $billing->id,
            'description' => 'Module invoice '.$number,
            'amount' => 100,
            'credits' => 0,
            'currency' => 'gbp',
            'billing_name' => $billedUser->name,
            'billing_email' => $billedUser->email,
            'status' => 'unpaid',
            'issued_at' => now(),
        ]);
    }

    public function test_firm_admin_sees_only_own_firm_module_invoices(): void
    {
        $hub = $this->whiteLabelHub();
        $this->enableFirmsPayOwn($hub);

        // Legacy blanket grant still present on many hubs — must not open network invoices.
        $this->grantCaps($hub, User::ROLE_FIRM_ADMIN, [
            'dashboard_view_firm_module_invoices' => true,
            'dashboard_view_network_module_invoices' => false,
            'dashboard_view_module_invoices' => true,
            'dashboard_view_all_module_invoices' => false,
        ]);
        $this->grantCaps($hub, User::ROLE_NETWORK_ADMIN, [
            'dashboard_view_network_module_invoices' => true,
            'dashboard_view_firm_module_invoices' => false,
            'dashboard_view_module_invoices' => false,
            'dashboard_view_all_module_invoices' => false,
        ]);

        $firmA = Firm::query()->create(['name' => 'Firm A']);
        $firmB = Firm::query()->create(['name' => 'Firm B']);

        $networkUser = User::factory()->create(['role' => User::ROLE_NETWORK_ADMIN]);
        $firmAdminA = User::factory()->create([
            'role' => User::ROLE_FIRM_ADMIN,
            'firm_id' => $firmA->id,
        ]);
        $payer = User::factory()->create(['role' => User::ROLE_USER]);

        $networkInvoice = $this->makeModuleInvoice(
            $hub,
            $payer,
            ModuleInvoiceSettingsService::PAYER_NETWORK,
            null,
            'INV-NET-1'
        );
        $firmAInvoice = $this->makeModuleInvoice(
            $hub,
            $payer,
            ModuleInvoiceSettingsService::PAYER_FIRM,
            $firmA->id,
            'INV-FIRM-A'
        );
        $firmBInvoice = $this->makeModuleInvoice(
            $hub,
            $payer,
            ModuleInvoiceSettingsService::PAYER_FIRM,
            $firmB->id,
            'INV-FIRM-B'
        );

        Sanctum::actingAs($firmAdminA);
        $firmResponse = $this->getJson('/api/admin/module-invoices');
        $firmResponse->assertOk();
        $firmIds = collect($firmResponse->json('data'))->pluck('id');
        $this->assertTrue($firmIds->contains($firmAInvoice->id));
        $this->assertFalse($firmIds->contains($networkInvoice->id));
        $this->assertFalse($firmIds->contains($firmBInvoice->id));

        Sanctum::actingAs($networkUser);
        $networkResponse = $this->getJson('/api/admin/module-invoices');
        $networkResponse->assertOk();
        $networkIds = collect($networkResponse->json('data'))->pluck('id');
        $this->assertTrue($networkIds->contains($networkInvoice->id));
        $this->assertFalse($networkIds->contains($firmAInvoice->id));
        $this->assertFalse($networkIds->contains($firmBInvoice->id));
    }

    public function test_accounts_admin_with_both_caps_sees_network_and_all_firms(): void
    {
        $hub = $this->whiteLabelHub();
        $this->enableFirmsPayOwn($hub);
        $this->grantCaps($hub, User::ROLE_ACCOUNTS_ADMIN, [
            'dashboard_view_network_module_invoices' => true,
            'dashboard_view_firm_module_invoices' => true,
            'dashboard_view_module_invoices' => false,
            'dashboard_view_all_module_invoices' => false,
        ]);

        $firmA = Firm::query()->create(['name' => 'Firm A']);
        $firmB = Firm::query()->create(['name' => 'Firm B']);
        $accounts = User::factory()->create([
            'role' => User::ROLE_ACCOUNTS_ADMIN,
            'firm_id' => null,
        ]);
        $payer = User::factory()->create(['role' => User::ROLE_USER]);

        $networkInvoice = $this->makeModuleInvoice(
            $hub,
            $payer,
            ModuleInvoiceSettingsService::PAYER_NETWORK,
            null,
            'INV-NET-2'
        );
        $firmAInvoice = $this->makeModuleInvoice(
            $hub,
            $payer,
            ModuleInvoiceSettingsService::PAYER_FIRM,
            $firmA->id,
            'INV-FIRM-A2'
        );
        $firmBInvoice = $this->makeModuleInvoice(
            $hub,
            $payer,
            ModuleInvoiceSettingsService::PAYER_FIRM,
            $firmB->id,
            'INV-FIRM-B2'
        );

        Sanctum::actingAs($accounts);
        $response = $this->getJson('/api/admin/module-invoices');
        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($networkInvoice->id));
        $this->assertTrue($ids->contains($firmAInvoice->id));
        $this->assertTrue($ids->contains($firmBInvoice->id));
    }
}
