<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Split WC "approved" (decision only) from "published" (content live).
 * Existing approved rows were already live — remap them to published.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('wc_change_requests')) {
            DB::table('wc_change_requests')
                ->where('status', 'approved')
                ->update(['status' => 'published']);
        }

        if (Schema::hasTable('wc_change_request_versions')) {
            DB::table('wc_change_request_versions')
                ->where('status', 'approved')
                ->update(['status' => 'published']);
        }

        if (
            Schema::hasTable('wc_platform_reports')
            && ! Schema::hasColumn('wc_platform_reports', 'change_requests_published')
        ) {
            Schema::table('wc_platform_reports', function (Blueprint $table) {
                $table->unsignedInteger('change_requests_published')->default(0)
                    ->after('change_requests_approved');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('wc_change_requests')) {
            DB::table('wc_change_requests')
                ->where('status', 'published')
                ->update(['status' => 'approved']);
        }

        if (Schema::hasTable('wc_change_request_versions')) {
            DB::table('wc_change_request_versions')
                ->where('status', 'published')
                ->update(['status' => 'approved']);
        }

        if (
            Schema::hasTable('wc_platform_reports')
            && Schema::hasColumn('wc_platform_reports', 'change_requests_published')
        ) {
            Schema::table('wc_platform_reports', function (Blueprint $table) {
                $table->dropColumn('change_requests_published');
            });
        }
    }
};
