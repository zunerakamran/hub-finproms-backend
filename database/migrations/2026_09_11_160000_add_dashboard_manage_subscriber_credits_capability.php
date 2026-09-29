<?php

use App\Models\Hub;
use App\Models\User;
use App\Services\CapabilitiesMatrixService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $matrix = app(CapabilitiesMatrixService::class);
        $key = 'dashboard_manage_subscriber_credits';

        Hub::query()->orderBy('id')->each(function (Hub $hub) use ($matrix, $key) {
            $roleCaps = $matrix->resolvedRoleCapabilities($hub);

            foreach ([User::ROLE_POWER_ADMIN, User::ROLE_FINPROMS_ADMIN] as $role) {
                if (! isset($roleCaps[$role]) || ! is_array($roleCaps[$role])) {
                    $roleCaps[$role] = [];
                }
                // Power Admin always; FinProms admin on white-label hubs.
                $roleCaps[$role][$key] = $role === User::ROLE_POWER_ADMIN
                    || $hub->type === Hub::TYPE_WHITE_LABEL;
            }

            foreach ([User::ROLE_CLIENT_ADMIN, User::ROLE_MANAGER] as $role) {
                if (! isset($roleCaps[$role]) || ! is_array($roleCaps[$role])) {
                    $roleCaps[$role] = [];
                }
                if (! array_key_exists($key, $roleCaps[$role])) {
                    $roleCaps[$role][$key] = false;
                }
            }

            $hub->role_capabilities = $roleCaps;
            $checklist = $hub->resolvedChecklist();
            $checklist[$key] = ! empty($roleCaps[User::ROLE_POWER_ADMIN][$key])
                || ! empty($roleCaps[User::ROLE_FINPROMS_ADMIN][$key])
                || ! empty($roleCaps[User::ROLE_CLIENT_ADMIN][$key])
                || ! empty($roleCaps[User::ROLE_MANAGER][$key]);
            $hub->checklist = $checklist;
            $hub->save();
        });
    }

    public function down(): void
    {
        // Non-destructive.
    }
};
