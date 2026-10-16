<?php

use App\Models\Hub;
use App\Models\User;
use App\Services\CapabilitiesMatrixService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('security_incidents')) {
            Schema::table('security_incidents', function (Blueprint $table) {
                if (! Schema::hasColumn('security_incidents', 'affected_module')) {
                    $table->string('affected_module', 80)->nullable()->after('category');
                }
                if (! Schema::hasColumn('security_incidents', 'disaster_recognized')) {
                    $table->boolean('disaster_recognized')->default(false)->after('affected_module');
                }
                if (! Schema::hasColumn('security_incidents', 'disaster_recognized_at')) {
                    $table->dateTime('disaster_recognized_at')->nullable()->after('disaster_recognized');
                }
                if (! Schema::hasColumn('security_incidents', 'recovery_status')) {
                    $table->string('recovery_status', 40)->default('none')->after('disaster_recognized_at');
                }
                if (! Schema::hasColumn('security_incidents', 'last_snapshot_id')) {
                    $table->unsignedBigInteger('last_snapshot_id')->nullable()->after('recovery_status');
                }
                if (! Schema::hasColumn('security_incidents', 'last_rollback_snapshot_id')) {
                    $table->unsignedBigInteger('last_rollback_snapshot_id')->nullable()->after('last_snapshot_id');
                }
                if (! Schema::hasColumn('security_incidents', 'last_rollback_at')) {
                    $table->dateTime('last_rollback_at')->nullable()->after('last_rollback_snapshot_id');
                }
            });
        }

        if (! Schema::hasTable('module_disaster_snapshots')) {
            Schema::create('module_disaster_snapshots', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('hub_id')->nullable()->index();
                $table->string('module_key', 80);
                $table->string('label', 255)->nullable();
                $table->string('trigger', 40)->default('manual');
                $table->string('disk_path', 500);
                $table->unsignedBigInteger('size_bytes')->nullable();
                $table->json('row_counts')->nullable();
                $table->boolean('module_enabled')->nullable();
                $table->unsignedBigInteger('security_incident_id')->nullable()->index();
                $table->unsignedBigInteger('created_by_user_id')->nullable();
                $table->string('created_by_name')->nullable();
                $table->timestamps();

                $table->index(['hub_id', 'module_key', 'created_at'], 'mdr_snap_hub_module_created_idx');
            });
        }

        $matrix = app(CapabilitiesMatrixService::class);

        Hub::query()->orderBy('id')->each(function (Hub $hub) use ($matrix) {
            $checklist = $hub->resolvedChecklist();
            $roleCaps = $matrix->resolvedRoleCapabilities($hub);

            foreach (CapabilitiesMatrixService::MATRIX_ROLES as $role) {
                if (! isset($roleCaps[$role]) || ! is_array($roleCaps[$role])) {
                    $roleCaps[$role] = [];
                }
                if (! array_key_exists('secmon_manage_disaster_recovery', $roleCaps[$role])) {
                    $isHubAdmin = in_array($role, [
                        User::ROLE_POWER_ADMIN,
                        User::ROLE_FINPROMS_ADMIN,
                        User::ROLE_CLIENT_ADMIN,
                        User::ROLE_MANAGER,
                    ], true);
                    $roleCaps[$role]['secmon_manage_disaster_recovery'] = $isHubAdmin;
                }
            }

            $any = false;
            foreach (CapabilitiesMatrixService::MATRIX_ROLES as $role) {
                if (! empty($roleCaps[$role]['secmon_manage_disaster_recovery'])) {
                    $any = true;
                    break;
                }
            }
            $checklist['secmon_manage_disaster_recovery'] = $any;

            $hub->checklist = $checklist;
            $hub->role_capabilities = $roleCaps;
            $hub->save();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_disaster_snapshots');

        if (Schema::hasTable('security_incidents')) {
            Schema::table('security_incidents', function (Blueprint $table) {
                foreach ([
                    'affected_module',
                    'disaster_recognized',
                    'disaster_recognized_at',
                    'recovery_status',
                    'last_snapshot_id',
                    'last_rollback_snapshot_id',
                    'last_rollback_at',
                ] as $column) {
                    if (Schema::hasColumn('security_incidents', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        Hub::query()->orderBy('id')->each(function (Hub $hub) {
            $checklist = is_array($hub->checklist) ? $hub->checklist : [];
            unset($checklist['secmon_manage_disaster_recovery']);

            $roleCaps = is_array($hub->role_capabilities) ? $hub->role_capabilities : [];
            foreach ($roleCaps as $role => $caps) {
                if (! is_array($caps)) {
                    continue;
                }
                unset($roleCaps[$role]['secmon_manage_disaster_recovery']);
            }

            $hub->checklist = $checklist;
            $hub->role_capabilities = $roleCaps;
            $hub->save();
        });
    }
};
