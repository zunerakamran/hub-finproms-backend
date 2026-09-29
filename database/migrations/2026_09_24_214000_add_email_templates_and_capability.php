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
        Schema::table('hubs', function (Blueprint $table) {
            $table->json('email_templates')->nullable()->after('compliance_status_display_names');
        });

        $matrix = app(CapabilitiesMatrixService::class);
        $key = 'dashboard_manage_email_templates';

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
                    unset($roleCaps[$role]['dashboard_manage_email_templates']);
                }
            }
            $checklist = is_array($hub->checklist) ? $hub->checklist : [];
            unset($checklist['dashboard_manage_email_templates']);
            $hub->role_capabilities = $roleCaps;
            $hub->checklist = $checklist;
            $hub->email_templates = null;
            $hub->save();
        });

        Schema::table('hubs', function (Blueprint $table) {
            $table->dropColumn('email_templates');
        });
    }
};
