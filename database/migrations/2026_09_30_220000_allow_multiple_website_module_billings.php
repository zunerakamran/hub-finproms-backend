<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Allow multiple one-time WTL invoices (one per deployed website).
     * Module-enable one-time rows still use meta without wc_template_request_id.
     */
    public function up(): void
    {
        Schema::table('hub_module_billings', function (Blueprint $table) {
            $table->dropUnique(['hub_id', 'module_key']);
            $table->index(['hub_id', 'module_key']);
        });
    }

    public function down(): void
    {
        Schema::table('hub_module_billings', function (Blueprint $table) {
            $table->dropIndex(['hub_id', 'module_key']);
            $table->unique(['hub_id', 'module_key']);
        });
    }
};
