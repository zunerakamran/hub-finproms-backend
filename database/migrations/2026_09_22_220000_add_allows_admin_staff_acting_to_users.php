<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'allows_admin_staff_acting')) {
                $table->boolean('allows_admin_staff_acting')
                    ->default(false)
                    ->after('is_advisor');
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'allows_admin_staff_acting')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('allows_admin_staff_acting');
            });
        }
    }
};
