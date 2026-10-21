<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Hub;
use App\Models\HubBackup;
use App\Services\HubBackupService;
use App\Services\HubService;
use App\Support\HubInternalAuth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Hub ↔ Central backup callbacks.
 *
 * run / receive: X-Hub-Backup-Key (backup_token / HUB_BACKUP_SECRET)
 * restore:       X-Hub-Restore-Key (restore_token / HUB_RESTORE_SECRET)
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
        if (! HubInternalAuth::authorizeCurrentHub($request, HubInternalAuth::PURPOSE_BACKUP)) {
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
            // Prefer CENTRAL_API_URL. Request override is only accepted when it
            // matches the configured Central host / allowlist (no open SSRF).
            $receiveUrl = $request->header('X-Central-Receive-Url')
                ?: $request->input('central_receive_url');
            // Reuse the key + registry slug Central used to authorize /run so the
            // upload matches Central's hubs.backup_token even if this deploy's
            // HUB_SLUG / local token drifted from the Central registry.
            $uploadToken = (string) $request->header(HubInternalAuth::HEADER_BACKUP, '');
            $uploadSlug = (string) ($request->header('X-Hub-Slug')
                ?: $request->input('hub_slug', '')
                ?: $this->hubs->current()->slug);
            try {
                $centralPayload = $this->backups->uploadLocalBackupToCentral(
                    $local,
                    is_string($receiveUrl) && $receiveUrl !== '' ? $receiveUrl : null,
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
        if (! HubInternalAuth::authorizeIncomingBackup($request, $slug)) {
            Log::warning('hub backup receive: unauthorized', [
                'ip' => $request->ip(),
                'slug' => $slug,
                'key_present' => $request->header(HubInternalAuth::HEADER_BACKUP) !== null
                    && $request->header(HubInternalAuth::HEADER_BACKUP) !== '',
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
                'is_encrypted' => $request->input('is_encrypted', false),
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
        if (! HubInternalAuth::authorizeCurrentHub($request, HubInternalAuth::PURPOSE_RESTORE)) {
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

        $provided = (string) $request->header(HubInternalAuth::HEADER_BACKUP, '');
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
