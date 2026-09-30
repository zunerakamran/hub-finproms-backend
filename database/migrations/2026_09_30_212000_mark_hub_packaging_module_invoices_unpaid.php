<?php

use App\Models\Hub;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Hub packaging invoices (Shared / White Label / Central) used to be
     * auto-created as paid. Align them with other module invoices: unpaid
     * until payment is recorded. Skip rows that were manually settled
     * (paid_by_user_id set).
     */
    public function up(): void
    {
        $baseKeys = Hub::LOCKED_MODULE_KEYS;

        $billingIds = DB::table('hub_module_billings')
            ->whereIn('module_key', $baseKeys)
            ->where('status', 'paid')
            ->whereNull('paid_by_user_id')
            ->pluck('id');

        if ($billingIds->isEmpty()) {
            return;
        }

        DB::table('hub_module_billings')
            ->whereIn('id', $billingIds)
            ->update([
                'status' => 'unpaid',
                'payment_status' => 'unpaid',
                'updated_at' => now(),
            ]);

        DB::table('invoices')
            ->where('type', 'module_billing')
            ->whereIn('hub_module_billing_id', $billingIds)
            ->update([
                'status' => 'unpaid',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Intentionally empty — do not re-auto-pay packaging invoices.
    }
};
