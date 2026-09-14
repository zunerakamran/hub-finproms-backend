<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Prefer the Social Media Compliance table names. If an older install already
        // created compliance_requests, the later rename migration handles it.
        if (Schema::hasTable('compliance_requests') || Schema::hasTable('social_media_compliance_requests')) {
            return;
        }

        Schema::create('social_media_compliance_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('post_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->unsignedInteger('current_version')->default(1);
            $table->timestamp('submission_date')->useCurrent();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_date')->nullable();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'submission_date']);
            $table->index(['assigned_to', 'submission_date']);
            $table->index('post_id');
        });

        Schema::create('social_media_compliance_request_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('social_media_compliance_requests')->cascadeOnDelete();
            $table->unsignedTinyInteger('version_number')->default(1);
            $table->text('description')->nullable();
            $table->string('image_path', 500)->nullable();
            $table->string('image_url', 500)->nullable();
            $table->foreignId('submitted_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('submitted_at')->useCurrent();
            $table->string('status', 50)->nullable();
            $table->text('feedback')->nullable();
            $table->string('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['request_id', 'version_number']);
            $table->index(['request_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_media_compliance_request_versions');
        Schema::dropIfExists('social_media_compliance_requests');
        Schema::dropIfExists('compliance_request_versions');
        Schema::dropIfExists('compliance_requests');
    }
};
