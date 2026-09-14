<?php

namespace App\Http\Controllers\Api\WebsiteCompliance;

use App\Http\Controllers\Controller;
use App\Models\WebsiteCompliance\ChangeRequest;
use App\Services\WebsiteCompliance\ChangeRequestPublishService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * HTTP-based scheduler webhook.
 *
 *   GET /api/website-compliance/scheduler/publish-scheduled?key=YOUR_SCHEDULER_SECRET
 */
class SchedulerController extends Controller
{
    public function publishScheduled(Request $request): JsonResponse
    {
        $secret = config('services.website_compliance.scheduler_secret');

        if (! $secret || $request->query('key') !== $secret) {
            Log::warning('wc publish-scheduled webhook: invalid or missing key', [
                'ip' => $request->ip(),
            ]);

            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $due = ChangeRequest::with('section')
            ->where('status', 'scheduled')
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->orderBy('scheduled_at')
            ->get();

        if ($due->isEmpty()) {
            return response()->json([
                'message' => 'No scheduled change requests due for publication.',
                'published' => 0,
                'failed' => 0,
                'now' => now()->toIso8601String(),
            ]);
        }

        Log::info('wc publish-scheduled webhook: due change requests found', [
            'due_count' => $due->count(),
            'now' => now()->toIso8601String(),
        ]);

        $published = 0;
        $failed = 0;
        $results = [];

        foreach ($due as $changeRequest) {
            try {
                $result = ChangeRequestPublishService::publish(
                    $changeRequest,
                    $changeRequest->approver_id
                );

                $published++;
                Log::info('wc publish-scheduled webhook: published', [
                    'change_request_id' => $changeRequest->id,
                    'cpanel_synced' => $result['cpanel_synced'] ?? false,
                ]);

                $results[] = [
                    'id' => $changeRequest->id,
                    'status' => 'published',
                    'cpanel_synced' => $result['cpanel_synced'] ?? false,
                ];
            } catch (\Throwable $e) {
                $failed++;
                Log::error('wc publish-scheduled webhook: failed to publish', [
                    'change_request_id' => $changeRequest->id,
                    'error' => $e->getMessage(),
                ]);

                $results[] = [
                    'id' => $changeRequest->id,
                    'status' => 'failed',
                    'error' => $e->getMessage(),
                ];
            }
        }

        return response()->json([
            'message' => "Done. Published: {$published}, failed: {$failed}.",
            'published' => $published,
            'failed' => $failed,
            'results' => $results,
            'now' => now()->toIso8601String(),
        ], $failed > 0 ? 207 : 200);
    }
}
