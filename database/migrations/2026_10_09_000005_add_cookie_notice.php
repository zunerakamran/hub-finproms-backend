<?php

use App\Models\Hub;
use App\Services\CapabilitiesMatrixService;
use App\Support\CookieNoticeDefaults;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hubs', function (Blueprint $table) {
            if (! Schema::hasColumn('hubs', 'cookie_notice')) {
                $after = Schema::hasColumn('hubs', 'privacy_policy')
                    ? 'privacy_policy'
                    : (Schema::hasColumn('hubs', 'terms_and_conditions')
                        ? 'terms_and_conditions'
                        : 'email_templates');
                $table->json('cookie_notice')->nullable()->after($after);
            }
        });

        $matrix = app(CapabilitiesMatrixService::class);
        $key = 'dashboard_manage_cookies';

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

            if (! is_array($hub->cookie_notice) || empty($hub->cookie_notice['content'])) {
                $hub->cookie_notice = [
                    'content' => CookieNoticeDefaults::forType((string) $hub->type),
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
                    unset($roleCaps[$role]['dashboard_manage_cookies']);
                }
            }
            $checklist = is_array($hub->checklist) ? $hub->checklist : [];
            unset($checklist['dashboard_manage_cookies']);
            $hub->role_capabilities = $roleCaps;
            $hub->checklist = $checklist;
            $hub->cookie_notice = null;
            $hub->save();
        });

        Schema::table('hubs', function (Blueprint $table) {
            if (Schema::hasColumn('hubs', 'cookie_notice')) {
                $table->dropColumn('cookie_notice');
            }
        });
    }
};
