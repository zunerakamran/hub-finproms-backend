<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Allowlist of firms that may appear in Central / Network document access rights
 * and receive firm-level grants. Managed via firm_documents_manage_firm_access
 * (or Head of Central / Network).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('firm_document_visible_firms')) {
            return;
        }

        Schema::create('firm_document_visible_firms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_firm_id')
                ->constrained('firms')
                ->cascadeOnDelete();
            $table->foreignId('grantee_firm_id')
                ->constrained('firms')
                ->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['owner_firm_id', 'grantee_firm_id'], 'firm_doc_visible_owner_grantee_unique');
            $table->index(['grantee_firm_id'], 'firm_doc_visible_grantee_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('firm_document_visible_firms');
    }
};
