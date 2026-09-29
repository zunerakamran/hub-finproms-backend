<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_number')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type'); // subscription | post_purchase
            $table->foreignId('user_subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('post_purchase_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description');
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3)->default('gbp');
            $table->unsignedInteger('credits')->nullable();
            $table->string('status')->default('paid');
            $table->string('billing_name');
            $table->string('billing_email');
            $table->json('line_items')->nullable();
            $table->timestamp('issued_at');
            $table->timestamps();

            $table->unique('user_subscription_id');
            $table->unique('post_purchase_id');
            $table->index(['user_id', 'issued_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
