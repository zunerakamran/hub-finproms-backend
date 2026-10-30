<?php

use App\Models\Hub;
use App\Models\User;
use App\Services\CapabilitiesMatrixService;
use Illuminate\Database\Migrations\Migration;

/**
 * Add st_view_reports — Support Tickets report (stats, charts, firm/network
 * comparison, CSV export). Defaults OFF except Top Management (aligned with
 * other report capabilities).
 */
return new class extends Migration
{
    private const KEY = 'st_view_reports';

    public function up(): void
    {
        $matrix = app(CapabilitiesMatrixService::class);

        Hub::query()->orderBy('id')->each(function (Hub $hub) use ($matrix) {
            $roleCaps = $matrix->resolvedRoleCapabilities($hub);

            foreach (array_keys($roleCaps) as $role) {
                if (! is_array($roleCaps[$role] ?? null)) {
                    $roleCaps[$role] = [];
                }
                if (array_key_exists(self::KEY, $roleCaps[$role])) {
                    continue;
                }
                $roleCaps[$role][self::KEY] = $role === User::ROLE_TOP_MANAGEMENT;
            }

            $any = false;
            foreach ($roleCaps as $caps) {
                if (! empty($caps[self::KEY])) {
                    $any = true;
                    break;
                }
            }

            $checklist = $hub->resolvedChecklist();
            $checklist[self::KEY] = $any;

            $hub->forceFill([
                'role_capabilities' => $roleCaps,
                'checklist' => $checklist,
            ])->save();
        });

        $matrix->forgetResolvedCaches();
    }

    public function down(): void
    {
        Hub::query()->orderBy('id')->each(function (Hub $hub) {
            $roleCaps = is_array($hub->role_capabilities) ? $hub->role_capabilities : [];
            foreach ($roleCaps as $role => $caps) {
                if (! is_array($caps)) {
                    continue;
                }
                unset($caps[self::KEY]);
                $roleCaps[$role] = $caps;
            }

            $checklist = is_array($hub->checklist) ? $hub->checklist : [];
            unset($checklist[self::KEY]);

            $hub->forceFill([
                'role_capabilities' => $roleCaps,
                'checklist' => $checklist,
            ])->save();
        });

        app(CapabilitiesMatrixService::class)->forgetResolvedCaches();
    }
};
