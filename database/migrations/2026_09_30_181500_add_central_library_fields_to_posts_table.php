<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            if (! Schema::hasColumn('posts', 'creation_source')) {
                $table->string('creation_source', 32)->default('manual')->after('is_active');
            }
            if (! Schema::hasColumn('posts', 'archived_at')) {
                $table->timestamp('archived_at')->nullable()->after('creation_source');
            }
            if (! Schema::hasColumn('posts', 'archive_remarks')) {
                $table->text('archive_remarks')->nullable()->after('archived_at');
            }
            if (! Schema::hasColumn('posts', 'archived_by')) {
                $table->foreignId('archived_by')->nullable()->after('archive_remarks')
                    ->constrained('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            if (Schema::hasColumn('posts', 'archived_by')) {
                $table->dropConstrainedForeignId('archived_by');
            }
            foreach (['archive_remarks', 'archived_at', 'creation_source'] as $column) {
                if (Schema::hasColumn('posts', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
