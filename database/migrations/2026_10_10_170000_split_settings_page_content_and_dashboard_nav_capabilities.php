<?php

use App\Models\Hub;
use App\Services\CapabilitiesMatrixService;
use Illuminate\Database\Migrations\Migration;

/**
 * Split the monolithic Settings page into three capability-gated pages:
 * general settings, website page content, and dashboard menu labels.
 *
 * Existing roles that already had Manage settings keep access to the two
 * new pages so nobody loses edit rights after the split.
 */
return new class extends Migration
{
    /** @var list<string> */
    private array $keys = [
        'dashboard_manage_page_content',
        'dashboard_manage_dashboard_nav',
    ];

    public function up(): void
    {
        $matrix = app(CapabilitiesMatrixService::class);

        Hub::query()->orderBy('id')->each(function (Hub $hub) use ($matrix) {
            $roleCaps = $matrix->resolvedRoleCapabilities($hub);
            $defaults = $matrix->defaultRoleCapabilities($hub->type);

            foreach ($roleCaps as $role => $caps) {
                if (! is_array($caps)) {
                    $roleCaps[$role] = [];
                    $caps = [];
                }

                $hadSettings = (bool) ($caps['dashboard_manage_settings'] ?? false);

                foreach ($this->keys as $key) {
                    if (array_key_exists($key, $roleCaps[$role])) {
                        continue;
                    }
                    // Preserve prior Settings access; otherwise use type defaults.
                    $roleCaps[$role][$key] = $hadSettings
                        ? true
                        : (bool) ($defaults[$role][$key] ?? false);
                }
            }

            $hub->role_capabilities = $roleCaps;

            $checklist = $hub->resolvedChecklist();
            foreach ($this->keys as $key) {
                $any = false;
                foreach ($roleCaps as $caps) {
                    if (! empty($caps[$key])) {
                        $any = true;
                        break;
                    }
                }
                $checklist[$key] = $any;
            }
            $hub->checklist = $checklist;
            $hub->save();
        });

        $matrix->forgetResolvedCaches();
    }

    public function down(): void
    {
        Hub::query()->orderBy('id')->each(function (Hub $hub) {
            $roleCaps = is_array($hub->role_capabilities) ? $hub->role_capabilities : [];
            foreach ($roleCaps as $role => $caps) {
                if (! is_array($caps)) {
                    continue;
                }
                foreach ($this->keys as $key) {
                    unset($roleCaps[$role][$key]);
                }
            }
            $checklist = is_array($hub->checklist) ? $hub->checklist : [];
            foreach ($this->keys as $key) {
                unset($checklist[$key]);
            }
            $hub->role_capabilities = $roleCaps;
            $hub->checklist = $checklist;
            $hub->save();
        });

        app(CapabilitiesMatrixService::class)->forgetResolvedCaches();
    }
};
