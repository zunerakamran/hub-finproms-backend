<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hubs', function (Blueprint $table) {
            // Idle session lifetime in minutes for this hub (SPA cookie + Sanctum PAT).
            // null = fall back to config('session.lifetime') / SESSION_LIFETIME.
            $table->unsignedInteger('session_lifetime_minutes')->nullable()->after('from_email');
        });
    }

    public function down(): void
    {
        Schema::table('hubs', function (Blueprint $table) {
            $table->dropColumn('session_lifetime_minutes');
        });
    }
};
