<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Access control enforcement: monitor (log only) vs enforce (block).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('security_monitoring_settings')
            && ! Schema::hasColumn('security_monitoring_settings', 'access_enforcement_mode')
        ) {
            Schema::table('security_monitoring_settings', function (Blueprint $table) {
                $table->string('access_enforcement_mode', 20)->default('enforce')->after('access_allow_vpn_enabled');
            });
        }

        if (Schema::hasTable('user_access_restriction_settings')
            && ! Schema::hasColumn('user_access_restriction_settings', 'access_enforcement_mode')
        ) {
            Schema::table('user_access_restriction_settings', function (Blueprint $table) {
                $table->string('access_enforcement_mode', 20)->default('enforce')->after('access_allow_vpn_enabled');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('security_monitoring_settings')
            && Schema::hasColumn('security_monitoring_settings', 'access_enforcement_mode')
        ) {
            Schema::table('security_monitoring_settings', function (Blueprint $table) {
                $table->dropColumn('access_enforcement_mode');
            });
        }

        if (Schema::hasTable('user_access_restriction_settings')
            && Schema::hasColumn('user_access_restriction_settings', 'access_enforcement_mode')
        ) {
            Schema::table('user_access_restriction_settings', function (Blueprint $table) {
                $table->dropColumn('access_enforcement_mode');
            });
        }
    }
};
