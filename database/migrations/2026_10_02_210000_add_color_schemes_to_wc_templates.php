<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('wc_templates')) {
            return;
        }

        if (! Schema::hasColumn('wc_templates', 'color_schemes')) {
            Schema::table('wc_templates', function (Blueprint $table) {
                $table->json('color_schemes')->nullable()->after('dummy_content');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('wc_templates')) {
            return;
        }

        if (Schema::hasColumn('wc_templates', 'color_schemes')) {
            Schema::table('wc_templates', function (Blueprint $table) {
                $table->dropColumn('color_schemes');
            });
        }
    }
};
