<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Hub;
use App\Models\HubBackup;
use App\Services\HubBackupService;
use App\Services\HubService;
use App\Services\WhiteLabelHubSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class PowerAdminHubBackupController extends Controller
{
    public function __construct(
        private readonly HubBackupService $backups,
        private readonly HubService $hubs,
        private readonly WhiteLabelHubSyncService $whiteLabelSync,
    ) {}

    public function index(Hub $hub): JsonResponse
    {
        $items = HubBackup::query()
            ->where(function ($q) use ($hub) {
                $q->where('hub_id', $hub->id)->orWhere('hub_slug', $hub->slug);
            })
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn (HubBackup $b) => $b->toAdminArray())
            ->values();

        return response()->json([
            'hub' => [
                'id' => $hub->id,
                'name' => $hub->name,
                'slug' => $hub->slug,
                'type' => $hub->type,
            ],
            'schedule' => $this->backups->schedulePayload($hub),
            'backups' => $items,
        ]);
    }

    public function updateSchedule(Request $request, Hub $hub): JsonResponse
    {
        $validated = $request->validate([
            'backup_enabled' => ['sometimes', 'boolean'],
            'backup_time' => ['sometimes', 'string', 'regex:/^\d{2}:\d{2}$/'],
            'backup_timezone' => ['sometimes', 'string', 'max:64'],
            'backup_frequency' => ['sometimes', 'string', Rule::in(['daily', 'weekly'])],
            'backup_weekday' => ['nullable', 'integer', 'min:0', 'max:6'],
            'backup_retention_local' => ['sometimes', 'integer', 'min:1', 'max:60'],
            'backup_retention_central' => ['sometimes', 'integer', 'min:1', 'max:90'],
        ]);

        $this->backups->ensureBackupToken($hub);
        $hub->fill($validated)->save();
        $hub = $hub->fresh();

        $syncWarning = null;
        if (! $hub->isControlPlane() && $hub->hasRemoteDatabaseConfigured()) {
            try {
                $this->whiteLabelSync->pushSettings($hub);
            } catch (Throwable $e) {
                report($e);
                $syncWarning = $e->getMessage();
            }
        }

        return response()->json([
            'message' => 'Backup schedule saved.'
                .($syncWarning ? ' Remote sync warning: '.$syncWarning : ''),
            'schedule' => $this->backups->schedulePayload($hub),
            'hub' => $hub->toAdminArray(),
        ]);
    }

    public function store(Request $request, Hub $hub): JsonResponse
    {
        // Manual backup always runs on Central for Central hub.
        // For content hubs: instruct that hub via api_url when possible; otherwise
        // create on Central from this control-plane DB only if hub IS current (rare).
        try {
            if ($hub->isControlPlane() || $hub->slug === $this->hubs->current()->slug) {
                $local = $this->backups->createLocalBackup(
                    $hub,
                    HubBackup::TRIGGER_MANUAL,
                    $request->user()
                );
                $central = $this->backups->mirrorLocalToCentralStore($local, $hub);

                return response()->json([
                    'message' => 'Backup created on this server (local + Central store).',
                    'backup' => $central->toAdminArray(),
                    'local_backup' => $local->toAdminArray(),
                ], 201);
            }

            $apiUrl = rtrim((string) ($hub->api_url ?: ''), '/');
            if ($apiUrl === '') {
                return response()->json([
                    'message' => 'Set the hub api_url (deploy wiring) before running a remote backup.',
                ], 422);
            }

            $token = $this->backups->ensureBackupToken($hub);
            $hub->refresh();

            // Push token/schedule so the remote hub can authorize the callback.
            if ($hub->hasRemoteDatabaseConfigured()) {
                try {
                    $this->whiteLabelSync->pushSettings($hub);
                } catch (Throwable $e) {
                    report($e);
                }
            }

            $receiveUrl = rtrim((string) config('app.url'), '/').'/api/internal/hub-backups/receive';
            $verify = (bool) config('services.http_tls_verify', true);
            $response = Http::withOptions(['verify' => $verify])
                ->timeout((int) config('services.hub_backup.upload_timeout', 600))
                ->withHeaders([
                    'X-Hub-Backup-Key' => $token,
                    'X-Hub-Slug' => $hub->slug,
                    'X-Central-Receive-Url' => $receiveUrl,
                ])
                ->post($apiUrl.'/internal/hub-backups/run', [
                    'triggered_by' => HubBackup::TRIGGER_MANUAL,
                    'central_receive_url' => $receiveUrl,
                    'hub_slug' => $hub->slug,
                ]);

            if (! $response->successful()) {
                return response()->json([
                    'message' => 'Remote hub backup failed: HTTP '.$response->status().' '
                        .Str::limit($response->body(), 500),
                ], 422);
            }

            return response()->json([
                'message' => $response->json('message') ?: 'Backup started on the hub and uploaded to Central.',
                'backup' => $response->json('central_backup'),
                'local_backup' => $response->json('local_backup'),
            ], 201);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Backup failed: '.$e->getMessage(),
            ], 422);
        }
    }

    public function download(Hub $hub, HubBackup $backup): StreamedResponse|JsonResponse
    {
        $this->assertBackupBelongs($hub, $backup);

        if ($backup->status !== HubBackup::STATUS_COMPLETED || ! $backup->disk_path) {
            return response()->json(['message' => 'Backup is not downloadable.'], 422);
        }

        $path = $backup->absolutePath();
        if (! $path || ! is_file($path)) {
            return response()->json(['message' => 'Backup file missing on disk.'], 404);
        }

        return response()->streamDownload(function () use ($path) {
            $stream = fopen($path, 'rb');
            if ($stream) {
                fpassthru($stream);
                fclose($stream);
            }
        }, $backup->filename ?: 'backup.zip', [
            'Content-Type' => 'application/zip',
        ]);
    }

    public function restore(Request $request, Hub $hub, HubBackup $backup): JsonResponse
    {
        $this->assertBackupBelongs($hub, $backup);

        $request->validate([
            'confirm' => ['required', 'accepted'],
        ]);

        if ($backup->status !== HubBackup::STATUS_COMPLETED || $backup->location !== HubBackup::LOCATION_CENTRAL) {
            return response()->json([
                'message' => 'Restore requires a completed Central-stored backup.',
            ], 422);
        }

        try {
            $this->backups->restoreRemoteHubFromCentral($hub, $backup);

            return response()->json([
                'message' => 'Restore completed for hub '.$hub->slug.'.',
            ]);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Restore failed: '.$e->getMessage(),
            ], 422);
        }
    }

    public function destroy(Hub $hub, HubBackup $backup): JsonResponse
    {
        $this->assertBackupBelongs($hub, $backup);

        $path = $backup->absolutePath();
        if ($path && is_file($path)) {
            @unlink($path);
        }
        $backup->delete();

        return response()->json(['message' => 'Backup deleted.']);
    }

    private function assertBackupBelongs(Hub $hub, HubBackup $backup): void
    {
        if ((int) $backup->hub_id === (int) $hub->id || $backup->hub_slug === $hub->slug) {
            return;
        }

        abort(404);
    }
}
