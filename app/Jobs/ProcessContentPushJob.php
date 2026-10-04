<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\ContentPushService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Distributes Central library posts to remote hubs off the HTTP thread.
 */
class ProcessContentPushJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    /**
     * @param  list<int>  $postIds
     * @param  list<int>  $hubIds
     */
    public function __construct(
        public readonly string $jobId,
        public readonly array $postIds,
        public readonly array $hubIds,
        public readonly ?int $actorUserId,
    ) {}

    public function handle(ContentPushService $pushes): void
    {
        $cacheKey = self::cacheKey($this->jobId);

        try {
            Cache::put($cacheKey, [
                'status' => 'processing',
                'user_id' => $this->actorUserId,
                'message' => 'Distributing content to hubs…',
            ], now()->addHour());

            $actor = $this->actorUserId
                ? User::query()->find($this->actorUserId)
                : null;

            $result = $pushes->pushPosts($this->postIds, $this->hubIds, $actor);
            $payload = json_decode(json_encode($result), true) ?? [];

            $parts = [];
            if (($payload['pushed'] ?? 0) > 0) {
                $parts[] = 'Distributed '.($payload['pushed']).' post(s)';
            }
            if (($payload['skipped'] ?? 0) > 0) {
                $parts[] = ($payload['skipped']).' already on hub (skipped)';
            }
            if (($payload['failed'] ?? 0) > 0) {
                $parts[] = ($payload['failed']).' failed';
            }
            $message = $parts !== []
                ? implode('. ', $parts).'.'
                : 'No posts were distributed.';

            Cache::put($cacheKey, [
                'status' => 'completed',
                'user_id' => $this->actorUserId,
                'message' => $message,
                ...$payload,
            ], now()->addHour());
        } catch (InvalidArgumentException $e) {
            Cache::put($cacheKey, [
                'status' => 'failed',
                'user_id' => $this->actorUserId,
                'message' => $e->getMessage(),
            ], now()->addHour());
        } catch (Throwable $e) {
            report($e);
            Log::error('content push job failed', [
                'job_id' => $this->jobId,
                'error' => $e->getMessage(),
            ]);
            Cache::put($cacheKey, [
                'status' => 'failed',
                'user_id' => $this->actorUserId,
                'message' => 'Content distribution failed unexpectedly. Check server logs.',
            ], now()->addHour());
        }
    }

    public static function cacheKey(string $jobId): string
    {
        return 'content_push:'.$jobId;
    }
}
