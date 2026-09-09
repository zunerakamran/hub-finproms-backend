<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('advisor_pricing_tiers', function (Blueprint $table) {
            $table->id();
            $table->string('label')->nullable();
            $table->unsignedInteger('min_advisors')->default(1);
            $table->decimal('rate_per_advisor', 10, 2);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'min_advisors']);
        });

        Schema::create('hub_advisor_billings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hub_id')->constrained()->cascadeOnDelete();
            $table->foreignId('billed_user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('advisor_count');
            $table->decimal('rate_per_advisor', 10, 2);
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3)->default('gbp');
            $table->string('status')->default('pending'); // pending|paid|failed|canceled
            $table->string('payment_method')->nullable(); // stripe|bank_transfer
            $table->string('payment_status')->default('pending');
            $table->boolean('auto_renew')->default(false);
            $table->string('payment_reference')->nullable();
            $table->string('stripe_session_id')->nullable()->index();
            $table->string('stripe_subscription_id')->nullable()->index();
            $table->string('stripe_invoice_id')->nullable();
            $table->timestamp('period_starts_at')->nullable();
            $table->timestamp('period_ends_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['hub_id', 'status']);
            $table->index(['billed_user_id', 'status']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('hub_advisor_billing_id')
                ->nullable()
                ->after('post_purchase_id')
                ->constrained('hub_advisor_billings')
                ->nullOnDelete();
            $table->unique('hub_advisor_billing_id');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropUnique(['hub_advisor_billing_id']);
            $table->dropConstrainedForeignId('hub_advisor_billing_id');
        });

        Schema::dropIfExists('hub_advisor_billings');
        Schema::dropIfExists('advisor_pricing_tiers');
    }
};
