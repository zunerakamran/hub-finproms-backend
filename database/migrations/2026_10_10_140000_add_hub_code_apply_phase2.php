<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hub_releases', function (Blueprint $table) {
            if (! Schema::hasColumn('hub_releases', 'backend_artifact_path')) {
                $table->string('backend_artifact_path')->nullable()->after('notes');
            }
            if (! Schema::hasColumn('hub_releases', 'frontend_artifact_path')) {
                $table->string('frontend_artifact_path')->nullable()->after('backend_artifact_path');
            }
        });

        Schema::table('hubs', function (Blueprint $table) {
            if (! Schema::hasColumn('hubs', 'code_apply_status')) {
                $table->string('code_apply_status', 32)->nullable();
            }
            if (! Schema::hasColumn('hubs', 'code_target_version')) {
                $table->string('code_target_version', 64)->nullable();
            }
            if (! Schema::hasColumn('hubs', 'code_applied_at')) {
                $table->timestamp('code_applied_at')->nullable();
            }
            if (! Schema::hasColumn('hubs', 'code_apply_error')) {
                $table->text('code_apply_error')->nullable();
            }
            if (! Schema::hasColumn('hubs', 'code_update_token')) {
                $table->text('code_update_token')->nullable();
            }
            if (! Schema::hasColumn('hubs', 'code_frontend_path')) {
                $table->string('code_frontend_path', 1024)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('hub_releases', function (Blueprint $table) {
            $drop = array_values(array_filter(
                ['backend_artifact_path', 'frontend_artifact_path'],
                fn (string $col) => Schema::hasColumn('hub_releases', $col)
            ));
            if ($drop !== []) {
                $table->dropColumn($drop);
            }
        });

        Schema::table('hubs', function (Blueprint $table) {
            $drop = array_values(array_filter(
                [
                    'code_apply_status',
                    'code_target_version',
                    'code_applied_at',
                    'code_apply_error',
                    'code_update_token',
                    'code_frontend_path',
                ],
                fn (string $col) => Schema::hasColumn('hubs', $col)
            ));
            if ($drop !== []) {
                $table->dropColumn($drop);
            }
        });
    }
};
