<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compliance_audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hub_id')->nullable()->constrained('hubs')->nullOnDelete();
            $table->string('module', 16); // smc | gc | wc
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->string('event_type', 64);
            $table->text('description')->nullable();
            $table->string('from_status', 64)->nullable();
            $table->string('to_status', 64)->nullable();
            $table->unsignedInteger('version_number')->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name')->nullable();
            $table->string('actor_email')->nullable();
            $table->string('actor_role', 64)->nullable();
            $table->foreignId('related_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('related_user_name')->nullable();
            $table->string('related_user_email')->nullable();
            $table->string('related_user_role', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['module', 'subject_id', 'created_at'], 'compliance_audit_module_subject_created');
            $table->index(['hub_id', 'module', 'created_at'], 'compliance_audit_hub_module_created');
            $table->index(['subject_type', 'subject_id'], 'compliance_audit_subject');
            $table->index(['event_type', 'created_at'], 'compliance_audit_event_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_audit_events');
    }
};
