<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->text('overview')->nullable()->after('description');
            $table->json('features')->nullable()->after('overview');
            $table->json('benefits')->nullable()->after('features');
            $table->timestamp('last_updated')->nullable()->after('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->dropColumn(['overview', 'features', 'benefits', 'last_updated']);
        });
    }
};
