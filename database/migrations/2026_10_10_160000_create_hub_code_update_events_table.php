<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hub_code_update_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hub_id')->nullable()->constrained('hubs')->nullOnDelete();
            $table->string('hub_slug', 255)->nullable()->index();
            $table->string('hub_name', 255)->nullable();
            $table->foreignId('hub_release_id')->nullable()->constrained('hub_releases')->nullOnDelete();
            $table->string('version', 64)->index();
            $table->string('action', 32)->default('apply')->index(); // apply | mark_manual
            $table->string('status', 32)->index(); // success | failed
            $table->text('message')->nullable();
            $table->boolean('backend_applied')->nullable();
            $table->boolean('frontend_applied')->nullable();
            $table->boolean('migrated')->nullable();
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finished_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hub_code_update_events');
    }
};
