<?php

namespace App\Jobs;

use App\Models\AdvisorImportBatch;
use App\Models\Hub;
use App\Models\User;
use App\Services\AdvisorImportHistoryService;
use App\Services\AdvisorImportOrchestrator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class ProcessAdvisorImportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        public readonly string $jobId,
        public readonly string $storedPath,
        public readonly string $originalName,
        public readonly int $actorUserId,
        public readonly int $hubId,
        public readonly bool $useRemote,
    ) {}

    public function handle(
        AdvisorImportOrchestrator $orchestrator,
        AdvisorImportHistoryService $history
    ): void {
        $cacheKey = self::cacheKey($this->jobId);

        try {
            $hub = Hub::query()->find($this->hubId);
            $actor = User::query()->find($this->actorUserId);

            if (! $hub || ! $actor) {
                Cache::put($cacheKey, [
                    'status' => 'failed',
                    'user_id' => $this->actorUserId,
                    'message' => 'Import target hub or actor no longer exists.',
                ], now()->addHour());

                return;
            }

            if (! Storage::disk('local')->exists($this->storedPath)) {
                Cache::put($cacheKey, [
                    'status' => 'failed',
                    'user_id' => $this->actorUserId,
                    'message' => 'Uploaded import file is missing.',
                ], now()->addHour());

                return;
            }

            $absolute = Storage::disk('local')->path($this->storedPath);
            $file = new UploadedFile(
                $absolute,
                $this->originalName,
                null,
                null,
                true
            );

            Cache::put($cacheKey, [
                'status' => 'processing',
                'user_id' => $this->actorUserId,
                'message' => 'Import is processing…',
            ], now()->addHour());

            $payload = $orchestrator->run($file, $hub, $actor, $this->useRemote);

            if (empty($payload['awaiting_payment'])) {
                try {
                    $batch = $history->record(
                        $hub,
                        $actor,
                        $payload,
                        $this->originalName,
                        AdvisorImportBatch::STATUS_COMPLETED
                    );
                    $payload['import_batch_id'] = $batch->id;
                } catch (Throwable $e) {
                    report($e);
                }
            }

            Cache::put($cacheKey, [
                'status' => 'completed',
                'user_id' => $this->actorUserId,
                ...$payload,
            ], now()->addHour());
        } catch (InvalidArgumentException|RuntimeException $e) {
            Cache::put($cacheKey, [
                'status' => 'failed',
                'user_id' => $this->actorUserId,
                'message' => $e->getMessage(),
            ], now()->addHour());
        } catch (Throwable $e) {
            report($e);
            Log::error('advisor import job failed', [
                'job_id' => $this->jobId,
                'error' => $e->getMessage(),
            ]);
            Cache::put($cacheKey, [
                'status' => 'failed',
                'user_id' => $this->actorUserId,
                'message' => 'Import failed unexpectedly. Check server logs.',
            ], now()->addHour());
        } finally {
            try {
                Storage::disk('local')->delete($this->storedPath);
            } catch (Throwable) {
                // ignore cleanup failures
            }
        }
    }

    public static function cacheKey(string $jobId): string
    {
        return 'advisor_import:'.$jobId;
    }
}
