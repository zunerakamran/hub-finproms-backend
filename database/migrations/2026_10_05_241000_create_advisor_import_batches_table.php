<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('advisor_import_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hub_id')->constrained('hubs')->cascadeOnDelete();
            $table->foreignId('imported_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('imported_by_name')->nullable();
            $table->string('imported_by_email')->nullable();
            $table->string('original_filename')->nullable();
            $table->string('status', 32)->default('completed');
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('reactivated_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->json('created_users')->nullable();
            $table->json('updated_users')->nullable();
            $table->json('reactivated_users')->nullable();
            $table->json('skipped_rows')->nullable();
            $table->text('message')->nullable();
            $table->timestamps();

            $table->index(['hub_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('advisor_import_batches');
    }
};
