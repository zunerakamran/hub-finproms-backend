<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hubs', function (Blueprint $table) {
            // Remote DB for this white-label deploy (stored on shared hub control plane).
            $table->string('db_driver', 32)->nullable()->after('deploy_notes');
            $table->string('db_host')->nullable()->after('db_driver');
            $table->unsignedSmallInteger('db_port')->nullable()->after('db_host');
            $table->string('db_database')->nullable()->after('db_port');
            $table->string('db_username')->nullable()->after('db_database');
            $table->text('db_password')->nullable()->after('db_username');
        });
    }

    public function down(): void
    {
        Schema::table('hubs', function (Blueprint $table) {
            $table->dropColumn([
                'db_driver',
                'db_host',
                'db_port',
                'db_database',
                'db_username',
                'db_password',
            ]);
        });
    }
};
