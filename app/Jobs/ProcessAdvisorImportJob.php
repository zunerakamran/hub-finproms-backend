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

            $prior = Cache::get($cacheKey);
            $submissionBatchId = is_array($prior) ? (int) ($prior['submission_batch_id'] ?? 0) : 0;

            Cache::put($cacheKey, [
                'status' => 'processing',
                'user_id' => $this->actorUserId,
                'message' => 'Import is processing…',
                'submission_batch_id' => $submissionBatchId ?: null,
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

                    if ($submissionBatchId > 0) {
                        $this->finalizeSubmission($history, $submissionBatchId, $hub->id, $actor, $payload);
                    }
                } catch (Throwable $e) {
                    report($e);
                }
            }

            Cache::put($cacheKey, [
                'status' => 'completed',
                'user_id' => $this->actorUserId,
                'submission_batch_id' => $submissionBatchId ?: null,
                ...$payload,
            ], now()->addHour());
        } catch (InvalidArgumentException|RuntimeException $e) {
            $prior = Cache::get(self::cacheKey($this->jobId));
            $submissionBatchId = is_array($prior) ? (int) ($prior['submission_batch_id'] ?? 0) : 0;
            Cache::put(self::cacheKey($this->jobId), [
                'status' => 'failed',
                'user_id' => $this->actorUserId,
                'message' => $e->getMessage(),
                'submission_batch_id' => $submissionBatchId ?: null,
            ], now()->addHour());
            $this->markSubmissionFailedById($submissionBatchId, $e->getMessage());
        } catch (Throwable $e) {
            report($e);
            Log::error('advisor import job failed', [
                'job_id' => $this->jobId,
                'error' => $e->getMessage(),
            ]);
            $prior = Cache::get(self::cacheKey($this->jobId));
            $submissionBatchId = is_array($prior) ? (int) ($prior['submission_batch_id'] ?? 0) : 0;
            Cache::put(self::cacheKey($this->jobId), [
                'status' => 'failed',
                'user_id' => $this->actorUserId,
                'message' => 'Import failed unexpectedly. Check server logs.',
                'submission_batch_id' => $submissionBatchId ?: null,
            ], now()->addHour());
            $this->markSubmissionFailedById($submissionBatchId, 'Import failed unexpectedly.');
        } finally {
            try {
                Storage::disk('local')->delete($this->storedPath);
            } catch (Throwable) {
                // ignore cleanup failures
            }
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function finalizeSubmission(
        AdvisorImportHistoryService $history,
        int $submissionBatchId,
        int $hubId,
        User $actor,
        array $payload
    ): void {
        $submission = AdvisorImportBatch::query()
            ->where('hub_id', $hubId)
            ->where('id', $submissionBatchId)
            ->first();

        if (! $submission || ! $submission->isPendingSubmission()) {
            return;
        }

        $history->deleteStoredFile($submission->stored_path);

        $created = (int) ($payload['summary']['created'] ?? 0);
        $updated = (int) ($payload['summary']['updated'] ?? 0);
        $skipped = (int) ($payload['summary']['skipped'] ?? 0);

        $submission->status = AdvisorImportBatch::STATUS_COMPLETED;
        $submission->stored_path = null;
        $submission->message = sprintf(
            'Submitted sheet imported by %s (%s): %d created, %d updated, %d skipped.',
            $actor->name,
            $actor->email,
            $created,
            $updated,
            $skipped
        );
        $submission->save();
    }

    private function markSubmissionFailedById(int $submissionBatchId, string $reason): void
    {
        if ($submissionBatchId < 1) {
            return;
        }

        $submission = AdvisorImportBatch::query()->find($submissionBatchId);
        if (! $submission || ! $submission->isPendingSubmission()) {
            return;
        }

        $submission->message = 'Import of submitted sheet failed: '.$reason.' Sheet remains pending — try again.';
        $submission->save();
    }

    public static function cacheKey(string $jobId): string
    {
        return 'advisor_import:'.$jobId;
    }
}
