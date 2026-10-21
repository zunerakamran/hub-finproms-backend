<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hubs')) {
            return;
        }

        Schema::table('hubs', function (Blueprint $table) {
            if (! Schema::hasColumn('hubs', 'restore_token')) {
                $table->text('restore_token')->nullable()->after('backup_token');
            }
            if (! Schema::hasColumn('hubs', 'gc_file_token')) {
                $table->text('gc_file_token')->nullable()->after('restore_token');
            }
            if (! Schema::hasColumn('hubs', 'deploy_env_token')) {
                $table->text('deploy_env_token')->nullable()->after('gc_file_token');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('hubs')) {
            return;
        }

        Schema::table('hubs', function (Blueprint $table) {
            foreach (['deploy_env_token', 'gc_file_token', 'restore_token'] as $column) {
                if (Schema::hasColumn('hubs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
