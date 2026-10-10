<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\HubRelease;
use App\Services\HubCodeUpdateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * Hub ↔ Central code-update callbacks (per-hub update/backup token).
 */
class InternalCodeUpdateController extends Controller
{
    public function __construct(
        private readonly HubCodeUpdateService $codeUpdates,
    ) {}

    /**
     * Content hub (or any deploy): download artifacts from Central and apply.
     */
    public function apply(Request $request): JsonResponse
    {
        $provided = (string) $request->header('X-Hub-Code-Update-Key', '');
        if (! $this->codeUpdates->authorizeUpdateKey($provided)) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $validated = $request->validate([
            'version' => ['required', 'string', 'max:64'],
            'backend_download_url' => ['nullable', 'string', 'max:2048'],
            'frontend_download_url' => ['nullable', 'string', 'max:2048'],
            'frontend_path' => ['nullable', 'string', 'max:1024'],
            'auth_token' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $result = $this->codeUpdates->applyLocally([
                'version' => $validated['version'],
                'backend_download_url' => $validated['backend_download_url'] ?? null,
                'frontend_download_url' => $validated['frontend_download_url'] ?? null,
                'frontend_path' => $validated['frontend_path'] ?? null,
                'auth_token' => $validated['auth_token'] ?? $provided,
            ]);

            return response()->json($result);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Apply failed: '.$e->getMessage(),
            ], 422);
        }
    }

    /**
     * Central only: serve a release zip to a hub that presents a valid update key.
     */
    public function artifact(Request $request, HubRelease $release, string $kind): BinaryFileResponse|JsonResponse
    {
        if (! config('hub.is_control_plane')) {
            return response()->json(['message' => 'Not a control plane'], 403);
        }

        if (! in_array($kind, ['backend', 'frontend'], true)) {
            return response()->json(['message' => 'Invalid artifact kind.'], 404);
        }

        $provided = (string) $request->header('X-Hub-Code-Update-Key', '');
        if (! $this->codeUpdates->authorizeArtifactDownload($provided)) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $path = $this->codeUpdates->absoluteArtifactPath($release, $kind);
        if (! $path || ! is_file($path)) {
            return response()->json(['message' => 'Artifact not found.'], 404);
        }

        return response()->file($path, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => 'attachment; filename="'.$kind.'-'.$release->version.'.zip"',
        ]);
    }
}
