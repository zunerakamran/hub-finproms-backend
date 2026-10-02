<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('firms', function (Blueprint $table) {
            $table->foreignId('head_user_id')
                ->nullable()
                ->after('compliance_visible_to_firm_id')
                ->constrained('users')
                ->nullOnDelete();
        });

        Schema::create('firm_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('archived_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['firm_id', 'archived_at']);
        });

        Schema::create('firm_document_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_document_id')->constrained('firm_documents')->cascadeOnDelete();
            $table->string('original_name');
            $table->string('file_path');
            $table->string('file_url')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('firm_document_member_rights', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->boolean('can_add')->default(false);
            $table->boolean('can_view')->default(false);
            $table->boolean('can_delete')->default(false);
            $table->boolean('can_archive')->default(false);
            $table->timestamps();

            $table->unique(['firm_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('firm_document_member_rights');
        Schema::dropIfExists('firm_document_attachments');
        Schema::dropIfExists('firm_documents');

        Schema::table('firms', function (Blueprint $table) {
            $table->dropConstrainedForeignId('head_user_id');
        });
    }
};
