<?php

use App\Models\Hub;
use App\Models\User;
use App\Services\CapabilitiesMatrixService;
use Illuminate\Database\Migrations\Migration;

/**
 * Force-enable dashboard_manage_module_invoice_settings for the same roles that
 * manage module pricing. The previous migration skipped keys already present
 * (as false) from resolved defaults.
 */
return new class extends Migration
{
    public function up(): void
    {
        $matrix = app(CapabilitiesMatrixService::class);
        $key = 'dashboard_manage_module_invoice_settings';

        Hub::query()->orderBy('id')->each(function (Hub $hub) use ($matrix, $key) {
            $roleCaps = $matrix->resolvedRoleCapabilities($hub);

            foreach (array_keys($roleCaps) as $role) {
                if (! is_array($roleCaps[$role] ?? null)) {
                    $roleCaps[$role] = [];
                }

                $enable = $role === User::ROLE_POWER_ADMIN
                    || $role === User::ROLE_FINPROMS_ADMIN
                    || (
                        $hub->isWhiteLabel()
                        && in_array($role, [
                            User::ROLE_CLIENT_ADMIN,
                            User::ROLE_NETWORK_ADMIN,
                            'admin',
                        ], true)
                    )
                    // Shared: also match anyone who can already set module prices.
                    || ! empty($roleCaps[$role]['dashboard_manage_module_pricing']);

                $roleCaps[$role][$key] = (bool) $enable;
            }

            $hub->role_capabilities = $roleCaps;
            $checklist = $hub->resolvedChecklist();
            $any = false;
            foreach ($roleCaps as $caps) {
                if (! empty($caps[$key])) {
                    $any = true;
                    break;
                }
            }
            $checklist[$key] = $any;
            $hub->checklist = $checklist;
            $hub->save();
        });
    }

    public function down(): void
    {
        // Keep capability rows; do not strip grants on rollback.
    }
};
