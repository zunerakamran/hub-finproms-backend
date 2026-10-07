<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('firm_document_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });

        Schema::create('firm_document_folders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('parent_id')
                ->nullable()
                ->constrained('firm_document_folders')
                ->cascadeOnDelete();
            $table->string('name');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['firm_id', 'parent_id']);
        });

        Schema::table('firm_documents', function (Blueprint $table) {
            $table->foreignId('folder_id')
                ->nullable()
                ->after('firm_id')
                ->constrained('firm_document_folders')
                ->nullOnDelete();
            $table->foreignId('category_id')
                ->nullable()
                ->after('folder_id')
                ->constrained('firm_document_categories')
                ->nullOnDelete();
        });

        // Per-document access: firm_document_id + user rights.
        Schema::table('firm_document_member_rights', function (Blueprint $table) {
            $table->foreignId('firm_document_id')
                ->nullable()
                ->after('firm_id')
                ->constrained('firm_documents')
                ->cascadeOnDelete();
        });

        // Expand legacy firm-wide grants onto every existing document for that firm.
        if (Schema::hasTable('firm_document_member_rights') && Schema::hasTable('firm_documents')) {
            $legacy = DB::table('firm_document_member_rights')
                ->whereNull('firm_document_id')
                ->get();

            foreach ($legacy as $row) {
                $docIds = DB::table('firm_documents')
                    ->where('firm_id', $row->firm_id)
                    ->pluck('id');

                foreach ($docIds as $docId) {
                    $exists = DB::table('firm_document_member_rights')
                        ->where('firm_document_id', $docId)
                        ->where('user_id', $row->user_id)
                        ->exists();
                    if ($exists) {
                        continue;
                    }
                    DB::table('firm_document_member_rights')->insert([
                        'firm_id' => $row->firm_id,
                        'firm_document_id' => $docId,
                        'user_id' => $row->user_id,
                        'can_add' => (bool) $row->can_add,
                        'can_view' => (bool) $row->can_view,
                        'can_delete' => (bool) $row->can_delete,
                        'can_archive' => (bool) $row->can_archive,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                // Keep a firm-wide row only when can_add was granted (upload permission).
                if (! (bool) $row->can_add) {
                    DB::table('firm_document_member_rights')->where('id', $row->id)->delete();
                } else {
                    DB::table('firm_document_member_rights')->where('id', $row->id)->update([
                        'can_view' => false,
                        'can_delete' => false,
                        'can_archive' => false,
                        'updated_at' => now(),
                    ]);
                }
            }
        }

        Schema::table('firm_document_member_rights', function (Blueprint $table) {
            $table->dropUnique(['firm_id', 'user_id']);
            // Per-document uniqueness is enforced in the service (NULL firm_document_id =
            // firm-wide upload grant; MySQL unique indexes treat NULLs as distinct).
            $table->index(['firm_document_id', 'user_id'], 'firm_doc_member_rights_doc_user_idx');
            $table->index(['firm_id', 'user_id'], 'firm_doc_member_rights_firm_user_idx');
        });
    }

    public function down(): void
    {
        // Collapse back to one firm-wide row per user (best-effort).
        if (Schema::hasTable('firm_document_member_rights')) {
            $grouped = DB::table('firm_document_member_rights')
                ->select('firm_id', 'user_id')
                ->selectRaw('MAX(can_add) as can_add')
                ->selectRaw('MAX(can_view) as can_view')
                ->selectRaw('MAX(can_delete) as can_delete')
                ->selectRaw('MAX(can_archive) as can_archive')
                ->groupBy('firm_id', 'user_id')
                ->get();

            DB::table('firm_document_member_rights')->delete();

            foreach ($grouped as $row) {
                DB::table('firm_document_member_rights')->insert([
                    'firm_id' => $row->firm_id,
                    'firm_document_id' => null,
                    'user_id' => $row->user_id,
                    'can_add' => (bool) $row->can_add,
                    'can_view' => (bool) $row->can_view,
                    'can_delete' => (bool) $row->can_delete,
                    'can_archive' => (bool) $row->can_archive,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        Schema::table('firm_document_member_rights', function (Blueprint $table) {
            $table->dropIndex('firm_doc_member_rights_doc_user_idx');
            $table->dropIndex('firm_doc_member_rights_firm_user_idx');
            $table->dropConstrainedForeignId('firm_document_id');
            $table->unique(['firm_id', 'user_id']);
        });

        Schema::table('firm_documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('folder_id');
            $table->dropConstrainedForeignId('category_id');
        });

        Schema::dropIfExists('firm_document_folders');
        Schema::dropIfExists('firm_document_categories');
    }
};
