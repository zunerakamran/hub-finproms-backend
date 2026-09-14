<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wc_change_requests', function (Blueprint $table) {
            $table->unsignedInteger('current_version')->default(1)->after('rejection_reason');
            $table->text('feedback')->nullable()->after('current_version');
        });

        Schema::create('wc_change_request_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('wc_change_requests')->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->longText('proposed_content')->nullable();
            $table->string('status')->default('pending');
            $table->text('feedback')->nullable();
            $table->foreignId('submitted_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->string('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['request_id', 'version_number']);
        });

        if (Schema::hasTable('wc_platform_reports') && ! Schema::hasColumn('wc_platform_reports', 'change_requests_approved_with_feedback')) {
            Schema::table('wc_platform_reports', function (Blueprint $table) {
                $table->unsignedInteger('change_requests_approved_with_feedback')->default(0)->after('change_requests_rejected');
            });
        }

        $existing = DB::table('wc_change_requests')->orderBy('id')->get();
        foreach ($existing as $row) {
            $already = DB::table('wc_change_request_versions')
                ->where('request_id', $row->id)
                ->where('version_number', 1)
                ->exists();

            if (! $already) {
                DB::table('wc_change_request_versions')->insert([
                    'request_id' => $row->id,
                    'version_number' => 1,
                    'proposed_content' => $row->proposed_content,
                    'status' => $row->status ?: 'pending',
                    'feedback' => $row->rejection_reason ?: null,
                    'submitted_by' => $row->editor_id,
                    'submitted_at' => $row->created_at ?? now(),
                    'reviewed_by' => null,
                    'reviewed_at' => null,
                    'created_at' => $row->created_at ?? now(),
                    'updated_at' => $row->updated_at ?? now(),
                ]);
            }

            DB::table('wc_change_requests')
                ->where('id', $row->id)
                ->update(['current_version' => 1]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('wc_change_request_versions');

        Schema::table('wc_change_requests', function (Blueprint $table) {
            $table->dropColumn(['current_version', 'feedback']);
        });

        if (Schema::hasTable('wc_platform_reports') && Schema::hasColumn('wc_platform_reports', 'change_requests_approved_with_feedback')) {
            Schema::table('wc_platform_reports', function (Blueprint $table) {
                $table->dropColumn('change_requests_approved_with_feedback');
            });
        }
    }
};
