<?php

namespace Tests\Feature;

use App\Models\Hub;
use App\Models\HubModuleInvoiceSettings;
use App\Models\ModulePricing;
use App\Models\User;
use App\Services\CapabilitiesMatrixService;
use App\Services\HubService;
use App\Services\ModulePricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ResellerModulePricingTest extends TestCase
{
    use RefreshDatabase;

    private function whiteLabelHub(): Hub
    {
        config([
            'hub.current_slug' => 'wl-reseller',
            'hub.type' => 'white_label',
            'hub.is_control_plane' => false,
        ]);

        $hub = Hub::query()->create([
            'name' => 'WL Reseller',
            'slug' => 'wl-reseller',
            'type' => Hub::TYPE_WHITE_LABEL,
            'is_active' => true,
            'checklist' => array_merge(Hub::defaultChecklist(Hub::TYPE_WHITE_LABEL), [
                'charge_amount_per_module' => true,
                'charge_recurring_per_module' => true,
            ]),
        ]);
        app(HubService::class)->forgetCurrentCache();
        app(CapabilitiesMatrixService::class)->forgetResolvedCaches();

        return $hub;
    }

    public function test_reseller_pricing_lists_only_per_user_catalogue_rows(): void
    {
        $hub = $this->whiteLabelHub();
        HubModuleInvoiceSettings::query()->updateOrCreate(
            ['hub_id' => $hub->id],
            [
                'network_pays_all' => false,
                'firms_pay_own' => false,
                'users_pay_own' => false,
                'network_resells' => true,
                'reseller_invoice_text' => 'Resold by network',
                'reseller_invoice_format' => 'standard',
                'reseller_logo_path' => 'module-invoice-reseller-logos/test.png',
                'reseller_logo_name' => 'test.png',
            ]
        );

        app(ModulePricingService::class)->seedDefaultsIfEmpty();
        ModulePricing::query()->where('module_key', 'module_social_media_compliance')->update([
            'billing_unit' => ModulePricingService::UNIT_PER_NETWORK,
            'recurring_billing_unit' => ModulePricingService::UNIT_PER_USER,
            'recurring_amount' => 10,
        ]);
        ModulePricing::query()->where('module_key', 'module_website_compliance')->update([
            'billing_unit' => ModulePricingService::UNIT_PER_NETWORK,
            'recurring_billing_unit' => ModulePricingService::UNIT_PER_FIRM,
            'recurring_amount' => 20,
        ]);

        $matrix = app(CapabilitiesMatrixService::class);
        $caps = $matrix->resolvedRoleCapabilities($hub);
        $caps[User::ROLE_NETWORK_ADMIN]['dashboard_manage_reseller_module_pricing'] = true;
        $hub->role_capabilities = $caps;
        $hub->save();
        $matrix->forgetResolvedCaches();

        $admin = User::factory()->create(['role' => User::ROLE_NETWORK_ADMIN]);
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/admin/reseller-module-pricing');
        $response->assertOk();
        $keys = collect($response->json('pricing'))->pluck('module_key');
        $this->assertTrue($keys->contains('module_social_media_compliance'));
        $this->assertFalse($keys->contains('module_website_compliance'));

        $social = collect($response->json('pricing'))
            ->firstWhere('module_key', 'module_social_media_compliance');
        $this->assertFalse((bool) ($social['allows_one_time'] ?? true));
        $this->assertTrue((bool) ($social['allows_recurring'] ?? false));

        $save = $this->putJson('/api/admin/reseller-module-pricing/module_social_media_compliance', [
            'amount' => 15.5,
            'recurring_amount' => 9.25,
            'is_active' => true,
        ]);
        $save->assertOk();
        // One-time unit is not per-user, so amount must stay unset / zero.
        $this->assertNull($save->json('pricing.amount'));
        $this->assertFalse((bool) $save->json('pricing.allows_one_time'));
        $this->assertTrue((bool) $save->json('pricing.allows_recurring'));
        $this->assertSame(9.25, (float) $save->json('pricing.recurring_amount'));
    }

    public function test_reseller_pricing_updates_one_time_only_when_main_one_time_is_per_user(): void
    {
        $hub = $this->whiteLabelHub();
        HubModuleInvoiceSettings::query()->updateOrCreate(
            ['hub_id' => $hub->id],
            [
                'network_pays_all' => false,
                'firms_pay_own' => false,
                'users_pay_own' => false,
                'network_resells' => true,
                'reseller_invoice_text' => 'Resold by network',
                'reseller_invoice_format' => 'standard',
                'reseller_logo_path' => 'module-invoice-reseller-logos/test.png',
                'reseller_logo_name' => 'test.png',
            ]
        );

        app(ModulePricingService::class)->seedDefaultsIfEmpty();
        ModulePricing::query()->where('module_key', 'module_social_media_compliance')->update([
            'billing_unit' => ModulePricingService::UNIT_PER_USER,
            'amount' => 12,
            'recurring_billing_unit' => ModulePricingService::UNIT_PER_NETWORK,
            'recurring_amount' => 5,
        ]);

        $matrix = app(CapabilitiesMatrixService::class);
        $caps = $matrix->resolvedRoleCapabilities($hub);
        $caps[User::ROLE_NETWORK_ADMIN]['dashboard_manage_reseller_module_pricing'] = true;
        $hub->role_capabilities = $caps;
        $hub->save();
        $matrix->forgetResolvedCaches();

        $admin = User::factory()->create(['role' => User::ROLE_NETWORK_ADMIN]);
        Sanctum::actingAs($admin);

        $list = $this->getJson('/api/admin/reseller-module-pricing');
        $list->assertOk();
        $row = collect($list->json('pricing'))
            ->firstWhere('module_key', 'module_social_media_compliance');
        $this->assertTrue((bool) ($row['allows_one_time'] ?? false));
        $this->assertFalse((bool) ($row['allows_recurring'] ?? true));

        $save = $this->putJson('/api/admin/reseller-module-pricing/module_social_media_compliance', [
            'amount' => 18.5,
            'recurring_amount' => 99,
            'is_active' => true,
        ]);
        $save->assertOk();
        $this->assertSame(18.5, (float) $save->json('pricing.amount'));
        $this->assertNull($save->json('pricing.recurring_amount'));
        $this->assertTrue((bool) $save->json('pricing.allows_one_time'));
        $this->assertFalse((bool) $save->json('pricing.allows_recurring'));
    }

    public function test_network_stripe_requires_network_resells_to_save(): void
    {
        $hub = $this->whiteLabelHub();
        $matrix = app(CapabilitiesMatrixService::class);
        $caps = $matrix->resolvedRoleCapabilities($hub);
        $caps[User::ROLE_NETWORK_ADMIN]['dashboard_manage_network_stripe'] = true;
        $hub->role_capabilities = $caps;
        $hub->save();
        $matrix->forgetResolvedCaches();

        $admin = User::factory()->create(['role' => User::ROLE_NETWORK_ADMIN]);
        Sanctum::actingAs($admin);

        // Menu/API readable with capability alone; saving still needs network_resells.
        $blocked = $this->putJson('/api/admin/network-stripe', [
            'hub_stripe_key' => 'pk_test_reseller',
            'hub_stripe_currency' => 'gbp',
        ]);
        $blocked->assertStatus(422);

        HubModuleInvoiceSettings::query()->updateOrCreate(
            ['hub_id' => $hub->id],
            [
                'network_pays_all' => false,
                'firms_pay_own' => false,
                'users_pay_own' => false,
                'network_resells' => true,
                'reseller_invoice_text' => 'Resold by network',
                'reseller_invoice_format' => 'standard',
                'reseller_logo_path' => 'module-invoice-reseller-logos/test.png',
                'reseller_logo_name' => 'test.png',
            ]
        );
        $matrix->forgetResolvedCaches();

        $show = $this->getJson('/api/admin/network-stripe');
        $show->assertOk();
        $this->assertTrue($show->json('network_resells'));

        $update = $this->putJson('/api/admin/network-stripe', [
            'hub_stripe_key' => 'pk_test_reseller',
            'hub_stripe_currency' => 'gbp',
        ]);
        $update->assertOk();
        $this->assertSame('pk_test_reseller', $update->json('hub.stripe.key'));
    }
}
