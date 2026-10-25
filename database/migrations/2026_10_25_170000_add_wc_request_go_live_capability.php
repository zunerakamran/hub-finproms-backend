<?php

use App\Models\Hub;
use App\Models\User;
use App\Services\CapabilitiesMatrixService;
use Illuminate\Database\Migrations\Migration;

/**
 * Add wc_request_go_live — original requesters (Network/Firm Admin, advisors,
 * managers by default) can ask Power Admin to promote a staging site to live.
 */
return new class extends Migration
{
    private const KEY = 'wc_request_go_live';

    /** @var list<string> */
    private const DEFAULT_ON_ROLES = [
        User::ROLE_POWER_ADMIN,
        User::ROLE_NETWORK_ADMIN,
        User::ROLE_FIRM_ADMIN,
        User::ROLE_MANAGER,
        User::ROLE_ADVISOR,
    ];

    public function up(): void
    {
        $matrix = app(CapabilitiesMatrixService::class);

        Hub::query()->orderBy('id')->each(function (Hub $hub) use ($matrix) {
            $roleCaps = $matrix->resolvedRoleCapabilities($hub);
            $defaults = $matrix->defaultRoleCapabilities($hub->type);

            foreach (array_keys($roleCaps) as $role) {
                if (! is_array($roleCaps[$role] ?? null)) {
                    $roleCaps[$role] = [];
                }
                if (array_key_exists(self::KEY, $roleCaps[$role])) {
                    continue;
                }
                $roleCaps[$role][self::KEY] = (bool) ($defaults[$role][self::KEY] ?? false)
                    || in_array($role, self::DEFAULT_ON_ROLES, true);
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
    }

    public function down(): void
    {
        Hub::query()->orderBy('id')->each(function (Hub $hub) {
            $roleCaps = is_array($hub->role_capabilities) ? $hub->role_capabilities : [];
            foreach ($roleCaps as $role => $caps) {
                if (is_array($caps)) {
                    unset($roleCaps[$role][self::KEY]);
                }
            }
            $checklist = is_array($hub->checklist) ? $hub->checklist : [];
            unset($checklist[self::KEY]);
            $hub->forceFill([
                'role_capabilities' => $roleCaps,
                'checklist' => $checklist,
            ])->save();
        });
    }
};
