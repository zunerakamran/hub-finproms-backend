<?php

use App\Models\Hub;
use App\Models\User;
use App\Services\CapabilitiesMatrixService;
use Illuminate\Database\Migrations\Migration;

/**
 * Seed payment-card + hub-wide module-invoice view capabilities.
 * Defaults illustrate common roles; Power Admin may grant any role.
 */
return new class extends Migration
{
    public function up(): void
    {
        $matrix = app(CapabilitiesMatrixService::class);

        $paymentCard = 'dashboard_manage_payment_card';
        $viewAll = 'dashboard_view_all_module_invoices';

        Hub::query()->orderBy('id')->each(function (Hub $hub) use ($matrix, $paymentCard, $viewAll) {
            $roleCaps = $matrix->resolvedRoleCapabilities($hub);

            foreach (array_keys($roleCaps) as $role) {
                if (! is_array($roleCaps[$role] ?? null)) {
                    $roleCaps[$role] = [];
                }

                if (! array_key_exists($paymentCard, $roleCaps[$role])) {
                    $roleCaps[$role][$paymentCard] = in_array($role, [
                        User::ROLE_CLIENT_ADMIN,
                        'admin',
                        User::ROLE_NETWORK_ADMIN,
                        User::ROLE_FIRM_ADMIN,
                        User::ROLE_ACCOUNTS_ADMIN,
                    ], true);
                }

                if (! array_key_exists($viewAll, $roleCaps[$role])) {
                    $roleCaps[$role][$viewAll] = in_array($role, [
                        User::ROLE_POWER_ADMIN,
                        User::ROLE_FINPROMS_ADMIN,
                        User::ROLE_CLIENT_ADMIN,
                        'admin',
                        User::ROLE_NETWORK_ADMIN,
                    ], true);
                }
            }

            $hub->role_capabilities = $roleCaps;

            $checklist = $hub->resolvedChecklist();
            foreach ([$paymentCard, $viewAll] as $key) {
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
        Hub::query()->orderBy('id')->each(function (Hub $hub) {
            $roleCaps = is_array($hub->role_capabilities) ? $hub->role_capabilities : [];
            foreach ($roleCaps as $role => $caps) {
                if (is_array($caps)) {
                    unset(
                        $roleCaps[$role]['dashboard_manage_payment_card'],
                        $roleCaps[$role]['dashboard_view_all_module_invoices']
                    );
                }
            }
            $checklist = is_array($hub->checklist) ? $hub->checklist : [];
            unset(
                $checklist['dashboard_manage_payment_card'],
                $checklist['dashboard_view_all_module_invoices']
            );
            $hub->role_capabilities = $roleCaps;
            $hub->checklist = $checklist;
            $hub->save();
        });

        app(CapabilitiesMatrixService::class)->forgetResolvedCaches();
    }
};
