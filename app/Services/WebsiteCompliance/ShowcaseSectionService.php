<?php

namespace App\Services\WebsiteCompliance;

use App\Models\WebsiteCompliance\Page;
use App\Models\WebsiteCompliance\Section;
use App\Models\WebsiteCompliance\Template;
use App\Support\WebsiteCompliance\TemplateDefaultContent;
use Illuminate\Support\Facades\Schema;

/**
 * Keeps showcase rows (advisor_id IS NULL) aligned with on-disk template JSON.
 * Used by WebsiteComplianceTemplateSeeder and `php artisan wc:seed-showcase-sections`.
 */
class ShowcaseSectionService
{
    /**
     * Sync template4 (or other slug) dummy JSON → wc_templates.dummy_content + wc_sections showcase rows.
     *
     * @return array{template_id:int|null,created:int,updated:int,skipped:int,sections:int}
     */
    public static function syncFromDefaults(string $slug = 'template4', bool $overwrite = true): array
    {
        $stats = [
            'template_id' => null,
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'sections' => 0,
        ];

        if (! Schema::hasTable('wc_templates') || ! Schema::hasTable('wc_sections') || ! Schema::hasTable('wc_pages')) {
            return $stats;
        }

        $safeSlug = preg_replace('/[^a-z0-9_-]/i', '', $slug) ?: 'template4';
        $defaults = TemplateDefaultContent::load($safeSlug);
        if (empty($defaults)) {
            return $stats;
        }

        $dummyJson = (string) file_get_contents(TemplateDefaultContent::pathForSlug($safeSlug));

        $template = Template::firstOrCreate(
            ['slug' => $safeSlug],
            [
                'name' => $safeSlug === 'template4'
                    ? 'Template 4 (Complete Financial Centre)'
                    : ucfirst(str_replace(['-', '_'], ' ', $safeSlug)),
                'description' => 'Website Compliance template seeded from '.$safeSlug.'-dummy-content.json',
                'preview_url' => rtrim((string) config('services.website_compliance.template_preview_base_url', 'https://sharedhub.fin-proms.com'), '/').'/'.$safeSlug.'/',
                'is_active' => true,
                'dummy_content' => $dummyJson,
            ]
        );

        $template->update([
            'dummy_content' => $dummyJson,
            'preview_url' => $template->preview_url
                ?: rtrim((string) config('services.website_compliance.template_preview_base_url', 'https://sharedhub.fin-proms.com'), '/').'/'.$safeSlug.'/',
            'is_active' => true,
        ]);

        $stats['template_id'] = (int) $template->id;

        $homePage = Page::firstOrCreate(
            ['slug' => 'home'],
            ['title' => 'Home Page', 'template_id' => $template->id]
        );
        if ((int) $homePage->template_id !== (int) $template->id) {
            $homePage->update(['template_id' => $template->id]);
        }

        foreach ($defaults as $name => $content) {
            if (! is_string($name) || $name === '') {
                continue;
            }

            $stats['sections']++;

            $section = Section::where('page_id', $homePage->id)
                ->where('name', $name)
                ->whereNull('advisor_id')
                ->whereNull('template_request_id')
                ->first();

            $payload = [
                'content' => TemplateDefaultContent::encode($content),
                'template_id' => $template->id,
                'section_key' => strtolower((string) preg_replace('/[^a-z0-9]+/i', '', $name)),
                'display_name' => $name,
                'is_visible' => true,
            ];

            if (! $section) {
                Section::create(array_merge($payload, [
                    'page_id' => $homePage->id,
                    'name' => $name,
                    'advisor_id' => null,
                    'template_request_id' => null,
                ]));
                $stats['created']++;

                continue;
            }

            $shouldOverwrite = $overwrite || self::contentIsEmpty($section->content);
            if (! $shouldOverwrite) {
                $stats['skipped']++;

                continue;
            }

            $section->update($payload);
            $stats['updated']++;
        }

        return $stats;
    }

    public static function contentIsEmpty(mixed $content): bool
    {
        if ($content === null || $content === '') {
            return true;
        }

        if (is_array($content)) {
            return $content === [];
        }

        if (! is_string($content)) {
            return false;
        }

        $trimmed = trim($content);
        if ($trimmed === '' || $trimmed === '[]' || $trimmed === '{}' || $trimmed === 'null') {
            return true;
        }

        $decoded = json_decode($trimmed, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded) && $decoded === []) {
            return true;
        }

        return false;
    }
}
