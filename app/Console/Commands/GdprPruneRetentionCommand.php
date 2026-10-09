<?php

namespace App\Console\Commands;

use App\Services\GdprRetentionPruneService;
use Illuminate\Console\Command;

class GdprPruneRetentionCommand extends Command
{
    protected $signature = 'gdpr:prune-retention {--dry-run : Count rows/files that would be removed without deleting}';

    protected $description = 'Prune expired personal-data artefacts (activity logs, auth tokens, sessions, import files, closed tickets) per config/gdpr.php';

    public function handle(GdprRetentionPruneService $pruner): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $result = $pruner->prune($dryRun);

        $this->info(($dryRun ? '[dry-run] ' : '').'GDPR retention prune @ '.$result['ran_at']);
        $this->table(
            ['Category', 'Removed'],
            collect($result['deleted'])->map(fn ($n, $key) => [$key, $n])->values()->all()
        );
        $this->line('Total: '.(int) $result['total_deleted']);

        return self::SUCCESS;
    }
}
