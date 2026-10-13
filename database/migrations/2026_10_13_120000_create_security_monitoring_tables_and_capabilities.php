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
        if (! Schema::hasTable('security_events')) {
            Schema::create('security_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('hub_id')->nullable()->constrained('hubs')->nullOnDelete();
                // dateTime (not timestamp): MySQL rejects NOT NULL timestamp without default
                // under strict sql_mode (error 1067 Invalid default value).
                $table->dateTime('occurred_at');
                $table->dateTime('detected_at');
                $table->string('category', 40);
                $table->string('event_type', 120);
                $table->string('severity', 20)->default('info');
                $table->string('title', 255);
                $table->text('description');
                $table->string('status', 40)->default('open');
                $table->unsignedBigInteger('actor_user_id')->nullable();
                $table->string('actor_name')->nullable();
                $table->string('actor_email')->nullable();
                $table->string('actor_role', 60)->nullable();
                $table->string('source_ip', 45)->nullable();
                $table->string('user_agent', 500)->nullable();
                $table->string('route', 255)->nullable();
                $table->string('http_action', 40)->nullable();
                $table->unsignedBigInteger('security_incident_id')->nullable();
                $table->json('related_refs')->nullable();
                $table->json('properties')->nullable();
                $table->timestamps();

                $table->index(['occurred_at', 'severity'], 'secmon_events_occurred_sev_idx');
                $table->index(['category', 'status'], 'secmon_events_cat_status_idx');
                $table->index(['event_type', 'occurred_at'], 'secmon_events_type_occurred_idx');
                $table->index(['source_ip', 'occurred_at'], 'secmon_events_ip_occurred_idx');
                $table->index(['security_incident_id'], 'secmon_events_incident_idx');
            });
        }

        if (! Schema::hasTable('security_incidents')) {
            Schema::create('security_incidents', function (Blueprint $table) {
                $table->id();
                $table->foreignId('hub_id')->nullable()->constrained('hubs')->nullOnDelete();
                $table->string('title', 255);
                $table->text('description')->nullable();
                $table->string('category', 40)->default('manual');
                $table->string('severity', 20)->default('medium');
                $table->string('status', 40)->default('open');
                $table->unsignedBigInteger('declared_by_user_id')->nullable();
                $table->string('declared_by_name')->nullable();
                $table->unsignedBigInteger('resolved_by_user_id')->nullable();
                $table->string('resolved_by_name')->nullable();
                $table->text('resolution_summary')->nullable();
                $table->dateTime('resolved_at')->nullable();
                $table->timestamps();

                $table->index(['status', 'severity', 'created_at'], 'secmon_inc_status_sev_created_idx');
            });
        }

        if (! Schema::hasTable('security_incident_notes')) {
            Schema::create('security_incident_notes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('security_incident_id')->constrained('security_incidents')->cascadeOnDelete();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('author_name')->nullable();
                $table->text('body');
                $table->timestamps();

                $table->index(['security_incident_id', 'created_at'], 'secmon_notes_incident_created_idx');
            });
        }

        if (! Schema::hasTable('security_monitoring_settings')) {
            Schema::create('security_monitoring_settings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('hub_id')->nullable()->constrained('hubs')->nullOnDelete();
                $table->string('alert_min_severity', 20)->default('high');
                $table->boolean('alert_email_enabled')->default(false);
                $table->json('alert_emails')->nullable();
                $table->unsignedSmallInteger('failed_login_threshold')->default(5);
                $table->unsignedSmallInteger('failed_login_window_minutes')->default(15);
                $table->dateTime('last_dependency_scan_at')->nullable();
                $table->string('last_dependency_scan_status', 40)->nullable();
                $table->text('last_dependency_scan_summary')->nullable();
                $table->timestamps();

                $table->unique('hub_id', 'secmon_settings_hub_unique');
            });
        }

        if (Schema::hasTable('security_events') && Schema::hasTable('security_incidents')) {
            Schema::table('security_events', function (Blueprint $table) {
                // Soft FK only — already indexed; avoid hard FK if incident row may be remote.
            });
        }

        $matrix = app(CapabilitiesMatrixService::class);

        Hub::query()->orderBy('id')->each(function (Hub $hub) use ($matrix) {
            $checklist = $hub->resolvedChecklist();

            if (! array_key_exists('security_monitoring', $checklist)) {
                $checklist['security_monitoring'] = false;
            }

            $roleCaps = $matrix->resolvedRoleCapabilities($hub);
            foreach (CapabilitiesMatrixService::MATRIX_ROLES as $role) {
                if (! isset($roleCaps[$role]) || ! is_array($roleCaps[$role])) {
                    $roleCaps[$role] = [];
                }
                foreach (Hub::SECURITY_MONITORING_CAPABILITY_KEYS as $key) {
                    if (! array_key_exists($key, $roleCaps[$role])) {
                        $isHubAdmin = in_array($role, [
                            \App\Models\User::ROLE_POWER_ADMIN,
                            \App\Models\User::ROLE_FINPROMS_ADMIN,
                            \App\Models\User::ROLE_CLIENT_ADMIN,
                            \App\Models\User::ROLE_MANAGER,
                        ], true);
                        $roleCaps[$role][$key] = $isHubAdmin;
                    }
                }
            }

            foreach (Hub::SECURITY_MONITORING_CAPABILITY_KEYS as $key) {
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
        Schema::dropIfExists('security_incident_notes');
        Schema::dropIfExists('security_events');
        Schema::dropIfExists('security_incidents');
        Schema::dropIfExists('security_monitoring_settings');

        Hub::query()->orderBy('id')->each(function (Hub $hub) {
            $checklist = is_array($hub->checklist) ? $hub->checklist : [];
            unset($checklist['security_monitoring']);
            foreach (Hub::SECURITY_MONITORING_CAPABILITY_KEYS as $key) {
                unset($checklist[$key]);
            }

            $roleCaps = is_array($hub->role_capabilities) ? $hub->role_capabilities : [];
            foreach ($roleCaps as $role => $caps) {
                if (! is_array($caps)) {
                    continue;
                }
                foreach (Hub::SECURITY_MONITORING_CAPABILITY_KEYS as $key) {
                    unset($roleCaps[$role][$key]);
                }
            }

            $hub->checklist = $checklist;
            $hub->role_capabilities = $roleCaps;
            $hub->save();
        });
    }
};
