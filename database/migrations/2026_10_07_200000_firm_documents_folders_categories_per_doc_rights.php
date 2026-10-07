<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('firm_document_categories')) {
            Schema::create('firm_document_categories', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('slug')->unique();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('firm_document_folders')) {
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
        }

        if (Schema::hasTable('firm_documents') && ! Schema::hasColumn('firm_documents', 'folder_id')) {
            Schema::table('firm_documents', function (Blueprint $table) {
                $table->foreignId('folder_id')
                    ->nullable()
                    ->after('firm_id')
                    ->constrained('firm_document_folders')
                    ->nullOnDelete();
            });
        }

        if (Schema::hasTable('firm_documents') && ! Schema::hasColumn('firm_documents', 'category_id')) {
            Schema::table('firm_documents', function (Blueprint $table) {
                $table->foreignId('category_id')
                    ->nullable()
                    ->after('folder_id')
                    ->constrained('firm_document_categories')
                    ->nullOnDelete();
            });
        }

        $addedDocumentId = false;
        if (Schema::hasTable('firm_document_member_rights')
            && ! Schema::hasColumn('firm_document_member_rights', 'firm_document_id')) {
            Schema::table('firm_document_member_rights', function (Blueprint $table) {
                $table->foreignId('firm_document_id')
                    ->nullable()
                    ->after('firm_id')
                    ->constrained('firm_documents')
                    ->cascadeOnDelete();
            });
            $addedDocumentId = true;
        }

        // Must drop (firm_id, user_id) unique BEFORE inserting per-document rows,
        // otherwise MySQL rejects a second row for the same firm+user.
        $this->replaceFirmUserUniqueWithNonUniqueIndexes();

        // Expand legacy firm-wide grants onto every existing document for that firm.
        if (Schema::hasTable('firm_document_member_rights')
            && Schema::hasTable('firm_documents')
            && Schema::hasColumn('firm_document_member_rights', 'firm_document_id')) {
            $legacyQuery = DB::table('firm_document_member_rights')
                ->whereNull('firm_document_id');

            if (! $addedDocumentId) {
                // Re-run / resume: only expand rows that still carry view/delete/archive.
                $legacyQuery->where(function ($q) {
                    $q->where('can_view', true)
                        ->orWhere('can_delete', true)
                        ->orWhere('can_archive', true);
                });
            }

            $legacy = $legacyQuery->get();

            foreach ($legacy as $row) {
                $docIds = DB::table('firm_documents')
                    ->where('firm_id', $row->firm_id)
                    ->pluck('id');

                foreach ($docIds as $docId) {
                    $exists = DB::table('firm_document_member_rights')
                        ->where('firm_id', $row->firm_id)
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
    }

    /**
     * MySQL will not drop firm_document_member_rights_firm_id_user_id_unique while a
     * foreign key still depends on it. Add dedicated firm_id / user_id indexes first,
     * then drop the unique so per-document rows can be inserted.
     */
    private function replaceFirmUserUniqueWithNonUniqueIndexes(): void
    {
        if (! Schema::hasTable('firm_document_member_rights')) {
            return;
        }

        $indexes = collect(DB::select('SHOW INDEX FROM firm_document_member_rights'));
        $indexNames = $indexes->pluck('Key_name')->unique()->values()->all();
        $uniqueName = 'firm_document_member_rights_firm_id_user_id_unique';

        $hasNonUniqueFirmIdLeadingIndex = $indexes->contains(
            fn ($idx) => $idx->Column_name === 'firm_id'
                && (int) $idx->Seq_in_index === 1
                && $idx->Key_name !== $uniqueName
        );
        $hasUserIdLeadingIndex = $indexes->contains(
            fn ($idx) => $idx->Column_name === 'user_id' && (int) $idx->Seq_in_index === 1
        );

        if (! $hasNonUniqueFirmIdLeadingIndex && ! in_array('firm_doc_member_rights_firm_id_idx', $indexNames, true)) {
            Schema::table('firm_document_member_rights', function (Blueprint $table) {
                $table->index('firm_id', 'firm_doc_member_rights_firm_id_idx');
            });
        }

        if (! $hasUserIdLeadingIndex && ! in_array('firm_doc_member_rights_user_id_idx', $indexNames, true)) {
            Schema::table('firm_document_member_rights', function (Blueprint $table) {
                $table->index('user_id', 'firm_doc_member_rights_user_id_idx');
            });
        }

        $indexNames = collect(DB::select('SHOW INDEX FROM firm_document_member_rights'))
            ->pluck('Key_name')
            ->unique()
            ->values()
            ->all();

        if (in_array($uniqueName, $indexNames, true)) {
            Schema::table('firm_document_member_rights', function (Blueprint $table) use ($uniqueName) {
                $table->dropUnique($uniqueName);
            });
        }

        $indexNames = collect(DB::select('SHOW INDEX FROM firm_document_member_rights'))
            ->pluck('Key_name')
            ->unique()
            ->values()
            ->all();

        if (! in_array('firm_doc_member_rights_doc_user_idx', $indexNames, true)
            && Schema::hasColumn('firm_document_member_rights', 'firm_document_id')) {
            Schema::table('firm_document_member_rights', function (Blueprint $table) {
                $table->index(['firm_document_id', 'user_id'], 'firm_doc_member_rights_doc_user_idx');
            });
        }

        if (! in_array('firm_doc_member_rights_firm_user_idx', $indexNames, true)) {
            Schema::table('firm_document_member_rights', function (Blueprint $table) {
                $table->index(['firm_id', 'user_id'], 'firm_doc_member_rights_firm_user_idx');
            });
        }
    }

    public function down(): void
    {
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

            $indexNames = collect(DB::select('SHOW INDEX FROM firm_document_member_rights'))
                ->pluck('Key_name')
                ->unique()
                ->all();

            Schema::table('firm_document_member_rights', function (Blueprint $table) use ($indexNames) {
                if (in_array('firm_doc_member_rights_doc_user_idx', $indexNames, true)) {
                    $table->dropIndex('firm_doc_member_rights_doc_user_idx');
                }
                if (in_array('firm_doc_member_rights_firm_user_idx', $indexNames, true)) {
                    $table->dropIndex('firm_doc_member_rights_firm_user_idx');
                }
                if (in_array('firm_doc_member_rights_firm_id_idx', $indexNames, true)) {
                    $table->dropIndex('firm_doc_member_rights_firm_id_idx');
                }
                if (in_array('firm_doc_member_rights_user_id_idx', $indexNames, true)) {
                    $table->dropIndex('firm_doc_member_rights_user_id_idx');
                }
            });

            if (Schema::hasColumn('firm_document_member_rights', 'firm_document_id')) {
                Schema::table('firm_document_member_rights', function (Blueprint $table) {
                    $table->dropConstrainedForeignId('firm_document_id');
                });
            }

            Schema::table('firm_document_member_rights', function (Blueprint $table) {
                $table->unique(['firm_id', 'user_id']);
            });
        }

        if (Schema::hasColumn('firm_documents', 'folder_id')) {
            Schema::table('firm_documents', function (Blueprint $table) {
                $table->dropConstrainedForeignId('folder_id');
            });
        }
        if (Schema::hasColumn('firm_documents', 'category_id')) {
            Schema::table('firm_documents', function (Blueprint $table) {
                $table->dropConstrainedForeignId('category_id');
            });
        }

        Schema::dropIfExists('firm_document_folders');
        Schema::dropIfExists('firm_document_categories');
    }
};
