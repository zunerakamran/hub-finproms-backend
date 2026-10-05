<?php

namespace App\Console\Commands;

use App\Services\HubBackupService;
use Illuminate\Console\Command;
use Throwable;

class RunDueHubBackupsCommand extends Command
{
    protected $signature = 'hubs:run-due-backups';

    protected $description = 'Create scheduled hub backups when due (local + upload to Central when configured)';

    public function handle(HubBackupService $backups): int
    {
        try {
            $created = $backups->runDueForCurrentDeploy();
        } catch (Throwable $e) {
            report($e);
            $this->error('Backup failed: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($created === []) {
            $this->info('No local backup due for this hub right now.');
        } else {
            foreach ($created as $backup) {
                $this->info(sprintf(
                    'Backup #%d (%s / %s) status=%s size=%s',
                    $backup->id,
                    $backup->hub_slug,
                    $backup->location,
                    $backup->status,
                    $backup->size_bytes ?? 0
                ));
            }
        }

        if (config('hub.is_control_plane')) {
            $remote = $backups->triggerDueRemoteHubsFromCentral();
            foreach ($remote as $line) {
                $this->info('Remote: '.$line);
            }
            if ($remote === []) {
                $this->info('No remote hub backups due.');
            }
        }

        return self::SUCCESS;
    }
}
