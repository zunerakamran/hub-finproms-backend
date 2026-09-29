<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Official one-time / per-website module catalogue prices.
     *
     * @var array<string, array{amount: float, billing_unit: string}>
     */
    private const CATALOGUE = [
        'module_shared_hub' => ['amount' => 14500.00, 'billing_unit' => 'one_time'],
        'module_white_label_hub' => ['amount' => 14500.00, 'billing_unit' => 'one_time'],
        'module_social_media_template_library' => ['amount' => 0.00, 'billing_unit' => 'one_time'],
        'module_social_media_compliance' => ['amount' => 5000.00, 'billing_unit' => 'one_time'],
        'module_website_template_library' => ['amount' => 300.00, 'billing_unit' => 'per_website'],
        'module_website_compliance' => ['amount' => 10000.00, 'billing_unit' => 'one_time'],
        'module_general_compliance' => ['amount' => 3500.00, 'billing_unit' => 'one_time'],
    ];

    public function up(): void
    {
        Schema::table('module_pricing', function (Blueprint $table) {
            // one_time | per_website
            $table->string('billing_unit', 32)->default('one_time')->after('amount');
        });

        foreach (self::CATALOGUE as $key => $meta) {
            DB::table('module_pricing')->updateOrInsert(
                ['module_key' => $key],
                [
                    'amount' => $meta['amount'],
                    'billing_unit' => $meta['billing_unit'],
                    'currency' => 'gbp',
                    'is_active' => true,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
    }

    public function down(): void
    {
        Schema::table('module_pricing', function (Blueprint $table) {
            $table->dropColumn('billing_unit');
        });
    }
};
