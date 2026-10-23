<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('module_packages', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->decimal('amount', 10, 2)->default(0);
            $table->string('billing_unit')->default('one_time');
            $table->string('currency', 3)->default('gbp');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('module_package_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('module_package_id')
                ->constrained('module_packages')
                ->cascadeOnDelete();
            $table->string('module_key');
            $table->timestamps();

            $table->unique(['module_package_id', 'module_key'], 'module_package_items_unique');
            $table->index('module_key');
        });

        Schema::table('hubs', function (Blueprint $table) {
            $table->json('enabled_module_package_ids')->nullable()->after('checklist');
        });
    }

    public function down(): void
    {
        Schema::table('hubs', function (Blueprint $table) {
            $table->dropColumn('enabled_module_package_ids');
        });

        Schema::dropIfExists('module_package_items');
        Schema::dropIfExists('module_packages');
    }
};
