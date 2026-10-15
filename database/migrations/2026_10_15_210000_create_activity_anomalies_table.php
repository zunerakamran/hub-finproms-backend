<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('activity_anomalies')) {
            return;
        }

        Schema::create('activity_anomalies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hub_id')->nullable()->constrained('hubs')->nullOnDelete();
            $table->timestamp('detected_at');
            $table->timestamp('window_start')->nullable();
            $table->timestamp('window_end')->nullable();
            $table->string('anomaly_type', 120);
            $table->string('severity', 20)->default('medium');
            $table->string('title', 255);
            $table->text('description');
            $table->string('status', 40)->default('open');
            $table->unsignedBigInteger('actor_user_id')->nullable()->index();
            $table->string('actor_name')->nullable();
            $table->string('actor_email')->nullable();
            $table->string('actor_role', 64)->nullable();
            $table->string('source_ip', 45)->nullable()->index();
            $table->json('related_activity_log_ids')->nullable();
            $table->json('properties')->nullable();
            $table->unsignedBigInteger('security_event_id')->nullable()->index();
            $table->unsignedBigInteger('security_incident_id')->nullable()->index();
            $table->unsignedBigInteger('reviewed_by_user_id')->nullable();
            $table->string('reviewed_by_name')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['hub_id', 'detected_at']);
            $table->index(['hub_id', 'status', 'detected_at']);
            $table->index(['anomaly_type', 'detected_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_anomalies');
    }
};
