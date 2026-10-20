<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user access restrictions (merged with hub rules after authentication).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('user_access_restriction_settings')) {
            return;
        }

        Schema::create('user_access_restriction_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('hub_id');
            $table->unsignedBigInteger('user_id');
            $table->boolean('access_uk_only')->default(false);
            $table->boolean('access_uk_cities_enabled')->default(false);
            $table->json('access_restriction_cities')->nullable();
            $table->boolean('access_restrict_countries_enabled')->default(false);
            $table->boolean('access_allow_countries_enabled')->default(false);
            $table->json('access_restricted_countries')->nullable();
            $table->json('access_allowed_countries')->nullable();
            $table->boolean('access_restrict_ips_enabled')->default(false);
            $table->boolean('access_allow_ips_enabled')->default(false);
            $table->json('access_restricted_ips')->nullable();
            $table->json('access_allowed_ips')->nullable();
            $table->boolean('access_restrict_vpn_enabled')->default(false);
            $table->boolean('access_allow_vpn_enabled')->default(false);
            $table->timestamps();

            $table->unique(['hub_id', 'user_id'], 'user_access_hub_user_unique');
            $table->index('user_id', 'user_access_user_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_access_restriction_settings');
    }
};
