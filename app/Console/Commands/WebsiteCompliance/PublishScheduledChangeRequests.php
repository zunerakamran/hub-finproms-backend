<?php

namespace App\Console\Commands\WebsiteCompliance;

use App\Services\WebsiteCompliance\ScheduledChangeRequestPublisher;
use Illuminate\Console\Command;

class PublishScheduledChangeRequests extends Command
{
    protected $signature = 'wc:publish-scheduled';

    protected $description = 'Publish Website Compliance change requests whose scheduled_at time has been reached (shared + white-labelled hub DBs)';

    public function handle(ScheduledChangeRequestPublisher $publisher): int
    {
        $result = $publisher->publishDue();

        if ($result['published'] === 0 && $result['failed'] === 0) {
            $this->info('No scheduled change requests due for publication.');
            $this->line('Scopes scanned: '.$result['hubs_scanned'].' · now '.$result['now']);

            return self::SUCCESS;
        }

        foreach ($result['results'] as $row) {
            if (($row['status'] ?? '') === 'published') {
                $synced = ! empty($row['cpanel_synced']) ? ' (cPanel synced)' : ' (hub only)';
                $this->info("Published change request #{$row['id']} [{$row['scope']}]{$synced}");
            } else {
                $this->error("Failed change request #{$row['id']} [{$row['scope']}]: ".($row['error'] ?? 'unknown'));
            }
        }

        $this->info("Done. Published: {$result['published']}, failed: {$result['failed']}, scopes scanned: {$result['hubs_scanned']}.");

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
