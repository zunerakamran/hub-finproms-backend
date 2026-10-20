<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hub access restrictions: Restrict VPN / Allow VPN toggles.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('security_monitoring_settings')) {
            return;
        }

        Schema::table('security_monitoring_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('security_monitoring_settings', 'access_restrict_vpn_enabled')) {
                $table->boolean('access_restrict_vpn_enabled')->default(false)->after('access_allowed_ips');
            }
            if (! Schema::hasColumn('security_monitoring_settings', 'access_allow_vpn_enabled')) {
                $table->boolean('access_allow_vpn_enabled')->default(false)->after('access_restrict_vpn_enabled');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('security_monitoring_settings')) {
            return;
        }

        Schema::table('security_monitoring_settings', function (Blueprint $table) {
            foreach (['access_restrict_vpn_enabled', 'access_allow_vpn_enabled'] as $column) {
                if (Schema::hasColumn('security_monitoring_settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
