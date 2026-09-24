<?php

namespace App\Services\WebsiteCompliance;

use App\Models\Hub;
use App\Models\WebsiteCompliance\ChangeRequest;
use App\Services\WhiteLabelDatabaseService;
use App\Support\WebsiteCompliance\WcDatabaseContext;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Publishes due scheduled Website Compliance change requests.
 * Scans the shared/default DB and every configured white-labelled hub remote DB.
 */
class ScheduledChangeRequestPublisher
{
    public function __construct(
        private readonly WhiteLabelDatabaseService $remoteDb
    ) {}

    /**
     * @return array{
     *     published: int,
     *     failed: int,
     *     hubs_scanned: int,
     *     results: list<array{id:int, status:string, scope:string, cpanel_synced?:bool, error?:string}>,
     *     now: string
     * }
     */
    public function publishDue(): array
    {
        $totals = [
            'published' => 0,
            'failed' => 0,
            'hubs_scanned' => 0,
            'results' => [],
            'now' => now()->toIso8601String(),
        ];

        $this->publishInScope('default', $totals);

        $hubs = Hub::query()
            ->where('type', Hub::TYPE_WHITE_LABEL)
            ->orderBy('id')
            ->get();

        foreach ($hubs as $hub) {
            if (! $hub->hasRemoteDatabaseConfigured()) {
                continue;
            }

            try {
                $this->remoteDb->run($hub, function (string $connection) use ($hub, &$totals) {
                    return WcDatabaseContext::using($connection, function () use ($hub, &$totals) {
                        $this->publishInScope('hub:'.$hub->id.' ('.$hub->name.')', $totals);

                        return null;
                    });
                });
            } catch (Throwable $e) {
                Log::warning('wc scheduled publisher: skipped white-labelled hub', [
                    'hub_id' => $hub->id,
                    'hub_name' => $hub->name,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $totals;
    }

    /**
     * @param  array{
     *     published: int,
     *     failed: int,
     *     hubs_scanned: int,
     *     results: list<array<string, mixed>>,
     *     now: string
     * }  $totals
     */
    private function publishInScope(string $scope, array &$totals): void
    {
        $connection = WcDatabaseContext::connection() ?: (string) config('database.default');

        try {
            if (! Schema::connection($connection)->hasTable('wc_change_requests')) {
                return;
            }
        } catch (Throwable $e) {
            Log::warning('wc scheduled publisher: could not inspect schema', [
                'scope' => $scope,
                'connection' => $connection,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $totals['hubs_scanned']++;

        $due = ChangeRequest::with(['section', 'currentVersionRow'])
            ->where('status', ChangeRequest::STATUS_SCHEDULED)
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->orderBy('scheduled_at')
            ->get();

        if ($due->isEmpty()) {
            return;
        }

        Log::info('wc scheduled publisher: due change requests found', [
            'scope' => $scope,
            'due_count' => $due->count(),
            'now' => now()->toIso8601String(),
        ]);

        foreach ($due as $changeRequest) {
            try {
                $result = ChangeRequestPublishService::publish(
                    $changeRequest,
                    $changeRequest->approver_id
                );

                $totals['published']++;
                $totals['results'][] = [
                    'id' => $changeRequest->id,
                    'status' => 'published',
                    'scope' => $scope,
                    'cpanel_synced' => (bool) ($result['cpanel_synced'] ?? false),
                ];

                Log::info('wc scheduled publisher: published', [
                    'scope' => $scope,
                    'change_request_id' => $changeRequest->id,
                    'cpanel_synced' => $result['cpanel_synced'] ?? false,
                ]);
            } catch (Throwable $e) {
                $totals['failed']++;
                $totals['results'][] = [
                    'id' => $changeRequest->id,
                    'status' => 'failed',
                    'scope' => $scope,
                    'error' => $e->getMessage(),
                ];

                Log::error('wc scheduled publisher: failed to publish', [
                    'scope' => $scope,
                    'change_request_id' => $changeRequest->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
