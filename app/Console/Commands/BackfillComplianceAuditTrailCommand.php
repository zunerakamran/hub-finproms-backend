<?php

namespace App\Console\Commands;

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

        $this->info('Done. Refresh the compliance Reports → Audit trail tab.');

        return self::SUCCESS;
    }
}
