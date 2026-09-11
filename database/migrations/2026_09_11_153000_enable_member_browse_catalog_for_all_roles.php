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

        Hub::query()->orderBy('id')->each(function (Hub $hub) use ($matrix) {
            $roleCaps = $matrix->resolvedRoleCapabilities($hub);

            foreach (CapabilitiesMatrixService::MATRIX_ROLES as $role) {
                if (! isset($roleCaps[$role]) || ! is_array($roleCaps[$role])) {
                    $roleCaps[$role] = [];
                }

                // Catalog browse for every role (Power Admin → Member).
                $roleCaps[$role]['member_browse_catalog'] = true;

                // Seed any other missing member caps from defaults for staff roles
                // that previously had no member column cells.
                foreach (Hub::CHECKLIST_DEFINITIONS as $key => $meta) {
                    if (($meta['group'] ?? null) !== Hub::GROUP_MEMBER) {
                        continue;
                    }
                    if ($key === 'member_browse_catalog') {
                        continue;
                    }
                    if (! array_key_exists($key, $roleCaps[$role])) {
                        $defaults = $matrix->defaultRoleCapabilities($hub->type);
                        $roleCaps[$role][$key] = (bool) ($defaults[$role][$key] ?? false);
                    }
                }
            }

            $hub->role_capabilities = $roleCaps;

            $checklist = $hub->resolvedChecklist();
            $checklist['member_browse_catalog'] = true;
            $hub->checklist = $checklist;
            $hub->save();
        });
    }

    public function down(): void
    {
        // Intentionally non-destructive — do not revoke browse access on rollback.
    }
};
