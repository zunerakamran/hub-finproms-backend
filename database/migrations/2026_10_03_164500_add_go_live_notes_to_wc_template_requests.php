<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('wc_template_requests')) {
            return;
        }

        Schema::table('wc_template_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('wc_template_requests', 'go_live_notes')) {
                $table->text('go_live_notes')->nullable()->after('go_live_requested_by_id');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('wc_template_requests')) {
            return;
        }

        Schema::table('wc_template_requests', function (Blueprint $table) {
            if (Schema::hasColumn('wc_template_requests', 'go_live_notes')) {
                $table->dropColumn('go_live_notes');
            }
        });
    }
};
