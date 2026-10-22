<?php

use App\Models\Hub;
use App\Models\User;
use App\Services\CapabilitiesMatrixService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user capability overrides (§2.7.3):
 * - users.capability_overrides JSON denylist (flag => false) within role UNION
 * - dashboard_manage_user_capabilities unlocks the User capabilities page
 */
return new class extends Migration
{
    private const KEY = 'dashboard_manage_user_capabilities';

    public function up(): void
    {
        if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'capability_overrides')) {
            Schema::table('users', function (Blueprint $table) {
                $table->json('capability_overrides')->nullable()->after('modules');
            });
        }

        $matrix = app(CapabilitiesMatrixService::class);
        $key = self::KEY;

        Hub::query()->orderBy('id')->each(function (Hub $hub) use ($matrix, $key) {
            $roleCaps = $matrix->resolvedRoleCapabilities($hub);
            $defaults = $matrix->defaultRoleCapabilities($hub->type);

            foreach ($roleCaps as $role => $caps) {
                if (! is_array($caps)) {
                    $roleCaps[$role] = [];
                }
                if (! array_key_exists($key, $roleCaps[$role])) {
                    // Seed ON for hub-admin style roles; otherwise follow typed defaults.
                    $seed = in_array($role, [
                        User::ROLE_POWER_ADMIN,
                        User::ROLE_FINPROMS_ADMIN,
                        User::ROLE_CLIENT_ADMIN,
                        User::ROLE_MANAGER,
                    ], true)
                        ? true
                        : (bool) ($defaults[$role][$key] ?? false);
                    $roleCaps[$role][$key] = $seed;
                }
            }

            $hub->role_capabilities = $roleCaps;

            $checklist = $hub->resolvedChecklist();
            $any = false;
            foreach ($roleCaps as $caps) {
                if (! empty($caps[$key])) {
                    $any = true;
                    break;
                }
            }
            $checklist[$key] = $any;
            $hub->checklist = $checklist;
            $hub->save();
        });

        $matrix->forgetResolvedCaches();
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
            $hub->role_capabilities = $roleCaps;
            $hub->checklist = $checklist;
            $hub->save();
        });

        if (Schema::hasTable('users') && Schema::hasColumn('users', 'capability_overrides')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('capability_overrides');
            });
        }
    }
};
