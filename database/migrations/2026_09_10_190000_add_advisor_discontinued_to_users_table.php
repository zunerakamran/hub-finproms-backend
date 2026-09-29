<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_discontinued')->default(false)->after('is_suspended');
            $table->timestamp('discontinued_at')->nullable()->after('is_discontinued');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_discontinued', 'discontinued_at']);
        });
    }
};
