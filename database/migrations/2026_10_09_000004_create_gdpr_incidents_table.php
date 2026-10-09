<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('gdpr_incidents')) {
            return;
        }

        Schema::create('gdpr_incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reported_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->text('summary');
            $table->string('severity', 32)->default('unknown'); // confidentiality / integrity / availability / unknown
            $table->string('status', 32)->default('open'); // open / investigating / contained / closed
            $table->timestamp('discovered_at')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->boolean('ico_notified')->default(false);
            $table->timestamp('ico_notified_at')->nullable();
            $table->boolean('individuals_notified')->default(false);
            $table->timestamp('individuals_notified_at')->nullable();
            $table->unsignedInteger('affected_estimate')->nullable();
            $table->text('actions_taken')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gdpr_incidents');
    }
};
