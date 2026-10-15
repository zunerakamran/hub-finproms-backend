<?php

namespace App\Console\Commands;

use App\Models\Hub;
use App\Services\ActivityAnomalyService;
use Illuminate\Console\Command;

class DetectActivityAnomaliesCommand extends Command
{
    protected $signature = 'activity:detect-anomalies
        {--hub= : Optional hub id or slug; default = all active hubs}';

    protected $description = 'Scan activity_logs for unusual patterns and write activity_anomalies rows (section 6A.2)';

    public function handle(ActivityAnomalyService $anomalies): int
    {
        if (! $anomalies->schemaReady()) {
            $this->warn('activity_anomalies table missing — run migrations first.');

            return self::FAILURE;
        }

        $hubOpt = $this->option('hub');
        $hubs = Hub::query()
            ->when($hubOpt, function ($q) use ($hubOpt) {
                if (ctype_digit((string) $hubOpt)) {
                    $q->where('id', (int) $hubOpt);
                } else {
                    $q->where('slug', (string) $hubOpt);
                }
            })
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        if ($hubs->isEmpty()) {
            $this->warn('No matching active hubs.');

            return self::SUCCESS;
        }

        $totalCreated = 0;
        foreach ($hubs as $hub) {
            $result = $anomalies->detectForHub($hub);
            $totalCreated += $result['created'];
            $this->line(sprintf(
                'Hub %s (#%d): created %d%s',
                $hub->slug,
                $hub->id,
                $result['created'],
                $result['by_type'] ? ' ['.collect($result['by_type'])->map(fn ($n, $t) => $t.'='.$n)->implode(', ').']' : ''
            ));
        }

        $this->info('Done. Total anomalies created: '.$totalCreated);

        return self::SUCCESS;
    }
}
