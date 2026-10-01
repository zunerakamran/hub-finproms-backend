<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('social_media_compliance_request_attachments')) {
            Schema::create('social_media_compliance_request_attachments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('version_id')
                    ->constrained('social_media_compliance_request_versions')
                    ->cascadeOnDelete();
                $table->string('original_name');
                $table->string('file_path', 500);
                $table->string('file_url', 500)->nullable();
                $table->string('mime_type', 120)->nullable();
                $table->unsignedBigInteger('size_bytes')->default(0);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();

                $table->index(['version_id', 'sort_order'], 'smc_attachments_version_sort_index');
            });
        }

        if (! Schema::hasTable('wc_change_request_attachments')) {
            Schema::create('wc_change_request_attachments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('version_id')
                    ->constrained('wc_change_request_versions')
                    ->cascadeOnDelete();
                $table->string('original_name');
                $table->string('file_path', 500);
                $table->string('file_url', 500)->nullable();
                $table->string('mime_type', 120)->nullable();
                $table->unsignedBigInteger('size_bytes')->default(0);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();

                $table->index(['version_id', 'sort_order'], 'wc_attachments_version_sort_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('wc_change_request_attachments');
        Schema::dropIfExists('social_media_compliance_request_attachments');
    }
};
