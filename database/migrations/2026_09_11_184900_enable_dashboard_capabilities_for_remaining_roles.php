<?php

use App\Models\Hub;
use App\Models\User;
use App\Services\CapabilitiesMatrixService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /** @var list<string> */
    private const REMAINING_ROLES = [
        User::ROLE_APPROVER,
        User::ROLE_ADVISOR,
        User::ROLE_USER,
    ];

    public function up(): void
    {
        $matrix = app(CapabilitiesMatrixService::class);

        Hub::query()->orderBy('id')->each(function (Hub $hub) use ($matrix) {
            $roleCaps = $matrix->resolvedRoleCapabilities($hub);

            foreach (self::REMAINING_ROLES as $role) {
                if (! isset($roleCaps[$role]) || ! is_array($roleCaps[$role])) {
                    $roleCaps[$role] = [];
                }

                foreach (Hub::CHECKLIST_DEFINITIONS as $key => $meta) {
                    if (($meta['group'] ?? null) !== Hub::GROUP_DASHBOARD) {
                        continue;
                    }
                    // Seed missing cells; keep any value Power Admin already set.
                    if (! array_key_exists($key, $roleCaps[$role])) {
                        $roleCaps[$role][$key] = false;
                    }
                }
            }

            // Ensure Power Admin / staff columns also have any newly applicable keys.
            foreach (CapabilitiesMatrixService::MATRIX_ROLES as $role) {
                if (in_array($role, self::REMAINING_ROLES, true)) {
                    continue;
                }
                if (! isset($roleCaps[$role]) || ! is_array($roleCaps[$role])) {
                    $roleCaps[$role] = [];
                }
                $defaults = $matrix->defaultRoleCapabilities($hub->type);
                foreach (Hub::CHECKLIST_DEFINITIONS as $key => $meta) {
                    if (($meta['group'] ?? null) !== Hub::GROUP_DASHBOARD) {
                        continue;
                    }
                    if (! array_key_exists($key, $roleCaps[$role])) {
                        $roleCaps[$role][$key] = (bool) ($defaults[$role][$key] ?? false);
                    }
                }
            }

            $hub->role_capabilities = $roleCaps;
            $hub->save();
        });
    }

    public function down(): void
    {
        // Non-destructive — do not revoke matrix cells on rollback.
    }
};
