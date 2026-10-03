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
        if (! Schema::hasTable('support_tickets')) {
            Schema::create('support_tickets', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('subject', 255);
                $table->string('module_area', 100);
                $table->string('category', 50)->default('bug');
                $table->string('priority', 20)->default('medium');
                $table->longText('description');
                $table->string('status', 40)->default('Open');
                $table->string('page_url', 500)->nullable();
                $table->string('browser_info', 500)->nullable();
                $table->text('status_note')->nullable();
                // No FK: Power Admin acting remotely may not exist in this hub's users table.
                $table->unsignedBigInteger('status_changed_by')->nullable();
                $table->timestamp('status_changed_at')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->timestamp('closed_at')->nullable();
                $table->timestamps();

                $table->index(['user_id', 'status'], 'st_user_status_idx');
                $table->index(['status', 'created_at'], 'st_status_created_idx');
                $table->index(['module_area', 'status'], 'st_module_status_idx');
            });
        }

        if (! Schema::hasTable('support_ticket_attachments')) {
            Schema::create('support_ticket_attachments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('ticket_id')->constrained('support_tickets')->cascadeOnDelete();
                $table->string('original_name');
                $table->string('file_path', 500);
                $table->string('file_url', 500)->nullable();
                $table->string('mime_type', 120)->nullable();
                $table->unsignedBigInteger('size_bytes')->default(0);
                $table->unsignedSmallInteger('sort_order')->default(0);
                // No FK: uploader may be a control-plane user absent from this hub DB.
                $table->unsignedBigInteger('uploaded_by_user_id')->nullable();
                $table->string('uploaded_by_name')->nullable();
                $table->timestamps();

                $table->index(['ticket_id', 'sort_order'], 'st_attach_ticket_sort_idx');
            });
        }

        if (! Schema::hasTable('support_ticket_comments')) {
            Schema::create('support_ticket_comments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('ticket_id')->constrained('support_tickets')->cascadeOnDelete();
                // No FK: commenter may be a control-plane user absent from this hub DB.
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('author_name')->nullable();
                $table->text('body');
                $table->string('from_status', 40)->nullable();
                $table->string('to_status', 40)->nullable();
                $table->timestamps();

                $table->index(['ticket_id', 'created_at'], 'st_comments_ticket_created_idx');
            });
        }

        $matrix = app(CapabilitiesMatrixService::class);

        Hub::query()->orderBy('id')->each(function (Hub $hub) use ($matrix) {
            $checklist = $hub->resolvedChecklist();

            // Functionality flag (not a Modules-page product module).
            $legacyModuleOn = ! empty($checklist['module_support_tickets']);
            unset($checklist['module_support_tickets']);
            if (! array_key_exists('support_tickets', $checklist)) {
                $checklist['support_tickets'] = $legacyModuleOn;
            }

            $roleCaps = $matrix->resolvedRoleCapabilities($hub);
            foreach (CapabilitiesMatrixService::MATRIX_ROLES as $role) {
                if (! isset($roleCaps[$role]) || ! is_array($roleCaps[$role])) {
                    $roleCaps[$role] = [];
                }
                foreach (Hub::SUPPORT_TICKETS_CAPABILITY_KEYS as $key) {
                    if (! array_key_exists($key, $roleCaps[$role])) {
                        $roleCaps[$role][$key] = false;
                    }
                }
            }

            foreach (Hub::SUPPORT_TICKETS_CAPABILITY_KEYS as $key) {
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
        Schema::dropIfExists('support_ticket_comments');
        Schema::dropIfExists('support_ticket_attachments');
        Schema::dropIfExists('support_tickets');

        Hub::query()->orderBy('id')->each(function (Hub $hub) {
            $checklist = is_array($hub->checklist) ? $hub->checklist : [];
            unset($checklist['support_tickets'], $checklist['module_support_tickets']);
            foreach (Hub::SUPPORT_TICKETS_CAPABILITY_KEYS as $key) {
                unset($checklist[$key]);
            }

            $roleCaps = is_array($hub->role_capabilities) ? $hub->role_capabilities : [];
            foreach ($roleCaps as $role => $caps) {
                if (! is_array($caps)) {
                    continue;
                }
                foreach (Hub::SUPPORT_TICKETS_CAPABILITY_KEYS as $key) {
                    unset($roleCaps[$role][$key]);
                }
            }

            $hub->checklist = $checklist;
            $hub->role_capabilities = $roleCaps;
            $hub->save();
        });
    }
};
