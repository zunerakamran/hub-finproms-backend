<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hub_module_billings', function (Blueprint $table) {
            $table->timestamp('paid_at')->nullable()->after('payment_status');
            $table->foreignId('paid_by_user_id')
                ->nullable()
                ->after('paid_at')
                ->constrained('users')
                ->nullOnDelete();
            $table->string('payment_method')->nullable()->after('paid_by_user_id');
            $table->string('payment_reference')->nullable()->after('payment_method');
            $table->text('payment_notes')->nullable()->after('payment_reference');
            $table->string('payment_attachment_path')->nullable()->after('payment_notes');
            $table->string('payment_attachment_name')->nullable()->after('payment_attachment_path');
            $table->string('payment_attachment_mime')->nullable()->after('payment_attachment_name');
        });
    }

    public function down(): void
    {
        Schema::table('hub_module_billings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('paid_by_user_id');
            $table->dropColumn([
                'paid_at',
                'payment_method',
                'payment_reference',
                'payment_notes',
                'payment_attachment_path',
                'payment_attachment_name',
                'payment_attachment_mime',
            ]);
        });
    }
};
