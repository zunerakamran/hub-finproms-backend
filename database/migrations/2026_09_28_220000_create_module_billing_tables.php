<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('module_pricing', function (Blueprint $table) {
            $table->id();
            $table->string('module_key')->unique();
            $table->decimal('amount', 10, 2)->default(0);
            $table->string('currency', 3)->default('gbp');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('hub_module_billings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hub_id')->constrained()->cascadeOnDelete();
            $table->string('module_key');
            $table->foreignId('billed_user_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3)->default('gbp');
            $table->string('status')->default('paid'); // paid|canceled
            $table->string('payment_status')->default('paid');
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['hub_id', 'module_key']);
            $table->index(['hub_id', 'status']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('hub_module_billing_id')
                ->nullable()
                ->after('hub_advisor_billing_id')
                ->constrained('hub_module_billings')
                ->nullOnDelete();
            $table->unique('hub_module_billing_id');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropUnique(['hub_module_billing_id']);
            $table->dropConstrainedForeignId('hub_module_billing_id');
        });

        Schema::dropIfExists('hub_module_billings');
        Schema::dropIfExists('module_pricing');
    }
};
