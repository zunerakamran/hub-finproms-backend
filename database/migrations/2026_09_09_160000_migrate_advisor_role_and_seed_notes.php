<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Flag-based advisors → role advisor
        DB::table('users')
            ->where('is_advisor', true)
            ->where('role', 'user')
            ->update(['role' => 'advisor']);
    }

    public function down(): void
    {
        DB::table('users')
            ->where('role', 'advisor')
            ->update(['role' => 'user']);
    }
};
