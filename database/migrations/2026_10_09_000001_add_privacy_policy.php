<?php

use App\Models\Hub;
use App\Services\CapabilitiesMatrixService;
use App\Support\PrivacyPolicyDefaults;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hubs', function (Blueprint $table) {
            if (! Schema::hasColumn('hubs', 'privacy_policy')) {
                $after = Schema::hasColumn('hubs', 'terms_and_conditions')
                    ? 'terms_and_conditions'
                    : 'email_templates';
                $table->json('privacy_policy')->nullable()->after($after);
            }
        });

        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'privacy_accepted_at')) {
                $after = Schema::hasColumn('users', 'terms_accepted_version')
                    ? 'terms_accepted_version'
                    : 'email_verified_at';
                $table->timestamp('privacy_accepted_at')->nullable()->after($after);
                $table->unsignedInteger('privacy_accepted_version')->nullable()->after('privacy_accepted_at');
            }
        });

        $matrix = app(CapabilitiesMatrixService::class);
        $key = 'dashboard_manage_privacy';

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

            if (! is_array($hub->privacy_policy) || empty($hub->privacy_policy['content'])) {
                $hub->privacy_policy = [
                    'content' => PrivacyPolicyDefaults::forType((string) $hub->type),
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
                    unset($roleCaps[$role]['dashboard_manage_privacy']);
                }
            }
            $checklist = is_array($hub->checklist) ? $hub->checklist : [];
            unset($checklist['dashboard_manage_privacy']);
            $hub->role_capabilities = $roleCaps;
            $hub->checklist = $checklist;
            $hub->privacy_policy = null;
            $hub->save();
        });

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'privacy_accepted_at')) {
                $table->dropColumn(['privacy_accepted_at', 'privacy_accepted_version']);
            }
        });

        Schema::table('hubs', function (Blueprint $table) {
            if (Schema::hasColumn('hubs', 'privacy_policy')) {
                $table->dropColumn('privacy_policy');
            }
        });
    }
};
