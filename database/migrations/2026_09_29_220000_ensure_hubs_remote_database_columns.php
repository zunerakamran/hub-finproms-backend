<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Older Central deploys sometimes have db_host/db_username but missed db_password.
 * Writing a password then fatals with Unknown column → opaque 500 in Power Admin wiring.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hubs')) {
            return;
        }

        $columns = [
            'db_driver' => fn (Blueprint $table) => $table->string('db_driver', 32)->nullable(),
            'db_host' => fn (Blueprint $table) => $table->string('db_host')->nullable(),
            'db_port' => fn (Blueprint $table) => $table->unsignedSmallInteger('db_port')->nullable(),
            'db_database' => fn (Blueprint $table) => $table->string('db_database')->nullable(),
            'db_username' => fn (Blueprint $table) => $table->string('db_username')->nullable(),
            'db_password' => fn (Blueprint $table) => $table->text('db_password')->nullable(),
        ];

        foreach ($columns as $name => $add) {
            if (Schema::hasColumn('hubs', $name)) {
                continue;
            }
            Schema::table('hubs', function (Blueprint $table) use ($add) {
                $add($table);
            });
        }
    }

    public function down(): void
    {
        // Non-destructive heal — do not drop columns that may hold live wiring.
    }
};
