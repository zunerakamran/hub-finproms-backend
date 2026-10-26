<?php

use App\Models\Hub;
use App\Models\User;
use App\Services\CapabilitiesMatrixService;
use Illuminate\Database\Migrations\Migration;

/**
 * Firm Admin previously received dashboard_view_module_invoices (legacy), which
 * made Module invoices show every network-scoped invoice. Firm Admin should only
 * use dashboard_view_firm_module_invoices (firm-payable rows for their firm).
 */
return new class extends Migration
{
    public function up(): void
    {
        $matrix = app(CapabilitiesMatrixService::class);

        Hub::query()->orderBy('id')->each(function (Hub $hub) use ($matrix) {
            $roleCaps = $matrix->resolvedRoleCapabilities($hub);
            if (! is_array($roleCaps[User::ROLE_FIRM_ADMIN] ?? null)) {
                return;
            }

            $roleCaps[User::ROLE_FIRM_ADMIN]['dashboard_view_module_invoices'] = false;
            $roleCaps[User::ROLE_FIRM_ADMIN]['dashboard_view_all_module_invoices'] = false;
            $roleCaps[User::ROLE_FIRM_ADMIN]['dashboard_view_network_module_invoices'] = false;
            // Keep firm view available as a stored default; settings gate still applies.
            $roleCaps[User::ROLE_FIRM_ADMIN]['dashboard_view_firm_module_invoices'] = true;

            $hub->role_capabilities = $roleCaps;
            $hub->save();
        });

        $matrix->forgetResolvedCaches();
    }

    public function down(): void
    {
        // Do not restore the over-broad legacy grant.
    }
};
