<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Speed concurrent catalog / admin post list filters.
 * Idempotent: skips indexes that already exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('posts')) {
            return;
        }

        $indexes = [
            'posts_is_active_created_at_index' => function (Blueprint $table) {
                if (Schema::hasColumn('posts', 'is_active') && Schema::hasColumn('posts', 'created_at')) {
                    $table->index(['is_active', 'created_at'], 'posts_is_active_created_at_index');
                }
            },
            'posts_archived_at_created_at_index' => function (Blueprint $table) {
                if (Schema::hasColumn('posts', 'archived_at') && Schema::hasColumn('posts', 'created_at')) {
                    $table->index(['archived_at', 'created_at'], 'posts_archived_at_created_at_index');
                }
            },
            'posts_type_is_active_index' => function (Blueprint $table) {
                if (Schema::hasColumn('posts', 'type') && Schema::hasColumn('posts', 'is_active')) {
                    $table->index(['type', 'is_active'], 'posts_type_is_active_index');
                }
            },
        ];

        foreach ($indexes as $name => $add) {
            if ($this->indexExists('posts', $name)) {
                continue;
            }
            try {
                Schema::table('posts', function (Blueprint $table) use ($add) {
                    $add($table);
                });
            } catch (Throwable) {
                // Duplicate / unsupported index on this host — ignore.
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('posts')) {
            return;
        }

        Schema::table('posts', function (Blueprint $table) {
            foreach ([
                'posts_is_active_created_at_index',
                'posts_archived_at_created_at_index',
                'posts_type_is_active_index',
            ] as $index) {
                try {
                    $table->dropIndex($index);
                } catch (Throwable) {
                    // Index may not exist on older DBs.
                }
            }
        });
    }

    private function indexExists(string $table, string $indexName): bool
    {
        try {
            $driver = Schema::getConnection()->getDriverName();
            if ($driver === 'mysql') {
                $db = Schema::getConnection()->getDatabaseName();
                $row = DB::selectOne(
                    'select 1 as ok from information_schema.statistics where table_schema = ? and table_name = ? and index_name = ? limit 1',
                    [$db, $table, $indexName]
                );

                return (bool) $row;
            }
            if ($driver === 'sqlite') {
                $rows = DB::select("pragma index_list('{$table}')");
                foreach ($rows as $row) {
                    if (($row->name ?? null) === $indexName) {
                        return true;
                    }
                }
            }
        } catch (Throwable) {
            return false;
        }

        return false;
    }
};
