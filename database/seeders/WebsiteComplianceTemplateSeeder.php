<?php

namespace Database\Seeders;

use App\Models\Hub;
use App\Models\User;
use App\Services\CapabilitiesMatrixService;
use App\Services\WebsiteCompliance\ShowcaseSectionService;
use App\Support\WebsiteCompliance\HubTemplateCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

class WebsiteComplianceTemplateSeeder extends Seeder
{
    public function run(): void
    {
        if (! Schema::hasTable('wc_templates')) {
            return;
        }

        // Drop templates that belong to other hubs (e.g. template4 on shared).
        $purged = ShowcaseSectionService::purgeForeignTemplates();
        if ($purged['deleted_templates'] !== [] && $this->command) {
            $this->command->warn(
                'Purged foreign WC templates for hub ['.HubTemplateCatalog::currentHubSlug().']: '
                .implode(', ', $purged['deleted_templates'])
            );
        }

        $allowed = HubTemplateCatalog::allowedSlugs();
        if ($allowed === []) {
            if ($this->command) {
                $this->command->comment(
                    'No showcase templates configured for hub ['.HubTemplateCatalog::currentHubSlug().']; skipped seed.'
                );
            }
            $this->enableModuleWithDefaultCapabilities();

            return;
        }

        foreach ($allowed as $slug) {
            ShowcaseSectionService::syncFromDefaults($slug, true);
        }

        $this->enableModuleWithDefaultCapabilities();
    }

    /**
     * Turn on Website Compliance + sensible role defaults (mirrors former content-flow grants).
     */
    private function enableModuleWithDefaultCapabilities(): void
    {
        if (! Schema::hasTable('hubs')) {
            return;
        }

        $defaultsByRole = [
            User::ROLE_POWER_ADMIN => Hub::WEBSITE_COMPLIANCE_CAPABILITY_KEYS,
            User::ROLE_FINPROMS_ADMIN => [
                'wc_view_all_change_requests',
                'wc_assign_change_requests',
                'wc_assign_website_templates',
                'wc_request_deployments',
                'wc_view_all_deployments',
                'wc_view_activity_logs',
                'wc_view_platform_report',
            ],
            User::ROLE_CLIENT_ADMIN => [
                'wc_view_activity_logs',
                'wc_view_platform_report',
            ],
            User::ROLE_MANAGER => [
                'wc_assign_change_requests',
                'wc_assign_website_templates',
                'wc_view_all_change_requests',
                'wc_request_deployments',
                'wc_view_all_deployments',
                'wc_view_activity_logs',
                'wc_view_platform_report',
            ],
            User::ROLE_APPROVER => [
                // Review only — history/queue stay scoped to requests this approver picked.
                // Hub-wide visibility requires wc_view_all_change_requests (managers / admins).
                'wc_review_change_requests',
            ],
            User::ROLE_ADVISOR => [
                'wc_edit_sections',
                'wc_submit_change_requests',
                'wc_request_deployments',
            ],
            User::ROLE_USER => [],
        ];

        Hub::query()->orderBy('id')->each(function (Hub $hub) use ($defaultsByRole) {
            $checklist = $hub->resolvedChecklist();
            $checklist['module_website_compliance'] = true;

            $roleCaps = is_array($hub->role_capabilities) ? $hub->role_capabilities : [];
            foreach (CapabilitiesMatrixService::MATRIX_ROLES as $role) {
                if (! isset($roleCaps[$role]) || ! is_array($roleCaps[$role])) {
                    $roleCaps[$role] = [];
                }
                foreach (Hub::WEBSITE_COMPLIANCE_CAPABILITY_KEYS as $key) {
                    $roleCaps[$role][$key] = in_array($key, $defaultsByRole[$role] ?? [], true);
                }
            }

            foreach (Hub::WEBSITE_COMPLIANCE_CAPABILITY_KEYS as $key) {
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
}
