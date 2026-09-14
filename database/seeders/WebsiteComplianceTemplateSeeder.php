<?php

namespace Database\Seeders;

use App\Models\Hub;
use App\Models\User;
use App\Models\WebsiteCompliance\Page;
use App\Models\WebsiteCompliance\Section;
use App\Models\WebsiteCompliance\Template;
use App\Services\CapabilitiesMatrixService;
use App\Support\WebsiteCompliance\TemplateDefaultContent;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

class WebsiteComplianceTemplateSeeder extends Seeder
{
    public function run(): void
    {
        if (! Schema::hasTable('wc_templates')) {
            return;
        }

        $this->seedTemplate4();
        $this->enableModuleWithDefaultCapabilities();
    }

    private function seedTemplate4(): void
    {
        $dummyPath = database_path('data/website-compliance/template4-dummy-content.json');
        $dummy = file_exists($dummyPath)
            ? (string) file_get_contents($dummyPath)
            : json_encode(new \stdClass);

        $template = Template::firstOrCreate(
            ['slug' => 'template4'],
            [
                'name' => 'Template 4 (Complete Financial Centre)',
                'description' => 'Modern React corporate financial centre template with sections matching the template4 layout.',
                'preview_url' => 'https://epatronus.space/template4/',
                'is_active' => true,
                'dummy_content' => $dummy,
            ]
        );

        if ($dummy) {
            $template->update(['dummy_content' => $dummy]);
        }

        $homePage = Page::firstOrCreate(
            ['slug' => 'home'],
            ['title' => 'Home Page', 'template_id' => $template->id]
        );
        if (! $homePage->template_id) {
            $homePage->update(['template_id' => $template->id]);
        }

        $defaults = TemplateDefaultContent::forTemplate($template, 'template4');
        if (empty($defaults)) {
            return;
        }

        foreach ($defaults as $name => $content) {
            if (! is_string($name) || $name === '') {
                continue;
            }

            $section = Section::where('page_id', $homePage->id)
                ->where('name', $name)
                ->whereNull('advisor_id')
                ->first();

            $payload = [
                'content' => TemplateDefaultContent::encode($content),
                'template_id' => $template->id,
                'section_key' => strtolower((string) preg_replace('/[^a-z0-9]+/i', '', $name)),
                'display_name' => $name,
                'is_visible' => true,
            ];

            if ($section) {
                $section->update($payload);
            } else {
                Section::create(array_merge($payload, [
                    'page_id' => $homePage->id,
                    'name' => $name,
                    'advisor_id' => null,
                ]));
            }
        }
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
                'wc_view_all_change_requests',
                'wc_request_deployments',
                'wc_view_all_deployments',
                'wc_view_activity_logs',
                'wc_view_platform_report',
            ],
            User::ROLE_APPROVER => [
                'wc_review_change_requests',
                'wc_view_all_change_requests',
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
