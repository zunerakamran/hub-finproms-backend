<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_advisor')->default(false)->after('credits');
            $table->boolean('has_unlimited_credits')->default(false)->after('is_advisor');
        });

        Schema::table('user_subscriptions', function (Blueprint $table) {
            $table->dropForeign(['subscription_plan_id']);
        });

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE user_subscriptions MODIFY subscription_plan_id BIGINT UNSIGNED NULL');
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE user_subscriptions ALTER COLUMN subscription_plan_id DROP NOT NULL');
        } elseif ($driver === 'sqlite') {
            // SQLite cannot easily MODIFY; recreate is heavy — skip if already flexible in tests.
        }

        Schema::table('user_subscriptions', function (Blueprint $table) {
            $table->foreign('subscription_plan_id')
                ->references('id')
                ->on('subscription_plans')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('user_subscriptions', function (Blueprint $table) {
            $table->dropForeign(['subscription_plan_id']);
        });

        DB::table('user_subscriptions')->whereNull('subscription_plan_id')->delete();

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE user_subscriptions MODIFY subscription_plan_id BIGINT UNSIGNED NOT NULL');
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE user_subscriptions ALTER COLUMN subscription_plan_id SET NOT NULL');
        }

        Schema::table('user_subscriptions', function (Blueprint $table) {
            $table->foreign('subscription_plan_id')
                ->references('id')
                ->on('subscription_plans')
                ->cascadeOnDelete();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_advisor', 'has_unlimited_credits']);
        });
    }
};
