<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_checkouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('item_type', 32); // post|bundle
            $table->unsignedBigInteger('item_id');
            $table->unsignedInteger('credits_cost');
            $table->decimal('amount', 10, 2);
            $table->string('payment_method', 32);
            $table->string('payment_status', 32)->default('pending');
            $table->string('stripe_session_id')->nullable()->unique();
            $table->string('payment_reference')->nullable()->unique();
            $table->string('stripe_payment_intent')->nullable();
            $table->foreignId('post_purchase_id')->nullable()->constrained('post_purchases')->nullOnDelete();
            $table->foreignId('bundle_purchase_id')->nullable()->constrained('bundle_purchases')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'item_type', 'item_id', 'payment_status']);
            $table->index(['payment_method', 'payment_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_checkouts');
    }
};
