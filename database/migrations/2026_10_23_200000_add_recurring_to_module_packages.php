<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('module_packages', function (Blueprint $table) {
            $table->decimal('recurring_amount', 10, 2)->default(0)->after('amount');
            $table->string('recurring_billing_unit')->default('none')->after('recurring_amount');
            $table->unsignedTinyInteger('recurring_tier_slot')->nullable()->after('recurring_billing_unit');
        });
    }

    public function down(): void
    {
        Schema::table('module_packages', function (Blueprint $table) {
            $table->dropColumn(['recurring_amount', 'recurring_billing_unit', 'recurring_tier_slot']);
        });
    }
};
