<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->boolean('show_reach')->default(true)->after('is_active');
            $table->boolean('show_views')->default(false)->after('show_reach');
            $table->boolean('show_buys')->default(false)->after('show_views');
        });

        // Seed defaults by plan tier (Power Admin can override later).
        DB::table('subscription_plans')->where('name', 'Basic')->update([
            'show_reach' => true,
            'show_views' => false,
            'show_buys' => false,
        ]);

        DB::table('subscription_plans')->where('name', 'Standard')->update([
            'show_reach' => true,
            'show_views' => true,
            'show_buys' => false,
        ]);

        DB::table('subscription_plans')->where('name', 'Premium')->update([
            'show_reach' => true,
            'show_views' => true,
            'show_buys' => true,
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->dropColumn(['show_reach', 'show_views', 'show_buys']);
        });
    }
};
