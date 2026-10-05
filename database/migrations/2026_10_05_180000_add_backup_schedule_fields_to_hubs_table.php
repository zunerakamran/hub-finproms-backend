<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hubs', function (Blueprint $table) {
            if (! Schema::hasColumn('hubs', 'backup_enabled')) {
                $table->boolean('backup_enabled')->default(false)->after('db_ssl_ca');
            }
            if (! Schema::hasColumn('hubs', 'backup_time')) {
                $table->string('backup_time', 5)->default('02:00')->after('backup_enabled');
            }
            if (! Schema::hasColumn('hubs', 'backup_timezone')) {
                $table->string('backup_timezone', 64)->default('UTC')->after('backup_time');
            }
            if (! Schema::hasColumn('hubs', 'backup_frequency')) {
                $table->string('backup_frequency', 16)->default('daily')->after('backup_timezone');
            }
            if (! Schema::hasColumn('hubs', 'backup_weekday')) {
                $table->unsignedTinyInteger('backup_weekday')->nullable()->after('backup_frequency');
            }
            if (! Schema::hasColumn('hubs', 'backup_retention_local')) {
                $table->unsignedSmallInteger('backup_retention_local')->default(3)->after('backup_weekday');
            }
            if (! Schema::hasColumn('hubs', 'backup_retention_central')) {
                $table->unsignedSmallInteger('backup_retention_central')->default(14)->after('backup_retention_local');
            }
            if (! Schema::hasColumn('hubs', 'backup_last_run_at')) {
                $table->timestamp('backup_last_run_at')->nullable()->after('backup_retention_central');
            }
            if (! Schema::hasColumn('hubs', 'backup_token')) {
                $table->text('backup_token')->nullable()->after('backup_last_run_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('hubs', function (Blueprint $table) {
            $cols = [];
            foreach ([
                'backup_enabled',
                'backup_time',
                'backup_timezone',
                'backup_frequency',
                'backup_weekday',
                'backup_retention_local',
                'backup_retention_central',
                'backup_last_run_at',
                'backup_token',
            ] as $col) {
                if (Schema::hasColumn('hubs', $col)) {
                    $cols[] = $col;
                }
            }
            if ($cols !== []) {
                $table->dropColumn($cols);
            }
        });
    }
};
