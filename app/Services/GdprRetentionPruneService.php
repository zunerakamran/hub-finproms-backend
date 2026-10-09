<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\AdvisorImportBatch;
use App\Models\SupportTicket;
use App\Models\SupportTicketAttachment;
use App\Models\SupportTicketComment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Delete or clear expired personal-data artefacts per config/gdpr.php retention.
 * Does not touch compliance_audit_events or hub_backups.
 */
class GdprRetentionPruneService
{
    /**
     * @return array{
     *   dry_run: bool,
     *   ran_at: string,
     *   policy: array<string, int>,
     *   deleted: array<string, int>
     * }
     */
    public function prune(bool $dryRun = false): array
    {
        $policy = $this->policy();
        $deleted = [
            'activity_logs' => $this->pruneActivityLogs($policy['activity_logs_days'], $dryRun),
            'login_otp_tokens' => $this->pruneEmailKeyedTable('login_otp_tokens', $policy['login_otp_hours'], 'hours', $dryRun),
            'email_verification_tokens' => $this->pruneEmailKeyedTable('email_verification_tokens', $policy['email_verification_days'], 'days', $dryRun),
            'password_reset_tokens' => $this->pruneEmailKeyedTable('password_reset_tokens', $policy['password_reset_days'], 'days', $dryRun),
            'sessions' => $this->pruneSessions($policy['sessions_days'], $dryRun),
            'advisor_import_files' => $this->pruneAdvisorImportFiles($policy['advisor_import_files_days'], $dryRun),
            'closed_support_tickets' => $this->pruneClosedSupportTickets($policy['closed_support_tickets_days'], $dryRun),
        ];

        return [
            'dry_run' => $dryRun,
            'ran_at' => now()->toIso8601String(),
            'policy' => $policy,
            'deleted' => $deleted,
            'total_deleted' => array_sum($deleted),
        ];
    }

    /**
     * @return array<string, int>
     */
    public function policy(): array
    {
        $cfg = config('gdpr.retention', []);

        return [
            'activity_logs_days' => max(0, (int) ($cfg['activity_logs_days'] ?? 0)),
            'login_otp_hours' => max(0, (int) ($cfg['login_otp_hours'] ?? 0)),
            'email_verification_days' => max(0, (int) ($cfg['email_verification_days'] ?? 0)),
            'password_reset_days' => max(0, (int) ($cfg['password_reset_days'] ?? 0)),
            'sessions_days' => max(0, (int) ($cfg['sessions_days'] ?? 0)),
            'advisor_import_files_days' => max(0, (int) ($cfg['advisor_import_files_days'] ?? 0)),
            'closed_support_tickets_days' => max(0, (int) ($cfg['closed_support_tickets_days'] ?? 0)),
        ];
    }

    private function pruneActivityLogs(int $days, bool $dryRun): int
    {
        if ($days <= 0 || ! Schema::hasTable('activity_logs')) {
            return 0;
        }

        $before = now()->subDays($days);
        $query = ActivityLog::query()->where('created_at', '<', $before);
        $count = (int) $query->count();
        if (! $dryRun && $count > 0) {
            $query->delete();
        }

        return $count;
    }

    private function pruneEmailKeyedTable(string $table, int $amount, string $unit, bool $dryRun): int
    {
        if ($amount <= 0 || ! Schema::hasTable($table)) {
            return 0;
        }

        if (! Schema::hasColumn($table, 'created_at')) {
            return 0;
        }

        $before = $unit === 'hours'
            ? now()->subHours($amount)
            : now()->subDays($amount);

        $query = DB::table($table)->where('created_at', '<', $before);
        $count = (int) $query->count();
        if (! $dryRun && $count > 0) {
            $query->delete();
        }

        return $count;
    }

    private function pruneSessions(int $days, bool $dryRun): int
    {
        if ($days <= 0 || ! Schema::hasTable('sessions')) {
            return 0;
        }

        $cutoff = now()->subDays($days)->getTimestamp();
        $query = DB::table('sessions')->where('last_activity', '<', $cutoff);
        $count = (int) $query->count();
        if (! $dryRun && $count > 0) {
            $query->delete();
        }

        return $count;
    }

    private function pruneAdvisorImportFiles(int $days, bool $dryRun): int
    {
        if ($days <= 0 || ! Schema::hasTable('advisor_import_batches')) {
            return 0;
        }

        $before = now()->subDays($days);
        $batches = AdvisorImportBatch::query()
            ->whereNotNull('stored_path')
            ->where('stored_path', '!=', '')
            ->where('created_at', '<', $before)
            // Keep files for pending submissions still awaiting import.
            ->where(function ($q) {
                $q->where('status', '!=', AdvisorImportBatch::STATUS_PENDING)
                    ->orWhere('kind', '!=', AdvisorImportBatch::KIND_SUBMISSION);
            })
            ->get();

        $count = 0;
        foreach ($batches as $batch) {
            $path = (string) $batch->stored_path;
            if ($path === '') {
                continue;
            }
            $count++;
            if ($dryRun) {
                continue;
            }
            try {
                if (Storage::disk('local')->exists($path)) {
                    Storage::disk('local')->delete($path);
                }
            } catch (\Throwable) {
                //
            }
            $batch->stored_path = null;
            $batch->save();
        }

        return $count;
    }

    private function pruneClosedSupportTickets(int $days, bool $dryRun): int
    {
        if ($days <= 0 || ! Schema::hasTable('support_tickets')) {
            return 0;
        }

        $before = now()->subDays($days);
        $query = SupportTicket::query()
            ->whereIn('status', [
                SupportTicket::STATUS_CLOSED,
                SupportTicket::STATUS_RESOLVED,
            ])
            ->where(function ($q) use ($before) {
                $q->where(function ($inner) use ($before) {
                    $inner->whereNotNull('closed_at')->where('closed_at', '<', $before);
                })->orWhere(function ($inner) use ($before) {
                    $inner->whereNull('closed_at')
                        ->whereNotNull('resolved_at')
                        ->where('resolved_at', '<', $before);
                })->orWhere(function ($inner) use ($before) {
                    $inner->whereNull('closed_at')
                        ->whereNull('resolved_at')
                        ->where('updated_at', '<', $before);
                });
            });

        $tickets = $query->get(['id']);
        $count = $tickets->count();
        if ($dryRun || $count === 0) {
            return $count;
        }

        $ids = $tickets->pluck('id')->all();

        if (Schema::hasTable('support_ticket_comments')) {
            SupportTicketComment::query()->whereIn('ticket_id', $ids)->delete();
        }

        if (Schema::hasTable('support_ticket_attachments')) {
            $attachments = SupportTicketAttachment::query()->whereIn('ticket_id', $ids)->get();
            foreach ($attachments as $attachment) {
                $path = $attachment->file_path ?? null;
                if (is_string($path) && $path !== '') {
                    try {
                        if (Storage::disk('public')->exists($path)) {
                            Storage::disk('public')->delete($path);
                        } elseif (Storage::disk('local')->exists($path)) {
                            Storage::disk('local')->delete($path);
                        }
                    } catch (\Throwable) {
                        //
                    }
                }
            }
            SupportTicketAttachment::query()->whereIn('ticket_id', $ids)->delete();
        }

        SupportTicket::query()->whereIn('id', $ids)->delete();

        return $count;
    }
}
