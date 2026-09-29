<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Plan subscribers and private-hub Excel advisors keep role=user.
     * Excel advisors remain identifiable via is_advisor / has_unlimited_credits.
     */
    public function up(): void
    {
        DB::table('users')
            ->where('role', 'advisor')
            ->update(['role' => 'user']);
    }

    public function down(): void
    {
        // Restore Excel-imported advisors only (flag-based).
        DB::table('users')
            ->where('role', 'user')
            ->where('is_advisor', true)
            ->update(['role' => 'advisor']);
    }
};
