<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Speed concurrent catalog / admin post list filters.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('posts')) {
            return;
        }

        Schema::table('posts', function (Blueprint $table) {
            if (Schema::hasColumn('posts', 'is_active') && Schema::hasColumn('posts', 'created_at')) {
                $table->index(['is_active', 'created_at'], 'posts_is_active_created_at_index');
            }
            if (Schema::hasColumn('posts', 'archived_at')) {
                $table->index(['archived_at', 'created_at'], 'posts_archived_at_created_at_index');
            }
            if (Schema::hasColumn('posts', 'type')) {
                $table->index(['type', 'is_active'], 'posts_type_is_active_index');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('posts')) {
            return;
        }

        Schema::table('posts', function (Blueprint $table) {
            $indexes = [
                'posts_is_active_created_at_index',
                'posts_archived_at_created_at_index',
                'posts_type_is_active_index',
            ];
            foreach ($indexes as $index) {
                try {
                    $table->dropIndex($index);
                } catch (\Throwable) {
                    // Index may not exist on older DBs.
                }
            }
        });
    }
};
