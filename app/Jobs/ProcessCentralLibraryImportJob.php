<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\PostImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class ProcessCentralLibraryImportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        public readonly string $jobId,
        public readonly string $storedPath,
        public readonly string $originalName,
        public readonly int $actorUserId,
    ) {}

    public function handle(PostImportService $imports): void
    {
        $cacheKey = self::cacheKey($this->jobId);

        try {
            $actor = User::query()->find($this->actorUserId);
            if (! $actor) {
                Cache::put($cacheKey, [
                    'status' => 'failed',
                    'user_id' => $this->actorUserId,
                    'message' => 'Import actor no longer exists.',
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

            Cache::put($cacheKey, [
                'status' => 'processing',
                'user_id' => $this->actorUserId,
                'message' => 'Import is processing…',
            ], now()->addHour());

            $absolute = Storage::disk('local')->path($this->storedPath);
            $file = new UploadedFile(
                $absolute,
                $this->originalName,
                null,
                null,
                true
            );

            $result = $imports->import($file, $actor);
            $payload = json_decode(json_encode($result), true) ?? [];

            Cache::put($cacheKey, [
                'status' => 'completed',
                'user_id' => $this->actorUserId,
                'message' => sprintf(
                    'Import finished: %d created, %d skipped, %d errors.',
                    $payload['summary']['created'] ?? 0,
                    $payload['summary']['skipped'] ?? 0,
                    $payload['summary']['errors'] ?? 0
                ),
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
            Log::error('central library import job failed', [
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
        return 'central_library_import:'.$jobId;
    }
}
