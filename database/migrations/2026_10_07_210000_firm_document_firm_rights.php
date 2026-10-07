<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Firm-level grants on Central / Network documents.
 * Rights on a grantee firm apply to every current member of that firm.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('firm_document_firm_rights')) {
            return;
        }

        Schema::create('firm_document_firm_rights', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_document_id')
                ->constrained('firm_documents')
                ->cascadeOnDelete();
            $table->foreignId('grantee_firm_id')
                ->constrained('firms')
                ->cascadeOnDelete();
            $table->boolean('can_add')->default(false);
            $table->boolean('can_view')->default(false);
            $table->boolean('can_delete')->default(false);
            $table->boolean('can_archive')->default(false);
            $table->timestamps();

            $table->unique(['firm_document_id', 'grantee_firm_id'], 'firm_doc_firm_rights_doc_firm_unique');
            $table->index(['grantee_firm_id', 'can_view'], 'firm_doc_firm_rights_grantee_view_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('firm_document_firm_rights');
    }
};
