<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hub_backups')) {
            return;
        }

        Schema::table('hub_backups', function (Blueprint $table) {
            if (! Schema::hasColumn('hub_backups', 'is_encrypted')) {
                $table->boolean('is_encrypted')->default(false)->after('includes_files');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('hub_backups') || ! Schema::hasColumn('hub_backups', 'is_encrypted')) {
            return;
        }

        Schema::table('hub_backups', function (Blueprint $table) {
            $table->dropColumn('is_encrypted');
        });
    }
};
