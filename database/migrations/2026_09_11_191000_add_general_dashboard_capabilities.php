<?php

use App\Models\Hub;
use App\Services\CapabilitiesMatrixService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $matrix = app(CapabilitiesMatrixService::class);
        $keys = Hub::GENERAL_DASHBOARD_KEYS;

        Hub::query()->orderBy('id')->each(function (Hub $hub) use ($matrix, $keys) {
            $roleCaps = $matrix->resolvedRoleCapabilities($hub);
            $defaults = $matrix->defaultRoleCapabilities($hub->type);

            foreach ($roleCaps as $role => $caps) {
                if (! is_array($caps)) {
                    $roleCaps[$role] = [];
                }
                foreach ($keys as $key) {
                    if (! array_key_exists($key, $roleCaps[$role])) {
                        $roleCaps[$role][$key] = (bool) ($defaults[$role][$key] ?? true);
                    }
                }
            }

            $hub->role_capabilities = $roleCaps;

            $checklist = $hub->resolvedChecklist();
            foreach ($keys as $key) {
                $anyEnabled = false;
                foreach ($roleCaps as $caps) {
                    if (! empty($caps[$key])) {
                        $anyEnabled = true;
                        break;
                    }
                }
                $checklist[$key] = $anyEnabled;
            }
            $hub->checklist = $checklist;
            $hub->save();
        });
    }

    public function down(): void
    {
        // Non-destructive.
    }
};
