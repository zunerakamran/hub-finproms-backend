<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hubs') && ! Schema::hasColumn('hubs', 'live_chat_support_email')) {
            Schema::table('hubs', function (Blueprint $table) {
                $table->string('live_chat_support_email', 255)
                    ->nullable()
                    ->after('live_chat_agents');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('hubs') && Schema::hasColumn('hubs', 'live_chat_support_email')) {
            Schema::table('hubs', function (Blueprint $table) {
                $table->dropColumn('live_chat_support_email');
            });
        }
    }
};
