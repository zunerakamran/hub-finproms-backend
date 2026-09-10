<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hub_id')->nullable()->constrained('hubs')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('user_name')->nullable();
            $table->string('user_email')->nullable();
            $table->string('user_role', 64)->nullable();
            $table->string('action', 120);
            $table->text('description')->nullable();
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('method', 16)->nullable();
            $table->string('path', 512)->nullable();
            $table->string('route_name')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->json('properties')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['hub_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index(['action', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
