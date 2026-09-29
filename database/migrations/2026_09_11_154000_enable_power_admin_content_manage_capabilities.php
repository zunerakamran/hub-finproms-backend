<?php

use App\Models\Hub;
use App\Models\User;
use App\Services\CapabilitiesMatrixService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /** @var list<string> */
    private const KEYS = [
        'dashboard_manage_posts',
        'dashboard_manage_bundles',
        'dashboard_manage_types',
        'dashboard_manage_categories',
        'dashboard_manage_tags',
    ];

    public function up(): void
    {
        $matrix = app(CapabilitiesMatrixService::class);

        Hub::query()->orderBy('id')->each(function (Hub $hub) use ($matrix) {
            $roleCaps = $matrix->resolvedRoleCapabilities($hub);
            $role = User::ROLE_POWER_ADMIN;

            if (! isset($roleCaps[$role]) || ! is_array($roleCaps[$role])) {
                $roleCaps[$role] = [];
            }

            foreach (self::KEYS as $key) {
                $roleCaps[$role][$key] = true;
            }

            $hub->role_capabilities = $roleCaps;

            $checklist = $hub->resolvedChecklist();
            foreach (self::KEYS as $key) {
                $checklist[$key] = true;
            }
            $hub->checklist = $checklist;
            $hub->save();
        });
    }

    public function down(): void
    {
        // Non-destructive — do not revoke Power Admin content tools on rollback.
    }
};
