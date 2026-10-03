<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('wc_template_requests')) {
            return;
        }

        // Existing "deployed" sites were previously treated as live. Under the
        // staging → go-live flow they should start on staging instead.
        $query = DB::table('wc_template_requests')->where('status', 'deployed');

        if (Schema::hasColumn('wc_template_requests', 'staging_domain')) {
            $query->update([
                'status' => 'staging',
                'staging_domain' => DB::raw('COALESCE(NULLIF(staging_domain, ""), cpanel_domain)'),
            ]);
        } else {
            $query->update(['status' => 'staging']);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('wc_template_requests')) {
            return;
        }

        // Best-effort reverse: staging rows with no go-live request become deployed.
        DB::table('wc_template_requests')
            ->where('status', 'staging')
            ->when(
                Schema::hasColumn('wc_template_requests', 'go_live_requested_at'),
                fn ($q) => $q->whereNull('go_live_requested_at')
            )
            ->update(['status' => 'deployed']);
    }
};
