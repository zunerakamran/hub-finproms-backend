<?php

use App\Models\Hub;
use App\Models\User;
use App\Services\CapabilitiesMatrixService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Automatic system error reports (unreported bugs) + st_view_system_errors.
 *
 * Distinct from human Support Tickets: frontend/backend exceptions are
 * fingerprint-deduped into this feed so staff can see issues nobody filed.
 */
return new class extends Migration
{
    private const KEY = 'st_view_system_errors';

    public function up(): void
    {
        if (! Schema::hasTable('system_error_reports')) {
            Schema::create('system_error_reports', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('hub_id')->nullable()->index();
                $table->string('fingerprint', 64)->index();
                $table->string('source', 20); // frontend | backend
                $table->string('level', 20)->default('error');
                $table->string('message', 1000);
                $table->string('exception_class', 255)->nullable();
                $table->text('stack_trace')->nullable();
                $table->string('page_url', 500)->nullable();
                $table->string('route', 255)->nullable();
                $table->string('http_method', 20)->nullable();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('user_name')->nullable();
                $table->string('user_email')->nullable();
                $table->string('browser_info', 500)->nullable();
                $table->string('environment', 40)->nullable();
                $table->unsignedInteger('occurrence_count')->default(1);
                $table->timestamp('first_seen_at');
                $table->timestamp('last_seen_at');
                $table->string('status', 40)->default('open');
                $table->unsignedBigInteger('resolved_by_user_id')->nullable();
                $table->string('resolved_by_name')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->text('staff_note')->nullable();
                $table->json('properties')->nullable();
                $table->timestamps();

                $table->index(['fingerprint', 'status'], 'syserr_fp_status_idx');
                $table->index(['status', 'last_seen_at'], 'syserr_status_seen_idx');
                $table->index(['source', 'last_seen_at'], 'syserr_source_seen_idx');
            });
        }

        $matrix = app(CapabilitiesMatrixService::class);

        Hub::query()->orderBy('id')->each(function (Hub $hub) use ($matrix) {
            $roleCaps = $matrix->resolvedRoleCapabilities($hub);

            foreach (array_keys($roleCaps) as $role) {
                if (! is_array($roleCaps[$role] ?? null)) {
                    $roleCaps[$role] = [];
                }
                if (array_key_exists(self::KEY, $roleCaps[$role])) {
                    continue;
                }
                // Same staff who see / reply to tickets, plus Top Management.
                $roleCaps[$role][self::KEY] = in_array($role, [
                    User::ROLE_MANAGER,
                    User::ROLE_TOP_MANAGEMENT,
                    User::ROLE_CLIENT_ADMIN,
                    User::ROLE_POWER_ADMIN,
                    User::ROLE_FINPROMS_ADMIN,
                ], true);
            }

            $any = false;
            foreach ($roleCaps as $caps) {
                if (! empty($caps[self::KEY])) {
                    $any = true;
                    break;
                }
            }

            $checklist = $hub->resolvedChecklist();
            $checklist[self::KEY] = $any;

            $hub->forceFill([
                'role_capabilities' => $roleCaps,
                'checklist' => $checklist,
            ])->save();
        });

        $matrix->forgetResolvedCaches();
    }

    public function down(): void
    {
        Schema::dropIfExists('system_error_reports');

        Hub::query()->orderBy('id')->each(function (Hub $hub) {
            $roleCaps = is_array($hub->role_capabilities) ? $hub->role_capabilities : [];
            foreach ($roleCaps as $role => $caps) {
                if (! is_array($caps)) {
                    continue;
                }
                unset($caps[self::KEY]);
                $roleCaps[$role] = $caps;
            }

            $checklist = is_array($hub->checklist) ? $hub->checklist : [];
            unset($checklist[self::KEY]);

            $hub->forceFill([
                'role_capabilities' => $roleCaps,
                'checklist' => $checklist,
            ])->save();
        });

        app(CapabilitiesMatrixService::class)->forgetResolvedCaches();
    }
};
