<?php

namespace App\Http\Controllers\Api\WebsiteCompliance;

use App\Http\Controllers\Controller;
use App\Services\WebsiteCompliance\ScheduledChangeRequestPublisher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * HTTP-based scheduler webhook.
 *
 * Prefer: GET .../publish-scheduled with header X-Scheduler-Key: YOUR_SCHEDULER_SECRET
 * Legacy:  GET .../publish-scheduled?key=YOUR_SCHEDULER_SECRET
 */
class SchedulerController extends Controller
{
    public function publishScheduled(Request $request, ScheduledChangeRequestPublisher $publisher): JsonResponse
    {
        $secret = (string) (config('services.website_compliance.scheduler_secret') ?? '');
        $provided = (string) (
            $request->header('X-Scheduler-Key')
            ?: $request->query('key', '')
        );

        if ($secret === '' || ! hash_equals($secret, $provided)) {
            Log::warning('wc publish-scheduled webhook: invalid or missing key', [
                'ip' => $request->ip(),
            ]);

            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $result = $publisher->publishDue();

        if ($result['published'] === 0 && $result['failed'] === 0) {
            return response()->json([
                'message' => 'No scheduled change requests due for publication.',
                'published' => 0,
                'failed' => 0,
                'hubs_scanned' => $result['hubs_scanned'],
                'now' => $result['now'],
            ]);
        }

        return response()->json([
            'message' => "Done. Published: {$result['published']}, failed: {$result['failed']}.",
            'published' => $result['published'],
            'failed' => $result['failed'],
            'hubs_scanned' => $result['hubs_scanned'],
            'results' => $result['results'],
            'now' => $result['now'],
        ], $result['failed'] > 0 ? 207 : 200);
    }
}
