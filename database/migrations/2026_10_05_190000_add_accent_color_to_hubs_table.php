<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hubs', function (Blueprint $table) {
            if (! Schema::hasColumn('hubs', 'accent_color')) {
                $table->string('accent_color', 32)->nullable()->after('secondary_color');
            }
        });
    }

    public function down(): void
    {
        Schema::table('hubs', function (Blueprint $table) {
            if (Schema::hasColumn('hubs', 'accent_color')) {
                $table->dropColumn('accent_color');
            }
        });
    }
};
