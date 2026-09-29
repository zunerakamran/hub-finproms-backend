<?php

namespace App\Services\WebsiteCompliance;

use App\Models\WebsiteCompliance\Page;
use App\Models\WebsiteCompliance\Section;
use App\Models\WebsiteCompliance\Template;
use App\Models\WebsiteCompliance\TemplateRequest;
use App\Support\WebsiteCompliance\HubTemplateCatalog;
use App\Support\WebsiteCompliance\TemplateDefaultContent;
use App\Support\WebsiteCompliance\WcDatabaseContext;
use Illuminate\Support\Facades\Schema;

/**
 * Keeps showcase rows (advisor_id IS NULL) aligned with on-disk template JSON.
 * Used by WebsiteComplianceTemplateSeeder and `php artisan wc:seed-showcase-sections`.
 */
class ShowcaseSectionService
{
    /**
     * Sync template dummy JSON → wc_templates.dummy_content + wc_sections showcase rows.
     * Refuses to write templates that are not owned by the current HUB_SLUG.
     *
     * @return array{template_id:int|null,created:int,updated:int,skipped:int,sections:int,refused:bool}
     */
    public static function syncFromDefaults(string $slug = 'template4', bool $overwrite = true): array
    {
        $stats = [
            'template_id' => null,
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'sections' => 0,
            'refused' => false,
        ];

        if (! self::schema()->hasTable('wc_templates')
            || ! self::schema()->hasTable('wc_sections')
            || ! self::schema()->hasTable('wc_pages')) {
            return $stats;
        }

        $safeSlug = HubTemplateCatalog::sanitizeSlug($slug) ?: 'template4';

        if (! HubTemplateCatalog::allows($safeSlug)) {
            $stats['refused'] = true;

            return $stats;
        }

        $defaults = TemplateDefaultContent::load($safeSlug);
        if (empty($defaults)) {
            return $stats;
        }

        $dummyJson = (string) file_get_contents(TemplateDefaultContent::pathForSlug($safeSlug));
        $previewUrl = HubTemplateCatalog::previewUrlFor($safeSlug);

        $template = Template::firstOrCreate(
            ['slug' => $safeSlug],
            [
                'name' => $safeSlug === 'template4'
                    ? 'Template 4 (Complete Financial Centre)'
                    : ucfirst(str_replace(['-', '_'], ' ', $safeSlug)),
                'description' => 'Website Compliance template seeded from '.$safeSlug.'-dummy-content.json',
                'preview_url' => $previewUrl,
                'is_active' => true,
                'dummy_content' => $dummyJson,
            ]
        );

        $template->update([
            'dummy_content' => $dummyJson,
            'preview_url' => $previewUrl,
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

    /**
     * Remove wc_templates (and related showcase/advisor sections) that do not
     * belong to the current hub. Keeps only HubTemplateCatalog::allowedSlugs().
     *
     * @return array{deleted_templates:list<string>,deleted_sections:int,deleted_requests:int}
     */
    public static function purgeForeignTemplates(): array
    {
        $result = [
            'deleted_templates' => [],
            'deleted_sections' => 0,
            'deleted_requests' => 0,
        ];

        if (! self::schema()->hasTable('wc_templates')) {
            return $result;
        }

        $allowed = HubTemplateCatalog::allowedSlugs();
        $foreign = Template::query()
            ->when(
                $allowed !== [],
                fn ($q) => $q->whereNotIn('slug', $allowed),
                fn ($q) => $q // no allowed templates on this hub → purge all catalog rows
            )
            ->get();

        foreach ($foreign as $template) {
            $slug = (string) $template->slug;

            if (self::schema()->hasTable('wc_sections')) {
                $result['deleted_sections'] += (int) Section::where('template_id', $template->id)->delete();
            }

            if (self::schema()->hasTable('wc_pages')) {
                $replacementId = $allowed !== []
                    ? Template::whereIn('slug', $allowed)->value('id')
                    : null;
                Page::where('template_id', $template->id)->update([
                    'template_id' => $replacementId,
                ]);
            }

            if (self::schema()->hasTable('wc_template_requests')) {
                $result['deleted_requests'] += (int) TemplateRequest::where('template_name', $slug)->delete();
            }

            $template->delete();
            $result['deleted_templates'][] = $slug;
        }

        return $result;
    }

    private static function schema()
    {
        $connection = WcDatabaseContext::connection();

        return $connection ? Schema::connection($connection) : Schema::connection();
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
