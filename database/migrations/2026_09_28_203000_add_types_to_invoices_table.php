<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // one_time | per_user_buying | ongoing
            $table->string('types', 32)->default('one_time')->after('type');
            $table->index('types');
        });

        DB::table('invoices')->where('type', 'subscription')->update(['types' => 'ongoing']);
        DB::table('invoices')->whereIn('type', ['post_purchase', 'bundle_purchase'])->update(['types' => 'one_time']);
        DB::table('invoices')->where('type', 'advisor_billing')->update(['types' => 'per_user_buying']);
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['types']);
            $table->dropColumn('types');
        });
    }
};
