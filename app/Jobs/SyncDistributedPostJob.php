<?php

namespace App\Jobs;

use App\Models\Post;
use App\Services\ContentPushService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Syncs an updated Central library post to hubs it was already distributed to.
 */
class SyncDistributedPostJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(
        public readonly int $postId,
    ) {}

    public function handle(ContentPushService $pushes): void
    {
        $post = Post::query()->find($this->postId);
        if (! $post) {
            return;
        }

        try {
            $result = $pushes->syncPostToDistributedHubs($post);
            Log::info('distributed post sync finished', [
                'post_id' => $this->postId,
                'synced' => $result['synced'] ?? 0,
                'failed' => $result['failed'] ?? 0,
            ]);
        } catch (Throwable $e) {
            Log::error('distributed post sync failed', [
                'post_id' => $this->postId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
