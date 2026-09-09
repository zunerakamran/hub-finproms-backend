<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('stripe_customer_id')->nullable()->after('has_unlimited_credits');
            $table->string('stripe_payment_method_id')->nullable()->after('stripe_customer_id');
            $table->index('stripe_customer_id');
        });

        Schema::table('hubs', function (Blueprint $table) {
            $table->unsignedTinyInteger('advisor_billing_renew_day')->default(1)->after('role_capabilities');
            $table->string('advisor_stripe_subscription_id')->nullable()->after('advisor_billing_renew_day');
            $table->index('advisor_stripe_subscription_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['stripe_customer_id']);
            $table->dropColumn(['stripe_customer_id', 'stripe_payment_method_id']);
        });

        Schema::table('hubs', function (Blueprint $table) {
            $table->dropIndex(['advisor_stripe_subscription_id']);
            $table->dropColumn(['advisor_billing_renew_day', 'advisor_stripe_subscription_id']);
        });
    }
};
