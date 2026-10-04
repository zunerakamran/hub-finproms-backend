<?php

namespace App\Support;

use App\Jobs\ProcessContentPushJob;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

trait QueuesContentPush
{
    /**
     * @param  list<int>  $postIds
     * @param  list<int>  $hubIds
     */
    protected function dispatchContentPush(array $postIds, array $hubIds, User $actor): JsonResponse
    {
        $jobId = (string) Str::uuid();

        Cache::put(ProcessContentPushJob::cacheKey($jobId), [
            'status' => 'queued',
            'user_id' => (int) $actor->id,
            'message' => 'Distribution queued. Waiting for a worker…',
        ], now()->addHour());

        ProcessContentPushJob::dispatch(
            $jobId,
            $postIds,
            $hubIds,
            (int) $actor->id,
        );

        return response()->json([
            'queued' => true,
            'job_id' => $jobId,
            'message' => 'Distribution queued. Processing in the background…',
        ], 202);
    }

    protected function contentPushStatusPayload(string $jobId, User $actor): JsonResponse
    {
        if (! preg_match('/^[0-9a-fA-F-]{36}$/', $jobId)) {
            return response()->json(['message' => 'Invalid distribution job id.'], 422);
        }

        $payload = Cache::get(ProcessContentPushJob::cacheKey($jobId));
        if (! is_array($payload) || (int) ($payload['user_id'] ?? 0) !== (int) $actor->id) {
            return response()->json(['message' => 'Distribution job not found.'], 404);
        }

        return response()->json($payload);
    }
}
