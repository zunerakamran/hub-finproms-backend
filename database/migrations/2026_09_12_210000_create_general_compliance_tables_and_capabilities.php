<?php

use App\Models\Hub;
use App\Services\CapabilitiesMatrixService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('general_compliance_requests')) {
            Schema::create('general_compliance_requests', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('name');
                $table->unsignedInteger('current_version')->default(1);
                $table->timestamp('submission_date')->useCurrent();
                $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('assigned_date')->nullable();
                $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                // Short names: MySQL identifier limit is 64 chars.
                $table->index(['user_id', 'submission_date'], 'gc_req_user_sub_idx');
                $table->index(['assigned_to', 'submission_date'], 'gc_req_assignee_sub_idx');
            });
        }

        if (! Schema::hasTable('general_compliance_request_versions')) {
            Schema::create('general_compliance_request_versions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('request_id')->constrained('general_compliance_requests')->cascadeOnDelete();
                $table->unsignedTinyInteger('version_number')->default(1);
                $table->text('description')->nullable();
                $table->foreignId('submitted_by')->constrained('users')->cascadeOnDelete();
                $table->timestamp('submitted_at')->useCurrent();
                $table->string('status', 50)->nullable();
                $table->text('feedback')->nullable();
                $table->string('reviewed_by')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->timestamps();

                $table->unique(['request_id', 'version_number'], 'gc_versions_request_version_unique');
                $table->index(['request_id', 'status'], 'gc_versions_request_status_index');
            });
        }

        if (! Schema::hasTable('general_compliance_request_attachments')) {
            Schema::create('general_compliance_request_attachments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('version_id')
                    ->constrained('general_compliance_request_versions')
                    ->cascadeOnDelete();
                $table->string('original_name');
                $table->string('file_path', 500);
                $table->string('file_url', 500)->nullable();
                $table->string('mime_type', 120)->nullable();
                $table->unsignedBigInteger('size_bytes')->default(0);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();

                $table->index(['version_id', 'sort_order'], 'gc_attachments_version_sort_index');
            });
        }

        $matrix = app(CapabilitiesMatrixService::class);

        Hub::query()->orderBy('id')->each(function (Hub $hub) use ($matrix) {
            $checklist = $hub->resolvedChecklist();

            // Module key already exists in MODULE_KEYS; ensure present (default OFF).
            if (! array_key_exists('module_general_compliance', $checklist)) {
                $checklist['module_general_compliance'] = false;
            }

            $roleCaps = $matrix->resolvedRoleCapabilities($hub);
            foreach (CapabilitiesMatrixService::MATRIX_ROLES as $role) {
                if (! isset($roleCaps[$role]) || ! is_array($roleCaps[$role])) {
                    $roleCaps[$role] = [];
                }
                foreach (Hub::GENERAL_COMPLIANCE_CAPABILITY_KEYS as $key) {
                    if (! array_key_exists($key, $roleCaps[$role])) {
                        $roleCaps[$role][$key] = false;
                    }
                }
            }

            foreach (Hub::GENERAL_COMPLIANCE_CAPABILITY_KEYS as $key) {
                $any = false;
                foreach (CapabilitiesMatrixService::MATRIX_ROLES as $role) {
                    if (! empty($roleCaps[$role][$key])) {
                        $any = true;
                        break;
                    }
                }
                $checklist[$key] = $any;
            }

            $hub->checklist = $checklist;
            $hub->role_capabilities = $roleCaps;
            $hub->save();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('general_compliance_request_attachments');
        Schema::dropIfExists('general_compliance_request_versions');
        Schema::dropIfExists('general_compliance_requests');

        Hub::query()->orderBy('id')->each(function (Hub $hub) {
            $checklist = is_array($hub->checklist) ? $hub->checklist : [];
            foreach (Hub::GENERAL_COMPLIANCE_CAPABILITY_KEYS as $key) {
                unset($checklist[$key]);
            }

            $roleCaps = is_array($hub->role_capabilities) ? $hub->role_capabilities : [];
            foreach ($roleCaps as $role => $caps) {
                if (! is_array($caps)) {
                    continue;
                }
                foreach (Hub::GENERAL_COMPLIANCE_CAPABILITY_KEYS as $key) {
                    unset($roleCaps[$role][$key]);
                }
            }

            $hub->checklist = $checklist;
            $hub->role_capabilities = $roleCaps;
            $hub->save();
        });
    }
};
