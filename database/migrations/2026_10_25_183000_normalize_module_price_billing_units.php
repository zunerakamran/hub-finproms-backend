<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Align catalogue / package units with the unified dropdown:
 * - one_time → per_network
 * - per_adviser → per_user_slots
 * - per_user + tier slot → per_user_slots (legacy seat rows)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('module_pricing')) {
            DB::table('module_pricing')->where('billing_unit', 'one_time')->update([
                'billing_unit' => 'per_network',
            ]);

            DB::table('module_pricing')->where('recurring_billing_unit', 'per_adviser')->update([
                'recurring_billing_unit' => 'per_user_slots',
            ]);

            // Legacy seat rows stored as per_user + slot.
            DB::table('module_pricing')
                ->where('recurring_billing_unit', 'per_user')
                ->whereNotNull('recurring_tier_slot')
                ->where('recurring_tier_slot', '>=', 1)
                ->update(['recurring_billing_unit' => 'per_user_slots']);
        }

        if (Schema::hasTable('module_packages')) {
            DB::table('module_packages')->where('billing_unit', 'one_time')->update([
                'billing_unit' => 'per_network',
            ]);

            DB::table('module_packages')->where('recurring_billing_unit', 'per_adviser')->update([
                'recurring_billing_unit' => 'per_user_slots',
            ]);

            DB::table('module_packages')
                ->where('recurring_billing_unit', 'per_user')
                ->whereNotNull('recurring_tier_slot')
                ->where('recurring_tier_slot', '>=', 1)
                ->update(['recurring_billing_unit' => 'per_user_slots']);
        }
    }

    public function down(): void
    {
        // Non-destructive: keep canonical units.
    }
};
