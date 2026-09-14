<?php

namespace App\Console\Commands\WebsiteCompliance;

use App\Models\WebsiteCompliance\ChangeRequest;
use App\Services\WebsiteCompliance\ChangeRequestPublishService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class PublishScheduledChangeRequests extends Command
{
    protected $signature = 'wc:publish-scheduled';

    protected $description = 'Publish Website Compliance change requests whose scheduled_at time has been reached';

    public function handle(): int
    {
        $due = ChangeRequest::with('section')
            ->where('status', 'scheduled')
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->orderBy('scheduled_at')
            ->get();

        if ($due->isNotEmpty()) {
            Log::info('wc:publish-scheduled: due change requests found', [
                'due_count' => $due->count(),
                'now' => now()->toIso8601String(),
            ]);
        }

        if ($due->isEmpty()) {
            $this->info('No scheduled change requests due for publication.');

            return self::SUCCESS;
        }

        $published = 0;
        $failed = 0;

        foreach ($due as $changeRequest) {
            try {
                $result = ChangeRequestPublishService::publish(
                    $changeRequest,
                    $changeRequest->approver_id
                );

                $published++;
                Log::info('wc:publish-scheduled: published change request', [
                    'change_request_id' => $changeRequest->id,
                    'cpanel_synced' => $result['cpanel_synced'] ?? null,
                ]);
                $this->info(
                    "Published change request #{$changeRequest->id}"
                    .($result['cpanel_synced'] ? ' (cPanel synced)' : ' (hub only)')
                );
            } catch (\Throwable $e) {
                $failed++;
                Log::error("Failed to publish scheduled change request #{$changeRequest->id}: ".$e->getMessage(), [
                    'exception' => $e,
                ]);
                $this->error("Failed change request #{$changeRequest->id}: {$e->getMessage()}");
            }
        }

        $this->info("Done. Published: {$published}, failed: {$failed}.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
