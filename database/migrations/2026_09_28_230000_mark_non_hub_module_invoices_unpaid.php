<?php

use App\Models\Hub;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $baseKeys = Hub::LOCKED_MODULE_KEYS;

        // Product-module invoices that were auto-marked paid → unpaid until collection.
        $billingIds = DB::table('hub_module_billings')
            ->whereNotIn('module_key', $baseKeys)
            ->where('status', 'paid')
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
        // Intentionally empty — do not re-mark product invoices paid.
    }
};
