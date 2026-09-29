<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hubs', function (Blueprint $table) {
            if (! Schema::hasColumn('hubs', 'auth_bg_image_url')) {
                $table->string('auth_bg_image_url')->nullable()->after('favicon_url');
            }
        });
    }

    public function down(): void
    {
        Schema::table('hubs', function (Blueprint $table) {
            if (Schema::hasColumn('hubs', 'auth_bg_image_url')) {
                $table->dropColumn('auth_bg_image_url');
            }
        });
    }
};
