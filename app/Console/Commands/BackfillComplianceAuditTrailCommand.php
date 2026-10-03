<?php

namespace App\Console\Commands;

use App\Models\ComplianceAuditEvent;
use App\Models\Hub;
use App\Services\ComplianceAuditBackfillService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class BackfillComplianceAuditTrailCommand extends Command
{
    protected $signature = 'compliance:backfill-audit-trail
                            {--force : Re-backfill subjects that only have previous backfill rows}';

    protected $description = 'Reconstruct SMC/GC/WC compliance audit trails from activity_logs and version history';

    public function handle(ComplianceAuditBackfillService $backfill): int
    {
        if (! Schema::hasTable('compliance_audit_events')) {
            $this->error('Table compliance_audit_events is missing. Run: php artisan migrate');

            return self::FAILURE;
        }

        $this->info('Backfilling compliance audit trails from activity logs + version history...');

        $stats = $backfill->backfill((bool) $this->option('force'));

        $this->table(
            ['Metric', 'Count'],
            [
                ['Events created', $stats['created']],
                ['From activity_logs', $stats['from_activity_logs']],
                ['From versions / assignment', $stats['from_versions']],
                ['Subjects skipped (already have trail)', $stats['skipped_subjects']],
            ]
        );

        $byHub = ComplianceAuditEvent::query()
            ->selectRaw('hub_id, module, COUNT(*) as total')
            ->groupBy('hub_id', 'module')
            ->orderBy('hub_id')
            ->get();

        if ($byHub->isNotEmpty()) {
            $hubNames = Hub::query()
                ->whereIn('id', $byHub->pluck('hub_id')->filter()->all())
                ->pluck('name', 'id');

            $this->newLine();
            $this->info('Events currently stored by hub (open Reports while on / acting as that hub):');
            $this->table(
                ['Hub ID', 'Hub name', 'Module', 'Events'],
                $byHub->map(fn ($row) => [
                    $row->hub_id ?? 'null',
                    $row->hub_id ? ($hubNames[$row->hub_id] ?? 'unknown') : '(no hub_id)',
                    $row->module,
                    $row->total,
                ])->all()
            );
        }

        $this->info('Done. From Central Hub, select that content hub in the hub switcher, then open Reports → Audit trail.');

        return self::SUCCESS;
    }
}
