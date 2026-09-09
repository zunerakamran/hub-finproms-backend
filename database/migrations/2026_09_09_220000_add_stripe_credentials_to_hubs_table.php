<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hubs', function (Blueprint $table) {
            $table->string('stripe_key')->nullable()->after('logo_url');
            $table->text('stripe_secret')->nullable()->after('stripe_key');
            $table->text('stripe_webhook_secret')->nullable()->after('stripe_secret');
            $table->string('stripe_currency', 3)->nullable()->after('stripe_webhook_secret');
        });
    }

    public function down(): void
    {
        Schema::table('hubs', function (Blueprint $table) {
            $table->dropColumn([
                'stripe_key',
                'stripe_secret',
                'stripe_webhook_secret',
                'stripe_currency',
            ]);
        });
    }
};
