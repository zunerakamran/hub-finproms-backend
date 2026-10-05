<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hub_backups')) {
            return;
        }

        Schema::create('hub_backups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hub_id')->nullable()->constrained('hubs')->nullOnDelete();
            $table->string('hub_slug', 255);
            $table->string('location', 16)->default('local'); // local|central
            $table->string('triggered_by', 32)->default('schedule'); // schedule|manual|receive
            $table->string('status', 16)->default('pending'); // pending|running|completed|failed
            $table->string('filename', 255)->nullable();
            $table->string('disk_path', 1024)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('checksum', 64)->nullable();
            $table->boolean('includes_database')->default(true);
            $table->boolean('includes_files')->default(true);
            $table->text('error_message')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['hub_slug', 'location', 'status']);
            $table->index(['hub_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hub_backups');
    }
};
