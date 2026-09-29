<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('wc_change_request_versions') || ! Schema::hasColumn('wc_change_request_versions', 'submitted_by')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        // Shared Power Admin / FinProms are not tenant users — allow null submitted_by.
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            try {
                DB::statement('ALTER TABLE wc_change_request_versions DROP FOREIGN KEY wc_change_request_versions_submitted_by_foreign');
            } catch (\Throwable) {
                // Constraint name may differ.
            }
            DB::statement('ALTER TABLE wc_change_request_versions MODIFY submitted_by BIGINT UNSIGNED NULL');
            try {
                DB::statement('ALTER TABLE wc_change_request_versions ADD CONSTRAINT wc_change_request_versions_submitted_by_foreign FOREIGN KEY (submitted_by) REFERENCES users(id) ON DELETE SET NULL');
            } catch (\Throwable) {
                // Ignore if already present / unsupported.
            }
        }
    }

    public function down(): void
    {
        // No safe reverse without fabricating submitters for null rows.
    }
};
