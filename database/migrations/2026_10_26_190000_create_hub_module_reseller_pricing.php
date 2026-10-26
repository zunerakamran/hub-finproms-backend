<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hub-specific amounts users pay the network when network_resells is on.
 * FinProms still bills the network from global module_pricing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hub_module_reseller_pricing', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hub_id')->constrained('hubs')->cascadeOnDelete();
            $table->string('module_key', 64);
            $table->decimal('amount', 12, 2)->default(0);
            $table->decimal('recurring_amount', 12, 2)->default(0);
            $table->string('currency', 3)->default('gbp');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['hub_id', 'module_key']);
            $table->index(['hub_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hub_module_reseller_pricing');
    }
};
