<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('bundle_purchase_id')
                ->nullable()
                ->after('post_purchase_id')
                ->constrained('bundle_purchases')
                ->nullOnDelete();
            $table->unique('bundle_purchase_id');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bundle_purchase_id');
        });
    }
};
