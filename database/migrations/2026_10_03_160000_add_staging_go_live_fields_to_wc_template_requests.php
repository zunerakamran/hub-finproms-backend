<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('wc_template_requests')) {
            return;
        }

        Schema::table('wc_template_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('wc_template_requests', 'staging_domain')) {
                $table->string('staging_domain')->nullable()->after('domain_name');
            }
            if (! Schema::hasColumn('wc_template_requests', 'go_live_requested_at')) {
                $table->timestamp('go_live_requested_at')->nullable()->after('status');
            }
            if (! Schema::hasColumn('wc_template_requests', 'go_live_requested_by_id')) {
                $table->unsignedBigInteger('go_live_requested_by_id')->nullable()->after('go_live_requested_at');
            }
            if (! Schema::hasColumn('wc_template_requests', 'live_promoted_at')) {
                $table->timestamp('live_promoted_at')->nullable()->after('go_live_requested_by_id');
            }
        });

        // Existing live sites used status=deployed; keep that as live-equivalent in code,
        // and backfill staging_domain from cpanel_domain where useful for history.
        DB::table('wc_template_requests')
            ->where('status', 'deployed')
            ->whereNotNull('cpanel_domain')
            ->where(function ($q) {
                $q->whereNull('staging_domain')->orWhere('staging_domain', '');
            })
            ->update([
                'staging_domain' => DB::raw('cpanel_domain'),
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('wc_template_requests')) {
            return;
        }

        Schema::table('wc_template_requests', function (Blueprint $table) {
            foreach (['live_promoted_at', 'go_live_requested_by_id', 'go_live_requested_at', 'staging_domain'] as $col) {
                if (Schema::hasColumn('wc_template_requests', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
