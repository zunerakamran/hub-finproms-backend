<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bundles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedInteger('credits_cost');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('buy_count')->default(0);
            $table->timestamps();
        });

        Schema::create('bundle_post', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bundle_id')->constrained('bundles')->cascadeOnDelete();
            $table->foreignId('post_id')->constrained('posts')->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['bundle_id', 'post_id']);
        });

        Schema::create('bundle_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('bundle_id')->constrained('bundles')->cascadeOnDelete();
            $table->unsignedInteger('credits_spent');
            $table->timestamp('purchased_at');
            $table->timestamps();

            $table->unique(['user_id', 'bundle_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bundle_purchases');
        Schema::dropIfExists('bundle_post');
        Schema::dropIfExists('bundles');
    }
};
