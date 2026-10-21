<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GeneralComplianceRequestAttachment;
use App\Support\GeneralComplianceEncryptedFiles;
use App\Support\HubInternalAuth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Hub ↔ Central GC encrypted-file bridge.
 *
 * Auth: X-Hub-Gc-File-Key (gc_file_token / HUB_GC_FILE_SECRET) — not the backup key.
 *
 * Central acting on a content hub reads that hub's DB remotely, but encrypted
 * bytes live on the content hub's disk. These endpoints let Central store/fetch
 * files on the hub that owns the storage.
 */
class InternalGcFileController extends Controller
{
    /**
     * Decrypt and return attachment bytes from THIS deploy's disk.
     */
    public function show(Request $request, int|string $id): Response|JsonResponse
    {
        if (! HubInternalAuth::authorizeCurrentHub($request, HubInternalAuth::PURPOSE_GC_FILE)) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $attachment = GeneralComplianceRequestAttachment::query()->find((int) $id);
        if (! $attachment) {
            return response()->json(['message' => 'Attachment not found.'], 404);
        }

        $path = (string) ($attachment->file_path ?? '');
        if ($path === '') {
            return response()->json(['message' => 'Attachment has an empty file_path.'], 404);
        }

        try {
            $bytes = GeneralComplianceEncryptedFiles::readPlaintext(
                $path,
                $attachment->isStoredEncrypted()
            );
        } catch (Throwable $e) {
            Log::warning('internal GC file show failed', [
                'attachment_id' => $attachment->id,
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => $e->getMessage()], 404);
        }

        $filename = $this->safeFilename(
            (string) ($attachment->original_name ?: ('gc-file-'.$attachment->id))
        );
        $mime = (string) ($attachment->mime_type ?: 'application/octet-stream');

        return response($bytes, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'private, no-store, no-transform',
            'Content-Encoding' => 'identity',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Encrypt + store an upload on THIS deploy; return path metadata for the caller DB row.
     */
    public function store(Request $request): JsonResponse
    {
        if (! HubInternalAuth::authorizeCurrentHub($request, HubInternalAuth::PURPOSE_GC_FILE)) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $request->validate([
            'file' => ['required', 'file', 'max:102400'],
        ]);

        $file = $request->file('file');

        try {
            $stored = GeneralComplianceEncryptedFiles::storeUpload($file);
        } catch (Throwable $e) {
            Log::warning('internal GC file store failed', ['error' => $e->getMessage()]);

            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'file_path' => $stored['file_path'],
            'file_url' => null,
            'size_bytes' => $stored['size_bytes'],
            'is_encrypted' => true,
            'original_name' => $this->safeFilename((string) $file->getClientOriginalName()),
            'mime_type' => $file->getClientMimeType() ?: $file->getMimeType(),
        ], 201);
    }

    private function safeFilename(string $name): string
    {
        $name = str_replace(["\0", '"', "\r", "\n", '/', '\\'], '-', $name);
        $name = trim($name);
        if ($name === '' || $name === '.' || $name === '..') {
            return 'download.bin';
        }

        return function_exists('mb_substr') ? mb_substr($name, 0, 180) : substr($name, 0, 180);
    }
}
