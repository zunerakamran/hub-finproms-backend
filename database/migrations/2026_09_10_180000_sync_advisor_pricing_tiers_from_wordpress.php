<?php

use App\Services\AdvisorPricingService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Copy default discount tiers from the WordPress FP Subscriptions plugin.
     * WP: 0–99 @ £25, 100–249 @ £22, 250–499 @ £20, 500+ @ £15.
     */
    public function up(): void
    {
        app(AdvisorPricingService::class)->syncWordPressDefaultTiers();
    }

    public function down(): void
    {
        // Intentionally left blank — previous custom tiers are not restored.
    }
};
