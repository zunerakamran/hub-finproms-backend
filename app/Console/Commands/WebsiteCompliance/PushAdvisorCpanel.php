<?php

namespace App\Console\Commands\WebsiteCompliance;

use App\Services\WebsiteCompliance\CpanelSyncService;
use Illuminate\Console\Command;

class PushAdvisorCpanel extends Command
{
    protected $signature = 'wc:push-cpanel {advisor_id}';

    protected $description = 'Push approved hub sections to the live advisor cPanel site';

    public function handle(): int
    {
        $advisorId = (int) $this->argument('advisor_id');
        $this->info("Pushing advisor {$advisorId} sections to cPanel...");

        $ok = CpanelSyncService::pushToAdvisorCpanel($advisorId);
        if ($ok) {
            $this->info('Live site MySQL was updated.');

            return self::SUCCESS;
        }

        $this->error('Push failed. Check storage/logs/laravel.log and that cpanel_domain is configured.');

        return self::FAILURE;
    }
}
