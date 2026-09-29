<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hubs', function (Blueprint $table) {
            // Public site URL for this hub's deploy (emails, Stripe redirects, Power Admin registry).
            $table->string('frontend_url', 2048)->nullable()->after('from_email');
            // Backend API base URL for this hub's deploy (Power Admin registry / ops reference).
            $table->string('api_url', 2048)->nullable()->after('frontend_url');
            // Free-text deploy notes (e.g. hosting provider, HUB_SLUG reminder).
            $table->text('deploy_notes')->nullable()->after('api_url');
        });
    }

    public function down(): void
    {
        Schema::table('hubs', function (Blueprint $table) {
            $table->dropColumn(['frontend_url', 'api_url', 'deploy_notes']);
        });
    }
};
