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
        if (! Schema::hasTable('general_compliance_content_types')) {
            Schema::create('general_compliance_content_types', function (Blueprint $table) {
                $table->id();
                $table->string('name')->unique();
                $table->string('slug')->unique();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('general_compliance_request_versions')
            && ! Schema::hasColumn('general_compliance_request_versions', 'content_type')) {
            Schema::table('general_compliance_request_versions', function (Blueprint $table) {
                $table->string('content_type', 100)->nullable()->after('description');
                $table->index('content_type', 'gc_versions_content_type_index');
            });
        }

        $matrix = app(CapabilitiesMatrixService::class);
        $key = 'gc_manage_content_types';

        Hub::query()->orderBy('id')->each(function (Hub $hub) use ($matrix, $key) {
            $roleCaps = $matrix->resolvedRoleCapabilities($hub);
            $defaults = $matrix->defaultRoleCapabilities($hub->type);

            foreach ($roleCaps as $role => $caps) {
                if (! is_array($caps)) {
                    $roleCaps[$role] = [];
                }
                if (! array_key_exists($key, $roleCaps[$role])) {
                    $roleCaps[$role][$key] = (bool) ($defaults[$role][$key] ?? false);
                }
            }

            $hub->role_capabilities = $roleCaps;

            $checklist = $hub->resolvedChecklist();
            $any = false;
            foreach ($roleCaps as $caps) {
                if (! empty($caps[$key])) {
                    $any = true;
                    break;
                }
            }
            $checklist[$key] = $any;
            $hub->checklist = $checklist;
            $hub->save();
        });
    }

    public function down(): void
    {
        Hub::query()->orderBy('id')->each(function (Hub $hub) {
            $roleCaps = is_array($hub->role_capabilities) ? $hub->role_capabilities : [];
            foreach ($roleCaps as $role => $caps) {
                if (is_array($caps)) {
                    unset($roleCaps[$role]['gc_manage_content_types']);
                }
            }
            $checklist = is_array($hub->checklist) ? $hub->checklist : [];
            unset($checklist['gc_manage_content_types']);
            $hub->role_capabilities = $roleCaps;
            $hub->checklist = $checklist;
            $hub->save();
        });

        if (Schema::hasTable('general_compliance_request_versions')
            && Schema::hasColumn('general_compliance_request_versions', 'content_type')) {
            Schema::table('general_compliance_request_versions', function (Blueprint $table) {
                $table->dropIndex('gc_versions_content_type_index');
                $table->dropColumn('content_type');
            });
        }

        Schema::dropIfExists('general_compliance_content_types');
    }
};
