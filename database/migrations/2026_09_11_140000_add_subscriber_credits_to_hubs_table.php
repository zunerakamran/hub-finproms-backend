<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hubs', function (Blueprint $table) {
            // null = unlimited credits for private-hub Excel subscribers.
            // integer = that many credits granted per subscriber (on import & autorenew).
            $table->unsignedInteger('subscriber_credits')->nullable()->after('advisor_billing_renew_day');
        });
    }

    public function down(): void
    {
        Schema::table('hubs', function (Blueprint $table) {
            $table->dropColumn('subscriber_credits');
        });
    }
};
