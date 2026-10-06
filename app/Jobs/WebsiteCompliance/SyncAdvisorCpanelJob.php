<?php

namespace App\Jobs\WebsiteCompliance;

use App\Models\Hub;
use App\Services\WebsiteCompliance\CpanelSyncService;
use App\Services\WhiteLabelDatabaseService;
use App\Support\WebsiteCompliance\WcDatabaseContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pushes approved advisor section content to the live cPanel site off the HTTP thread.
 */
class SyncAdvisorCpanelJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly mixed $advisorId,
        public readonly array $sectionsUpdated = [],
        public readonly ?int $hubId = null,
    ) {}

    public function handle(WhiteLabelDatabaseService $remoteDb): void
    {
        $run = function (): void {
            $ok = CpanelSyncService::pushToAdvisorCpanel($this->advisorId, $this->sectionsUpdated);
            if (! $ok) {
                throw new \RuntimeException(
                    'cPanel push failed for advisor '.$this->advisorId
                    .' (no domain, empty sections, or advisor site rejected the sync).'
                );
            }
        };

        try {
            if ($this->hubId) {
                $hub = Hub::query()->find($this->hubId);
                if (! $hub || ! $hub->hasRemoteDatabaseConfigured()) {
                    Log::warning('wc cpanel sync: remote hub unavailable — will retry', [
                        'hub_id' => $this->hubId,
                        'advisor_id' => $this->advisorId,
                    ]);
                    $this->release(60);

                    return;
                }

                $remoteDb->run($hub, function (string $connection) use ($run) {
                    WcDatabaseContext::using($connection, $run, $this->hubId);
                });

                return;
            }

            $run();
        } catch (Throwable $e) {
            Log::error('wc cpanel sync: advisor push failed', [
                'hub_id' => $this->hubId,
                'advisor_id' => $this->advisorId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
