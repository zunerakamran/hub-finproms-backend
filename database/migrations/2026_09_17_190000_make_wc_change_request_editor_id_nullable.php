<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('wc_change_requests') || ! Schema::hasColumn('wc_change_requests', 'editor_id')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        // Shared Power Admin / FinProms are not tenant users — allow null editor_id.
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            try {
                DB::statement('ALTER TABLE wc_change_requests DROP FOREIGN KEY wc_change_requests_editor_id_foreign');
            } catch (\Throwable) {
                // Constraint name may differ.
            }
            DB::statement('ALTER TABLE wc_change_requests MODIFY editor_id BIGINT UNSIGNED NULL');
            try {
                DB::statement('ALTER TABLE wc_change_requests ADD CONSTRAINT wc_change_requests_editor_id_foreign FOREIGN KEY (editor_id) REFERENCES users(id) ON DELETE SET NULL');
            } catch (\Throwable) {
                // Ignore if already present / unsupported.
            }
        }
    }

    public function down(): void
    {
        // No safe reverse without fabricating editors for null rows.
    }
};
