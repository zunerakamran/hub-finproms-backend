<?php

namespace App\Console\Commands;

use App\Models\Hub;
use App\Services\SecurityMonitoringService;
use Illuminate\Console\Command;

class SecurityMonitorCommand extends Command
{
    protected $signature = 'security:monitor
                            {--hub= : Optional hub slug to scan (default: all local hubs)}
                            {--dry-run : Show results without writing overdue events}';

    protected $description = 'On-demand security monitoring check (dependency audit). Prefer UI “Monitor now”; not scheduled daily.';

    public function handle(SecurityMonitoringService $security): int
    {
        $slug = $this->option('hub');
        $hubs = $slug
            ? Hub::query()->where('slug', $slug)->get()
            : Hub::query()->where('is_active', true)->orderBy('id')->get();

        if ($hubs->isEmpty()) {
            $this->warn('No hubs found to monitor.');

            return self::SUCCESS;
        }

        $exit = self::SUCCESS;

        foreach ($hubs as $hub) {
            $this->info('Scanning hub: '.$hub->slug.' (#'.$hub->id.')');

            if ($this->option('dry-run')) {
                $this->line('  (dry-run) would run composer audit + refresh scan status');

                continue;
            }

            $result = $security->runDependencyScan($hub);
            $this->line('  status='.$result['status'].' advisories='.$result['advisories']);
            $this->line('  '.$result['summary']);

            if ($result['status'] === 'fail' || $result['status'] === 'error') {
                $exit = self::FAILURE;
            }
        }

        return $exit;
    }
}
