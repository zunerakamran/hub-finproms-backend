<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Laravel Crypt ciphertext often exceeds VARCHAR(255), which truncates the MAC
 * and makes secrets unreadable. Also holds Central→remote portable envelopes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('wc_template_requests')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();
        if ($driver !== 'mysql' && $driver !== 'mariadb') {
            return;
        }

        if (Schema::hasColumn('wc_template_requests', 'cpanel_api_key')) {
            DB::statement('ALTER TABLE wc_template_requests MODIFY cpanel_api_key TEXT NULL');
        }
        if (Schema::hasColumn('wc_template_requests', 'cpanel_db_password')) {
            DB::statement('ALTER TABLE wc_template_requests MODIFY cpanel_db_password TEXT NULL');
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('wc_template_requests')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();
        if ($driver !== 'mysql' && $driver !== 'mariadb') {
            return;
        }

        if (Schema::hasColumn('wc_template_requests', 'cpanel_api_key')) {
            DB::statement('ALTER TABLE wc_template_requests MODIFY cpanel_api_key VARCHAR(255) NULL');
        }
        if (Schema::hasColumn('wc_template_requests', 'cpanel_db_password')) {
            DB::statement('ALTER TABLE wc_template_requests MODIFY cpanel_db_password VARCHAR(255) NULL');
        }
    }
};
