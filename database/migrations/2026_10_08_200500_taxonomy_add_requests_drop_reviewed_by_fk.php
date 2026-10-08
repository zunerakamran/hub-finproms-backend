<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * reviewed_by may be a Central Power Admin while taxonomy_add_requests lives on
 * a remounted Shared / WL database — an FK to local users breaks approve/reject.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('taxonomy_add_requests')) {
            return;
        }

        Schema::table('taxonomy_add_requests', function (Blueprint $table) {
            try {
                $table->dropForeign(['reviewed_by']);
            } catch (\Throwable) {
                // Already dropped or named differently on some hubs.
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('taxonomy_add_requests')) {
            return;
        }

        Schema::table('taxonomy_add_requests', function (Blueprint $table) {
            try {
                $table->foreign('reviewed_by')
                    ->references('id')
                    ->on('users')
                    ->nullOnDelete();
            } catch (\Throwable) {
                //
            }
        });
    }
};
