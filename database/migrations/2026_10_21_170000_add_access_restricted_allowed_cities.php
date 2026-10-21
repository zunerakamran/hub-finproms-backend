<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Restricted / Allowed Countries/Cities — city lists beside country lists.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('security_monitoring_settings')) {
            Schema::table('security_monitoring_settings', function (Blueprint $table) {
                if (! Schema::hasColumn('security_monitoring_settings', 'access_restricted_cities')) {
                    $table->json('access_restricted_cities')->nullable()->after('access_restricted_countries');
                }
                if (! Schema::hasColumn('security_monitoring_settings', 'access_allowed_cities')) {
                    $table->json('access_allowed_cities')->nullable()->after('access_allowed_countries');
                }
            });
        }

        if (Schema::hasTable('user_access_restriction_settings')) {
            Schema::table('user_access_restriction_settings', function (Blueprint $table) {
                if (! Schema::hasColumn('user_access_restriction_settings', 'access_restricted_cities')) {
                    $table->json('access_restricted_cities')->nullable()->after('access_restricted_countries');
                }
                if (! Schema::hasColumn('user_access_restriction_settings', 'access_allowed_cities')) {
                    $table->json('access_allowed_cities')->nullable()->after('access_allowed_countries');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('security_monitoring_settings')) {
            Schema::table('security_monitoring_settings', function (Blueprint $table) {
                if (Schema::hasColumn('security_monitoring_settings', 'access_restricted_cities')) {
                    $table->dropColumn('access_restricted_cities');
                }
                if (Schema::hasColumn('security_monitoring_settings', 'access_allowed_cities')) {
                    $table->dropColumn('access_allowed_cities');
                }
            });
        }

        if (Schema::hasTable('user_access_restriction_settings')) {
            Schema::table('user_access_restriction_settings', function (Blueprint $table) {
                if (Schema::hasColumn('user_access_restriction_settings', 'access_restricted_cities')) {
                    $table->dropColumn('access_restricted_cities');
                }
                if (Schema::hasColumn('user_access_restriction_settings', 'access_allowed_cities')) {
                    $table->dropColumn('access_allowed_cities');
                }
            });
        }
    }
};
