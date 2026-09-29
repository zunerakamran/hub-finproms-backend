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
            if (! Schema::hasColumn('wc_template_requests', 'favicon_url')) {
                $table->string('favicon_url', 1000)->nullable()->after('logo_url');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('wc_template_requests')) {
            return;
        }

        Schema::table('wc_template_requests', function (Blueprint $table) {
            if (Schema::hasColumn('wc_template_requests', 'favicon_url')) {
                $table->dropColumn('favicon_url');
            }
        });
    }
};
