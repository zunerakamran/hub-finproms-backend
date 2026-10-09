<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Hub;
use App\Models\HubBackup;
use App\Services\HubBackupService;
use App\Services\HubService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Hub ↔ Central backup callbacks (shared secret / per-hub backup_token).
 */
class InternalHubBackupController extends Controller
{
    public function __construct(
        private readonly HubBackupService $backups,
        private readonly HubService $hubs,
    ) {}

    /**
     * Content hub: create local backup and upload a copy to Central.
     */
    public function run(Request $request): JsonResponse
    {
        if (! $this->authorizeHub($request)) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        try {
            $triggeredBy = (string) $request->input('triggered_by', HubBackup::TRIGGER_MANUAL);
            if (! in_array($triggeredBy, [HubBackup::TRIGGER_MANUAL, HubBackup::TRIGGER_SCHEDULE], true)) {
                $triggeredBy = HubBackup::TRIGGER_MANUAL;
            }

            $local = $this->backups->createLocalBackup(null, $triggeredBy);
            $centralPayload = null;
            $uploadError = null;
            $receiveUrl = $request->input('central_receive_url')
                ?: $request->header('X-Central-Receive-Url');
            // Reuse the key + registry slug Central used to authorize /run so the
            // upload matches Central's hubs.backup_token even if this deploy's
            // HUB_SLUG / local token drifted from the Central registry.
            $uploadToken = (string) $request->header('X-Hub-Backup-Key', '');
            $uploadSlug = (string) ($request->header('X-Hub-Slug')
                ?: $request->input('hub_slug', '')
                ?: $this->hubs->current()->slug);
            try {
                $centralPayload = $this->backups->uploadLocalBackupToCentral(
                    $local,
                    is_string($receiveUrl) ? $receiveUrl : null,
                    $uploadToken !== '' ? $uploadToken : null,
                    $uploadSlug !== '' ? $uploadSlug : null,
                );
            } catch (Throwable $e) {
                report($e);
                $uploadError = $e->getMessage();
            }

            return response()->json([
                'message' => $uploadError
                    ? 'Local backup created; Central upload failed: '.$uploadError
                    : 'Local backup created and uploaded to Central.',
                'local_backup' => $local->toAdminArray(),
                'central_backup' => $centralPayload['backup'] ?? null,
            ], 201);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'Backup failed: '.$e->getMessage()], 422);
        }
    }

    /**
     * Central: receive archive uploaded by a content hub.
     */
    public function receive(Request $request): JsonResponse
    {
        if (! config('hub.is_control_plane')) {
            return response()->json(['message' => 'Not a control plane'], 403);
        }

        $slug = (string) ($request->header('X-Hub-Slug') ?: $request->input('hub_slug', ''));
        if (! $this->authorizeIncoming($request, $slug)) {
            Log::warning('hub backup receive: unauthorized', [
                'ip' => $request->ip(),
                'slug' => $slug,
                'key_present' => $request->header('X-Hub-Backup-Key') !== null
                    && $request->header('X-Hub-Backup-Key') !== '',
            ]);

            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $registryHub = $this->resolveIncomingHub($request, $slug);
        $storeSlug = $registryHub?->slug ?: $slug;
        if ($storeSlug === '') {
            return response()->json(['message' => 'Could not resolve hub for backup receive.'], 422);
        }

        if (! $request->hasFile('archive')) {
            return response()->json(['message' => 'Missing archive file.'], 422);
        }

        $file = $request->file('archive');
        if (! $file || ! $file->isValid()) {
            return response()->json(['message' => 'Invalid archive upload.'], 422);
        }

        $tmp = storage_path('app/backups/tmp/recv_'.uniqid('', true).'.zip');
        if (! is_dir(dirname($tmp))) {
            mkdir(dirname($tmp), 0755, true);
        }
        $file->move(dirname($tmp), basename($tmp));

        try {
            $backup = $this->backups->receiveOnCentral($storeSlug, $tmp, [
                'checksum' => $request->input('checksum'),
                'triggered_by' => $request->input('triggered_by', HubBackup::TRIGGER_RECEIVE),
                'includes_database' => $request->input('includes_database', true),
                'includes_files' => $request->input('includes_files', true),
                'size_bytes' => $request->input('size_bytes'),
            ]);

            return response()->json([
                'message' => 'Backup received on Central.',
                'backup' => $backup->toAdminArray(),
            ], 201);
        } catch (Throwable $e) {
            report($e);
            @unlink($tmp);

            return response()->json(['message' => 'Receive failed: '.$e->getMessage()], 422);
        }
    }

    /**
     * Content hub: restore from archive pushed by Central.
     */
    public function restore(Request $request): JsonResponse
    {
        if (! $this->authorizeHub($request)) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        if (! $request->hasFile('archive')) {
            return response()->json(['message' => 'Missing archive file.'], 422);
        }

        $file = $request->file('archive');
        if (! $file || ! $file->isValid()) {
            return response()->json(['message' => 'Invalid archive upload.'], 422);
        }

        $tmp = storage_path('app/backups/tmp/restore_in_'.uniqid('', true).'.zip');
        if (! is_dir(dirname($tmp))) {
            mkdir(dirname($tmp), 0755, true);
        }
        $file->move(dirname($tmp), basename($tmp));

        try {
            $this->backups->restoreLocalFromArchive($tmp);
            @unlink($tmp);

            return response()->json(['message' => 'Restore completed on this hub.']);
        } catch (Throwable $e) {
            report($e);
            @unlink($tmp);

            return response()->json(['message' => 'Restore failed: '.$e->getMessage()], 422);
        }
    }

    private function authorizeHub(Request $request): bool
    {
        $provided = (string) $request->header('X-Hub-Backup-Key', '');
        if ($provided === '') {
            return false;
        }

        $hub = $this->hubs->current();
        $expected = (string) ($hub->backup_token ?: '');
        $shared = (string) config('services.hub_backup.secret', '');

        if ($expected !== '' && hash_equals($expected, $provided)) {
            return true;
        }

        return $shared !== '' && hash_equals($shared, $provided);
    }

    private function authorizeIncoming(Request $request, string $slug): bool
    {
        $provided = (string) $request->header('X-Hub-Backup-Key', '');
        if ($provided === '') {
            return false;
        }

        $hub = Hub::query()->where('slug', $slug)->first();
        if ($hub && filled($hub->backup_token) && hash_equals((string) $hub->backup_token, $provided)) {
            return true;
        }

        // Token matches a registered hub (content HUB_SLUG can differ from Central slug).
        $matched = Hub::query()
            ->whereNotNull('backup_token')
            ->where('backup_token', '!=', '')
            ->get()
            ->first(fn (Hub $row) => hash_equals((string) $row->backup_token, $provided));
        if ($matched) {
            Log::info('hub backup receive: authorized by token match', [
                'provided_slug' => $slug,
                'matched_slug' => $matched->slug,
            ]);

            return true;
        }

        $shared = (string) config('services.hub_backup.secret', '');

        return $shared !== '' && hash_equals($shared, $provided);
    }

    /**
     * Resolve the Central registry hub for a received archive (slug, else token).
     */
    public function resolveIncomingHub(Request $request, string $slug): ?Hub
    {
        if ($slug !== '') {
            $bySlug = Hub::query()->where('slug', $slug)->first();
            if ($bySlug) {
                return $bySlug;
            }
        }

        $provided = (string) $request->header('X-Hub-Backup-Key', '');
        if ($provided === '') {
            return null;
        }

        return Hub::query()
            ->whereNotNull('backup_token')
            ->where('backup_token', '!=', '')
            ->get()
            ->first(fn (Hub $row) => hash_equals((string) $row->backup_token, $provided));
    }
}
