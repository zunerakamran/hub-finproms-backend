<?php

use App\Models\Hub;
use App\Services\CapabilitiesMatrixService;
use App\Support\TermsAndConditionsDefaults;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hubs', function (Blueprint $table) {
            $table->json('terms_and_conditions')->nullable()->after('email_templates');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('terms_accepted_at')->nullable()->after('email_verified_at');
            $table->unsignedInteger('terms_accepted_version')->nullable()->after('terms_accepted_at');
        });

        $matrix = app(CapabilitiesMatrixService::class);
        $key = 'dashboard_manage_terms';

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

            if (! is_array($hub->terms_and_conditions) || empty($hub->terms_and_conditions['content'])) {
                $hub->terms_and_conditions = [
                    'content' => TermsAndConditionsDefaults::forType((string) $hub->type),
                    'version' => 1,
                    'updated_at' => now()->toIso8601String(),
                ];
            }

            $hub->save();
        });
    }

    public function down(): void
    {
        Hub::query()->orderBy('id')->each(function (Hub $hub) {
            $roleCaps = is_array($hub->role_capabilities) ? $hub->role_capabilities : [];
            foreach ($roleCaps as $role => $caps) {
                if (is_array($caps)) {
                    unset($roleCaps[$role]['dashboard_manage_terms']);
                }
            }
            $checklist = is_array($hub->checklist) ? $hub->checklist : [];
            unset($checklist['dashboard_manage_terms']);
            $hub->role_capabilities = $roleCaps;
            $hub->checklist = $checklist;
            $hub->terms_and_conditions = null;
            $hub->save();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['terms_accepted_at', 'terms_accepted_version']);
        });

        Schema::table('hubs', function (Blueprint $table) {
            $table->dropColumn('terms_and_conditions');
        });
    }
};
