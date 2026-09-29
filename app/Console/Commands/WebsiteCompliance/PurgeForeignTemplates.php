<?php

namespace App\Console\Commands\WebsiteCompliance;

use App\Services\WebsiteCompliance\ShowcaseSectionService;
use App\Support\WebsiteCompliance\HubTemplateCatalog;
use Illuminate\Console\Command;

class PurgeForeignTemplates extends Command
{
    protected $signature = 'wc:purge-foreign-templates';

    protected $description = 'Delete wc_templates (and related sections/requests) that are not owned by the current HUB_SLUG';

    public function handle(): int
    {
        $hub = HubTemplateCatalog::currentHubSlug();
        $allowed = HubTemplateCatalog::allowedSlugs();

        $this->info("Current hub: {$hub}");
        $this->line('Allowed showcase templates: '.($allowed === [] ? '(none)' : implode(', ', $allowed)));

        $result = ShowcaseSectionService::purgeForeignTemplates();

        if ($result['deleted_templates'] === []) {
            $this->comment('Nothing to purge — catalog already matches this hub.');

            return self::SUCCESS;
        }

        $this->warn('Removed templates: '.implode(', ', $result['deleted_templates']));
        $this->line("  sections deleted : {$result['deleted_sections']}");
        $this->line("  requests deleted : {$result['deleted_requests']}");

        return self::SUCCESS;
    }
}
