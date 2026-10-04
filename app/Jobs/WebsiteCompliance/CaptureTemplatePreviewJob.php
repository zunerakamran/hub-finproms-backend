<?php

namespace App\Jobs\WebsiteCompliance;

use App\Models\Hub;
use App\Models\WebsiteCompliance\Template;
use App\Services\WebsiteCompliance\TemplatePreviewCaptureService;
use App\Services\WhiteLabelDatabaseService;
use App\Support\WebsiteCompliance\WcDatabaseContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs Puppeteer/Node preview capture off the HTTP thread, then stores thumbnail_url.
 */
class CaptureTemplatePreviewJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(
        public readonly int $templateId,
        public readonly string $previewUrl,
        public readonly ?int $hubId = null,
    ) {}

    public function handle(
        WhiteLabelDatabaseService $remoteDb,
        TemplatePreviewCaptureService $capture
    ): void {
        $run = function () use ($capture): void {
            try {
                $thumbnailUrl = $capture->capture($this->previewUrl);
            } catch (Throwable $e) {
                report($e);
                Log::warning('wc template preview capture failed', [
                    'template_id' => $this->templateId,
                    'preview_url' => $this->previewUrl,
                    'error' => $e->getMessage(),
                ]);

                return;
            }

            if (! $thumbnailUrl) {
                return;
            }

            Template::query()->whereKey($this->templateId)->update([
                'thumbnail_url' => $thumbnailUrl,
            ]);
        };

        if ($this->hubId) {
            $hub = Hub::query()->find($this->hubId);
            if (! $hub || ! $hub->hasRemoteDatabaseConfigured()) {
                Log::warning('wc template preview: remote hub unavailable — will retry', [
                    'hub_id' => $this->hubId,
                    'template_id' => $this->templateId,
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
    }
}
