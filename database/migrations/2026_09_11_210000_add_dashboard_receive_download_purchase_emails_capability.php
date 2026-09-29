<?php

use App\Models\Hub;
use App\Services\CapabilitiesMatrixService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $matrix = app(CapabilitiesMatrixService::class);
        $key = 'dashboard_receive_download_purchase_emails';

        Hub::query()->orderBy('id')->each(function (Hub $hub) use ($matrix, $key) {
            $roleCaps = $matrix->resolvedRoleCapabilities($hub);
            $defaults = $matrix->defaultRoleCapabilities($hub->type);

            foreach (CapabilitiesMatrixService::MATRIX_ROLES as $role) {
                if (! isset($roleCaps[$role]) || ! is_array($roleCaps[$role])) {
                    $roleCaps[$role] = [];
                }
                if (! array_key_exists($key, $roleCaps[$role])) {
                    $roleCaps[$role][$key] = (bool) ($defaults[$role][$key] ?? false);
                }
            }

            $hub->role_capabilities = $roleCaps;
            $checklist = $hub->resolvedChecklist();
            $checklist[$key] = collect($roleCaps)->contains(
                fn ($caps) => is_array($caps) && ! empty($caps[$key])
            );
            $hub->checklist = $checklist;
            $hub->save();
        });
    }

    public function down(): void
    {
        // Non-destructive.
    }
};
