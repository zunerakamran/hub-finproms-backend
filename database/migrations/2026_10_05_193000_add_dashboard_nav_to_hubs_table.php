<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hubs', function (Blueprint $table) {
            if (! Schema::hasColumn('hubs', 'dashboard_nav')) {
                $after = Schema::hasColumn('hubs', 'page_content')
                    ? 'page_content'
                    : 'terms_and_conditions';
                $table->json('dashboard_nav')->nullable()->after($after);
            }
        });
    }

    public function down(): void
    {
        Schema::table('hubs', function (Blueprint $table) {
            if (Schema::hasColumn('hubs', 'dashboard_nav')) {
                $table->dropColumn('dashboard_nav');
            }
        });
    }
};
