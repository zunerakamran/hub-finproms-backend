<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hubs', function (Blueprint $table) {
            if (! Schema::hasColumn('hubs', 'white_logo_url')) {
                $table->string('white_logo_url')->nullable()->after('logo_url');
            }
        });
    }

    public function down(): void
    {
        Schema::table('hubs', function (Blueprint $table) {
            if (Schema::hasColumn('hubs', 'white_logo_url')) {
                $table->dropColumn('white_logo_url');
            }
        });
    }
};
