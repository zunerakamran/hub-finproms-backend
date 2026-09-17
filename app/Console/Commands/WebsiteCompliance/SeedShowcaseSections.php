<?php

namespace App\Console\Commands\WebsiteCompliance;

use App\Services\WebsiteCompliance\ShowcaseSectionService;
use App\Support\WebsiteCompliance\HubTemplateCatalog;
use Illuminate\Console\Command;

class SeedShowcaseSections extends Command
{
    protected $signature = 'wc:seed-showcase-sections
                            {slug? : Template slug / dummy JSON basename (defaults to this hub\'s first allowed template)}
                            {--no-overwrite : Only create missing showcase rows; keep existing content}';

    protected $description = 'Sync showcase wc_sections (advisor_id NULL) + wc_templates.dummy_content from database/data/website-compliance/{slug}-dummy-content.json';

    public function handle(): int
    {
        $slugArg = $this->argument('slug');
        $slug = is_string($slugArg) && $slugArg !== ''
            ? $slugArg
            : (HubTemplateCatalog::defaultSlug() ?? '');

        if ($slug === '') {
            $this->error('No showcase template is configured for hub ['.HubTemplateCatalog::currentHubSlug().'].');
            $this->line('Set hub_showcase_templates in config/services.php or WC_SHOWCASE_TEMPLATES in .env.');

            return self::FAILURE;
        }

        $allowedLabel = HubTemplateCatalog::allowedSlugs() === []
            ? '(none)'
            : implode(', ', HubTemplateCatalog::allowedSlugs());

        if (! HubTemplateCatalog::allows($slug)) {
            $this->error("Template [{$slug}] is not owned by hub [".HubTemplateCatalog::currentHubSlug().'].');
            $this->line("Allowed on this hub: {$allowedLabel}");
            $this->line('Seed it on the owning hub deploy instead (e.g. template4 → myhub).');

            return self::FAILURE;
        }

        $overwrite = ! $this->option('no-overwrite');
        $stats = ShowcaseSectionService::syncFromDefaults($slug, $overwrite);

        if ($stats['refused'] ?? false) {
            $this->error("Refused to seed [{$slug}] on hub [".HubTemplateCatalog::currentHubSlug().'].');

            return self::FAILURE;
        }

        if (! $stats['template_id']) {
            $this->error("No dummy JSON found or WC tables missing for slug [{$slug}].");
            $this->line('Expected file: database/data/website-compliance/'.$slug.'-dummy-content.json');

            return self::FAILURE;
        }

        $this->info("Template #{$stats['template_id']} ({$slug}) on hub [".HubTemplateCatalog::currentHubSlug().']');
        $this->line("  sections in JSON : {$stats['sections']}");
        $this->line("  created          : {$stats['created']}");
        $this->line("  updated          : {$stats['updated']}");
        $this->line("  skipped          : {$stats['skipped']}");
        $this->comment($overwrite
            ? 'Showcase rows were overwritten from dummy JSON (default).'
            : 'Existing non-empty showcase rows were kept (--no-overwrite).');

        return self::SUCCESS;
    }
}
