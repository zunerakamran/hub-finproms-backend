<?php

namespace App\Jobs\WebsiteCompliance;

use App\Models\Hub;
use App\Models\WebsiteCompliance\ChangeRequest;
use App\Services\WebsiteCompliance\ChangeRequestPublishService;
use App\Services\WhiteLabelDatabaseService;
use App\Support\WebsiteCompliance\WcDatabaseContext;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Publishes a single scheduled WC change request when its timer elapses.
 * Catch-up: `php artisan wc:publish-scheduled` (registered every minute).
 */
class PublishScheduledChangeRequestJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly int $changeRequestId,
        public readonly ?int $hubId,
        public readonly string $scheduledAtIso,
    ) {}

    public function uniqueId(): string
    {
        return 'wc-publish-scheduled-'
            .($this->hubId ?: 'default')
            .'-'.$this->changeRequestId
            .'-'.substr(sha1($this->scheduledAtIso), 0, 12);
    }

    public function handle(WhiteLabelDatabaseService $remoteDb): void
    {
        if ($this->hubId) {
            $hub = Hub::query()->find($this->hubId);
            if (! $hub || ! $hub->hasRemoteDatabaseConfigured()) {
                Log::warning('wc scheduled job: white-labelled hub unavailable — will retry', [
                    'hub_id' => $this->hubId,
                    'change_request_id' => $this->changeRequestId,
                ]);
                $this->release(120);

                return;
            }

            $remoteDb->run($hub, function (string $connection) {
                WcDatabaseContext::using(
                    $connection,
                    fn () => $this->publishIfStillScheduled(),
                    $this->hubId
                );
            });

            return;
        }

        $this->publishIfStillScheduled();
    }

    private function publishIfStillScheduled(): void
    {
        $changeRequest = ChangeRequest::with(['section', 'currentVersionRow'])
            ->find($this->changeRequestId);

        if (! $changeRequest) {
            Log::info('wc scheduled job: change request missing', [
                'change_request_id' => $this->changeRequestId,
                'hub_id' => $this->hubId,
            ]);

            return;
        }

        if ($changeRequest->status !== ChangeRequest::STATUS_SCHEDULED) {
            Log::info('wc scheduled job: skipped — status is no longer scheduled', [
                'change_request_id' => $changeRequest->id,
                'status' => $changeRequest->status,
            ]);

            return;
        }

        $expected = Carbon::parse($this->scheduledAtIso);
        $actual = $changeRequest->scheduled_at;
        if ($actual && abs($actual->diffInSeconds($expected)) > 60) {
            // Approver may have re-scheduled; only the matching timer should publish.
            Log::info('wc scheduled job: skipped — scheduled_at changed', [
                'change_request_id' => $changeRequest->id,
                'expected' => $expected->toIso8601String(),
                'actual' => $actual->toIso8601String(),
            ]);

            return;
        }

        // Never publish early — release until the scheduled time is reached.
        if ($actual instanceof CarbonInterface && $actual->greaterThan(now())) {
            $delaySeconds = max(5, $actual->getTimestamp() - now()->getTimestamp());
            $this->release($delaySeconds);

            return;
        }

        try {
            $result = ChangeRequestPublishService::claimAndPublishDueScheduled(
                $changeRequest,
                $changeRequest->approver_id
            );

            if ($result === null) {
                Log::info('wc scheduled job: skipped — not due or already claimed', [
                    'change_request_id' => $changeRequest->id,
                    'hub_id' => $this->hubId,
                ]);

                return;
            }

            Log::info('wc scheduled job: published', [
                'change_request_id' => $changeRequest->id,
                'hub_id' => $this->hubId,
                'cpanel_synced' => $result['cpanel_synced'] ?? false,
            ]);
        } catch (Throwable $e) {
            Log::error('wc scheduled job: publish failed', [
                'change_request_id' => $changeRequest->id,
                'hub_id' => $this->hubId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
