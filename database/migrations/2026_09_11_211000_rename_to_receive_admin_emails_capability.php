<?php

use App\Models\Hub;
use App\Services\CapabilitiesMatrixService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    private const OLD_KEY = 'dashboard_receive_download_purchase_emails';

    private const NEW_KEY = 'receive_admin_emails';

    public function up(): void
    {
        $matrix = app(CapabilitiesMatrixService::class);

        Hub::query()->orderBy('id')->each(function (Hub $hub) use ($matrix) {
            $roleCaps = $matrix->resolvedRoleCapabilities($hub);
            $defaults = $matrix->defaultRoleCapabilities($hub->type);

            foreach (CapabilitiesMatrixService::MATRIX_ROLES as $role) {
                if (! isset($roleCaps[$role]) || ! is_array($roleCaps[$role])) {
                    $roleCaps[$role] = [];
                }

                // Prefer previously saved download-purchase email preference.
                if (array_key_exists(self::OLD_KEY, $roleCaps[$role])) {
                    $roleCaps[$role][self::NEW_KEY] = (bool) $roleCaps[$role][self::OLD_KEY];
                    unset($roleCaps[$role][self::OLD_KEY]);
                } elseif (! array_key_exists(self::NEW_KEY, $roleCaps[$role])) {
                    $roleCaps[$role][self::NEW_KEY] = (bool) ($defaults[$role][self::NEW_KEY] ?? false);
                }
            }

            $hub->role_capabilities = $roleCaps;
            $checklist = $hub->resolvedChecklist();
            unset($checklist[self::OLD_KEY]);
            $checklist[self::NEW_KEY] = collect($roleCaps)->contains(
                fn ($caps) => is_array($caps) && ! empty($caps[self::NEW_KEY])
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
