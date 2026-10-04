<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hubs', function (Blueprint $table) {
            if (! Schema::hasColumn('hubs', 'db_ssl_mode')) {
                $table->string('db_ssl_mode', 32)->nullable()->after('db_password');
            }
            if (! Schema::hasColumn('hubs', 'db_ssl_ca')) {
                $table->text('db_ssl_ca')->nullable()->after('db_ssl_mode');
            }
        });
    }

    public function down(): void
    {
        Schema::table('hubs', function (Blueprint $table) {
            $cols = [];
            if (Schema::hasColumn('hubs', 'db_ssl_mode')) {
                $cols[] = 'db_ssl_mode';
            }
            if (Schema::hasColumn('hubs', 'db_ssl_ca')) {
                $cols[] = 'db_ssl_ca';
            }
            if ($cols !== []) {
                $table->dropColumn($cols);
            }
        });
    }
};
