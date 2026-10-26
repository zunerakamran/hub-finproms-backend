<?php

use App\Models\Hub;
use App\Models\User;
use App\Services\CapabilitiesMatrixService;
use Illuminate\Database\Migrations\Migration;

/**
 * Force default grants for module-invoice related caps (previous migration
 * skipped keys already present as false from resolved defaults).
 */
return new class extends Migration
{
    public function up(): void
    {
        $matrix = app(CapabilitiesMatrixService::class);

        Hub::query()->orderBy('id')->each(function (Hub $hub) use ($matrix) {
            $roleCaps = $matrix->resolvedRoleCapabilities($hub);

            foreach (array_keys($roleCaps) as $role) {
                if (! is_array($roleCaps[$role] ?? null)) {
                    $roleCaps[$role] = [];
                }

                $paymentCard = in_array($role, [
                    User::ROLE_CLIENT_ADMIN,
                    'admin',
                    User::ROLE_NETWORK_ADMIN,
                    User::ROLE_FIRM_ADMIN,
                    User::ROLE_ACCOUNTS_ADMIN,
                ], true);

                $view = in_array($role, [
                    User::ROLE_POWER_ADMIN,
                    User::ROLE_FINPROMS_ADMIN,
                    User::ROLE_CLIENT_ADMIN,
                    'admin',
                    User::ROLE_NETWORK_ADMIN,
                    User::ROLE_FIRM_ADMIN,
                    User::ROLE_ACCOUNTS_ADMIN,
                ], true);

                $viewAll = in_array($role, [
                    User::ROLE_POWER_ADMIN,
                    User::ROLE_FINPROMS_ADMIN,
                    User::ROLE_CLIENT_ADMIN,
                    'admin',
                    User::ROLE_NETWORK_ADMIN,
                ], true);

                $markPaid = in_array($role, [
                    User::ROLE_POWER_ADMIN,
                    User::ROLE_FINPROMS_ADMIN,
                    User::ROLE_ACCOUNTS_ADMIN,
                ], true);

                $settings = in_array($role, [
                    User::ROLE_POWER_ADMIN,
                    User::ROLE_FINPROMS_ADMIN,
                    User::ROLE_CLIENT_ADMIN,
                    'admin',
                    User::ROLE_NETWORK_ADMIN,
                ], true);

                $roleCaps[$role]['dashboard_manage_payment_card'] = $paymentCard
                    || ! empty($roleCaps[$role]['dashboard_manage_payment_card']);
                $roleCaps[$role]['dashboard_view_module_invoices'] = $view
                    || ! empty($roleCaps[$role]['dashboard_view_module_invoices']);
                $roleCaps[$role]['dashboard_view_all_module_invoices'] = $viewAll
                    || ! empty($roleCaps[$role]['dashboard_view_all_module_invoices']);
                $roleCaps[$role]['dashboard_mark_module_invoices_paid'] = $markPaid
                    || ! empty($roleCaps[$role]['dashboard_mark_module_invoices_paid']);
                $roleCaps[$role]['dashboard_manage_module_invoice_settings'] = $settings
                    || ! empty($roleCaps[$role]['dashboard_manage_module_invoice_settings']);
            }

            $hub->role_capabilities = $roleCaps;
            $checklist = $hub->resolvedChecklist();
            foreach ([
                'dashboard_manage_payment_card',
                'dashboard_view_module_invoices',
                'dashboard_view_all_module_invoices',
                'dashboard_mark_module_invoices_paid',
                'dashboard_manage_module_invoice_settings',
            ] as $key) {
                $any = false;
                foreach ($roleCaps as $caps) {
                    if (! empty($caps[$key])) {
                        $any = true;
                        break;
                    }
                }
                $checklist[$key] = $any;
            }
            $hub->checklist = $checklist;
            $hub->save();
        });

        $matrix->forgetResolvedCaches();
    }

    public function down(): void
    {
        // Keep grants; do not strip on rollback.
    }
};
