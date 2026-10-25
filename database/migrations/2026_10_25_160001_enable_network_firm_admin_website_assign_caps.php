<?php

use App\Models\Hub;
use App\Models\User;
use App\Services\CapabilitiesMatrixService;
use Illuminate\Database\Migrations\Migration;

/**
 * Ensure Network / Firm Admin have request + assign Website Admin caps ON
 * (re-apply after website_admin role migration for hubs that already had
 * those keys set false).
 */
return new class extends Migration
{
    public function up(): void
    {
        $matrix = app(CapabilitiesMatrixService::class);

        Hub::query()->orderBy('id')->each(function (Hub $hub) use ($matrix) {
            $stored = is_array($hub->role_capabilities) ? $hub->role_capabilities : [];
            $defaults = $matrix->defaultRoleCapabilities($hub->type);
            $dirty = false;

            foreach ([User::ROLE_NETWORK_ADMIN, User::ROLE_FIRM_ADMIN] as $role) {
                if (! isset($stored[$role]) || ! is_array($stored[$role])) {
                    $stored[$role] = $defaults[$role] ?? [];
                    $dirty = true;
                }
                foreach (['wc_request_deployments', 'wc_assign_website_templates'] as $cap) {
                    $desired = (bool) ($defaults[$role][$cap] ?? true);
                    if (($stored[$role][$cap] ?? null) !== $desired) {
                        $stored[$role][$cap] = $desired;
                        $dirty = true;
                    }
                }
            }

            if (! isset($stored[User::ROLE_WEBSITE_ADMIN]) || ! is_array($stored[User::ROLE_WEBSITE_ADMIN])) {
                $stored[User::ROLE_WEBSITE_ADMIN] = $defaults[User::ROLE_WEBSITE_ADMIN] ?? [];
                $dirty = true;
            } else {
                foreach (['wc_edit_sections', 'wc_submit_change_requests'] as $cap) {
                    $desired = (bool) ($defaults[User::ROLE_WEBSITE_ADMIN][$cap] ?? true);
                    if (($stored[User::ROLE_WEBSITE_ADMIN][$cap] ?? null) !== $desired) {
                        $stored[User::ROLE_WEBSITE_ADMIN][$cap] = $desired;
                        $dirty = true;
                    }
                }
            }

            if ($dirty) {
                $hub->forceFill(['role_capabilities' => $stored])->save();
            }
        });
    }

    public function down(): void
    {
        // Leave caps as configured — do not revoke operational defaults.
    }
};
