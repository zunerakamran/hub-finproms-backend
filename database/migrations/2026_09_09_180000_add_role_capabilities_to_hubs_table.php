<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hubs', function (Blueprint $table) {
            $table->json('role_capabilities')->nullable()->after('checklist');
        });
    }

    public function down(): void
    {
        Schema::table('hubs', function (Blueprint $table) {
            $table->dropColumn('role_capabilities');
        });
    }
};
