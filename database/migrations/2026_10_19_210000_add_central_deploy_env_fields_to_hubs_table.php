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
            if (! Schema::hasColumn('hubs', 'central_api_url')) {
                $table->string('central_api_url', 2048)->nullable()->after('api_url');
            }
            if (! Schema::hasColumn('hubs', 'central_allowed_receive_hosts')) {
                $table->string('central_allowed_receive_hosts', 2048)->nullable()->after('central_api_url');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('hubs')) {
            return;
        }

        Schema::table('hubs', function (Blueprint $table) {
            if (Schema::hasColumn('hubs', 'central_allowed_receive_hosts')) {
                $table->dropColumn('central_allowed_receive_hosts');
            }
            if (Schema::hasColumn('hubs', 'central_api_url')) {
                $table->dropColumn('central_api_url');
            }
        });
    }
};
