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
        if (! Schema::hasTable('wc_templates')) {
            Schema::create('wc_templates', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('slug')->unique();
                $table->text('description')->nullable();
                $table->string('thumbnail_url', 500)->nullable();
                $table->string('preview_url', 500)->nullable();
                $table->longText('dummy_content')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('wc_pages')) {
            Schema::create('wc_pages', function (Blueprint $table) {
                $table->id();
                $table->foreignId('template_id')->nullable()->constrained('wc_templates')->nullOnDelete();
                $table->string('title');
                $table->string('slug')->unique();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('wc_sections')) {
            Schema::create('wc_sections', function (Blueprint $table) {
                $table->id();
                $table->foreignId('page_id')->constrained('wc_pages')->cascadeOnDelete();
                $table->foreignId('template_id')->nullable()->constrained('wc_templates')->nullOnDelete();
                $table->foreignId('advisor_id')->nullable()->constrained('users')->nullOnDelete();
                $table->unsignedBigInteger('template_request_id')->nullable()->index();
                $table->string('name');
                $table->string('section_key')->nullable();
                $table->string('display_name')->nullable();
                $table->boolean('is_visible')->default(true);
                $table->longText('content')->nullable();
                $table->boolean('is_locked')->default(false);
                $table->foreignId('locked_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(
                    ['template_request_id', 'page_id', 'advisor_id', 'name'],
                    'wc_sections_scope_index'
                );
            });
        }

        if (! Schema::hasTable('wc_change_requests')) {
            Schema::create('wc_change_requests', function (Blueprint $table) {
                $table->id();
                $table->foreignId('section_id')->nullable()->constrained('wc_sections')->nullOnDelete();
                $table->foreignId('editor_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('approver_id')->nullable()->constrained('users')->nullOnDelete();
                $table->longText('proposed_content')->nullable();
                $table->string('status')->default('pending');
                $table->timestamp('scheduled_at')->nullable();
                $table->text('rejection_reason')->nullable();
                $table->timestamps();

                $table->index(['status', 'scheduled_at']);
                $table->index('editor_id');
            });
        }

        if (! Schema::hasTable('wc_template_requests')) {
            Schema::create('wc_template_requests', function (Blueprint $table) {
                $table->id();
                $table->foreignId('advisor_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('requested_by_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('assigned_advisor_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('template_name');
                $table->string('request_type')->default('advisor_website');
                $table->string('domain_name')->nullable();
                $table->string('logo_url', 500)->nullable();
                $table->string('primary_color', 50)->nullable();
                $table->string('secondary_color', 50)->nullable();
                $table->string('status')->default('pending');
                $table->text('rejection_reason')->nullable();
                $table->string('cpanel_domain')->nullable();
                $table->string('cpanel_db_host')->nullable();
                $table->string('cpanel_db_name')->nullable();
                $table->string('cpanel_db_user')->nullable();
                $table->string('cpanel_db_password')->nullable();
                $table->string('cpanel_api_key')->nullable();
                $table->timestamps();

                $table->index(['status', 'advisor_id']);
            });
        }

        if (! Schema::hasTable('wc_platform_reports')) {
            Schema::create('wc_platform_reports', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('templates_total')->default(0);
                $table->unsignedInteger('templates_active')->default(0);
                $table->unsignedInteger('templates_inactive')->default(0);
                $table->unsignedInteger('users_total')->default(0);
                $table->unsignedInteger('advisors_count')->default(0);
                $table->unsignedInteger('approvers_count')->default(0);
                $table->unsignedInteger('managers_count')->default(0);
                $table->unsignedInteger('client_admins_count')->default(0);
                $table->unsignedInteger('power_admins_count')->default(0);
                $table->unsignedInteger('template_requests_total')->default(0);
                $table->unsignedInteger('template_requests_pending')->default(0);
                $table->unsignedInteger('template_requests_deployed')->default(0);
                $table->unsignedInteger('template_requests_rejected')->default(0);
                $table->unsignedInteger('template_requests_advisor_website')->default(0);
                $table->unsignedInteger('template_requests_hub_main_website')->default(0);
                $table->json('template_requests_by_template')->nullable();
                $table->unsignedInteger('change_requests_total')->default(0);
                $table->unsignedInteger('change_requests_pending')->default(0);
                $table->unsignedInteger('change_requests_under_review')->default(0);
                $table->unsignedInteger('change_requests_scheduled')->default(0);
                $table->unsignedInteger('change_requests_approved')->default(0);
                $table->unsignedInteger('change_requests_rejected')->default(0);
                $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('generated_at')->useCurrent();
                $table->timestamps();
            });
        }

        $matrix = app(CapabilitiesMatrixService::class);

        Hub::query()->orderBy('id')->each(function (Hub $hub) use ($matrix) {
            $checklist = $hub->resolvedChecklist();

            if (! array_key_exists('module_website_compliance', $checklist)) {
                $checklist['module_website_compliance'] = false;
            }

            $roleCaps = $matrix->resolvedRoleCapabilities($hub);
            foreach (CapabilitiesMatrixService::MATRIX_ROLES as $role) {
                if (! isset($roleCaps[$role]) || ! is_array($roleCaps[$role])) {
                    $roleCaps[$role] = [];
                }
                foreach (Hub::WEBSITE_COMPLIANCE_CAPABILITY_KEYS as $key) {
                    if (! array_key_exists($key, $roleCaps[$role])) {
                        $roleCaps[$role][$key] = false;
                    }
                }
            }

            foreach (Hub::WEBSITE_COMPLIANCE_CAPABILITY_KEYS as $key) {
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
        Schema::dropIfExists('wc_platform_reports');
        Schema::dropIfExists('wc_change_requests');
        Schema::dropIfExists('wc_sections');
        Schema::dropIfExists('wc_template_requests');
        Schema::dropIfExists('wc_pages');
        Schema::dropIfExists('wc_templates');

        Hub::query()->orderBy('id')->each(function (Hub $hub) {
            $checklist = is_array($hub->checklist) ? $hub->checklist : [];
            foreach (Hub::WEBSITE_COMPLIANCE_CAPABILITY_KEYS as $key) {
                unset($checklist[$key]);
            }

            $roleCaps = is_array($hub->role_capabilities) ? $hub->role_capabilities : [];
            foreach ($roleCaps as $role => $caps) {
                if (! is_array($caps)) {
                    continue;
                }
                foreach (Hub::WEBSITE_COMPLIANCE_CAPABILITY_KEYS as $key) {
                    unset($roleCaps[$role][$key]);
                }
            }

            $hub->checklist = $checklist;
            $hub->role_capabilities = $roleCaps;
            $hub->save();
        });
    }
};
