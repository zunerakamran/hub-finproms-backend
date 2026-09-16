<?php

namespace App\Console\Commands\WebsiteCompliance;

use App\Services\WebsiteCompliance\ShowcaseSectionService;
use Illuminate\Console\Command;

class SeedShowcaseSections extends Command
{
    protected $signature = 'wc:seed-showcase-sections
                            {slug=template4 : Template slug / dummy JSON basename}
                            {--no-overwrite : Only create missing showcase rows; keep existing content}';

    protected $description = 'Sync showcase wc_sections (advisor_id NULL) + wc_templates.dummy_content from database/data/website-compliance/{slug}-dummy-content.json';

    public function handle(): int
    {
        $slug = (string) $this->argument('slug');
        $overwrite = ! $this->option('no-overwrite');

        $stats = ShowcaseSectionService::syncFromDefaults($slug, $overwrite);

        if (! $stats['template_id']) {
            $this->error("No dummy JSON found or WC tables missing for slug [{$slug}].");
            $this->line('Expected file: database/data/website-compliance/'.$slug.'-dummy-content.json');

            return self::FAILURE;
        }

        $this->info("Template #{$stats['template_id']} ({$slug})");
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
