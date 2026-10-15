<?php

use App\Models\Hub;
use App\Models\User;
use App\Services\CapabilitiesMatrixService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hub-managed application access restrictions (geo / IP).
 *
 * Capable admins choose a mode: UK only, specific UK cities, allow/restrict
 * countries, or allow/restrict IPs. Gated by Security Monitoring + secmon_manage_access.
 */
return new class extends Migration
{
    private const KEY = 'secmon_manage_access';

    public function up(): void
    {
        if (Schema::hasTable('security_monitoring_settings')) {
            Schema::table('security_monitoring_settings', function (Blueprint $table) {
                if (! Schema::hasColumn('security_monitoring_settings', 'access_restriction_mode')) {
                    $table->string('access_restriction_mode', 40)->default('off')->after('failed_login_window_minutes');
                }
                if (! Schema::hasColumn('security_monitoring_settings', 'access_restriction_countries')) {
                    $table->json('access_restriction_countries')->nullable()->after('access_restriction_mode');
                }
                if (! Schema::hasColumn('security_monitoring_settings', 'access_restriction_cities')) {
                    $table->json('access_restriction_cities')->nullable()->after('access_restriction_countries');
                }
                if (! Schema::hasColumn('security_monitoring_settings', 'access_restriction_ips')) {
                    $table->json('access_restriction_ips')->nullable()->after('access_restriction_cities');
                }
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
                    $caps = [];
                }
                if (array_key_exists($key, $roleCaps[$role])) {
                    continue;
                }

                $isHubAdmin = in_array($role, [
                    User::ROLE_POWER_ADMIN,
                    User::ROLE_FINPROMS_ADMIN,
                    User::ROLE_CLIENT_ADMIN,
                    User::ROLE_MANAGER,
                ], true);

                $roleCaps[$role][$key] = $isHubAdmin
                    || (bool) ($defaults[$role][$key] ?? false)
                    || ! empty($caps['secmon_manage_alerts']);
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
    }

    public function down(): void
    {
        if (Schema::hasTable('security_monitoring_settings')) {
            Schema::table('security_monitoring_settings', function (Blueprint $table) {
                foreach ([
                    'access_restriction_ips',
                    'access_restriction_cities',
                    'access_restriction_countries',
                    'access_restriction_mode',
                ] as $column) {
                    if (Schema::hasColumn('security_monitoring_settings', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        $key = self::KEY;

        Hub::query()->orderBy('id')->each(function (Hub $hub) use ($key) {
            $roleCaps = is_array($hub->role_capabilities) ? $hub->role_capabilities : [];
            foreach ($roleCaps as $role => $caps) {
                if (is_array($caps)) {
                    unset($roleCaps[$role][$key]);
                }
            }
            $checklist = is_array($hub->checklist) ? $hub->checklist : [];
            unset($checklist[$key]);
            $hub->role_capabilities = $roleCaps;
            $hub->checklist = $checklist;
            $hub->save();
        });
    }
};
