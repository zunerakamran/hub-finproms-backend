<?php

namespace App\Jobs\WebsiteCompliance;

use App\Models\Hub;
use App\Models\WebsiteCompliance\TemplateRequest;
use App\Services\WebsiteCompliance\CpanelSyncService;
use App\Services\WhiteLabelDatabaseService;
use App\Support\WebsiteCompliance\WcDatabaseContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pushes deployment/section updates to a template-request cPanel site off the HTTP thread.
 */
class SyncTemplateRequestCpanelJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly int $templateRequestId,
        public readonly array $sectionsUpdated = [],
        public readonly ?int $hubId = null,
    ) {}

    public function handle(WhiteLabelDatabaseService $remoteDb): void
    {
        $run = function (): void {
            $templateRequest = TemplateRequest::query()->find($this->templateRequestId);
            if (! $templateRequest) {
                Log::info('wc cpanel sync: template request missing', [
                    'template_request_id' => $this->templateRequestId,
                    'hub_id' => $this->hubId,
                ]);

                throw new \RuntimeException(
                    'cPanel sync: template request #'.$this->templateRequestId.' not found.'
                );
            }

            $ok = CpanelSyncService::pushToTemplateRequestCpanel($templateRequest, $this->sectionsUpdated);
            if (! $ok) {
                throw new \RuntimeException(
                    'cPanel push failed for template request #'.$this->templateRequestId
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
                        'template_request_id' => $this->templateRequestId,
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
            Log::error('wc cpanel sync: template-request push failed', [
                'hub_id' => $this->hubId,
                'template_request_id' => $this->templateRequestId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
