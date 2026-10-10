<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Hub;
use App\Models\HubRelease;
use App\Services\HubCodeUpdateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Throwable;

class PowerAdminHubReleaseController extends Controller
{
    public function __construct(
        private readonly HubCodeUpdateService $codeUpdates,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json($this->codeUpdates->overviewPayload());
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'version' => ['required', 'string', 'max:64'],
            'backend_version' => ['nullable', 'string', 'max:64'],
            'frontend_version' => ['nullable', 'string', 'max:64'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'backend_zip' => ['nullable', 'file', 'mimes:zip', 'max:512000'],
            'frontend_zip' => ['nullable', 'file', 'mimes:zip', 'max:512000'],
        ]);

        try {
            $release = $this->codeUpdates->publishRelease($validated, $request->user());
            if ($request->hasFile('backend_zip') || $request->hasFile('frontend_zip')) {
                $release = $this->codeUpdates->storeArtifacts(
                    $release,
                    $request->file('backend_zip'),
                    $request->file('frontend_zip'),
                );
            }
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Could not publish release: '.$e->getMessage(),
            ], 422);
        }

        return response()->json([
            'message' => 'Release '.$release->version.' published as latest.',
            'release' => $release->toAdminArray(),
            'overview' => $this->codeUpdates->overviewPayload(),
        ], 201);
    }

    public function storeArtifacts(Request $request, HubRelease $release): JsonResponse
    {
        $request->validate([
            'backend_zip' => ['nullable', 'file', 'mimes:zip', 'max:512000'],
            'frontend_zip' => ['nullable', 'file', 'mimes:zip', 'max:512000'],
        ]);

        if (! $request->hasFile('backend_zip') && ! $request->hasFile('frontend_zip')) {
            return response()->json(['message' => 'Attach backend_zip and/or frontend_zip.'], 422);
        }

        try {
            $release = $this->codeUpdates->storeArtifacts(
                $release,
                $request->file('backend_zip'),
                $request->file('frontend_zip'),
            );
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Could not store artifacts: '.$e->getMessage(),
            ], 422);
        }

        return response()->json([
            'message' => 'Artifacts saved for release '.$release->version.'.',
            'release' => $release->toAdminArray(),
            'overview' => $this->codeUpdates->overviewPayload(),
        ]);
    }

    public function apply(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'release_id' => ['nullable', 'integer', 'exists:hub_releases,id'],
            'hub_ids' => ['required', 'array', 'min:1'],
            'hub_ids.*' => ['integer', 'exists:hubs,id'],
        ]);

        $release = isset($validated['release_id'])
            ? HubRelease::query()->findOrFail($validated['release_id'])
            : $this->codeUpdates->latestRelease();

        if (! $release) {
            return response()->json(['message' => 'Publish a release before applying.'], 422);
        }

        try {
            $result = $this->codeUpdates->applyReleaseToHubs(
                $release,
                $validated['hub_ids'],
                $request->user()
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Apply failed: '.$e->getMessage(),
            ], 422);
        }

        return response()->json([
            'message' => 'Applied '.$release->version.' to '.$result['applied']
                .' hub(s); '.$result['failed'].' failed.',
            'release' => $release->toAdminArray(),
            'applied' => $result['applied'],
            'failed' => $result['failed'],
            'results' => $result['results'],
            'overview' => $this->codeUpdates->overviewPayload(),
        ]);
    }

    public function history(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'hub_id' => ['nullable', 'integer', 'exists:hubs,id'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        return response()->json([
            'history' => $this->codeUpdates->listHistory(
                $validated['hub_id'] ?? null,
                (int) ($validated['limit'] ?? 100)
            ),
        ]);
    }

    public function refreshAll(): JsonResponse
    {
        $result = $this->codeUpdates->refreshAll();

        return response()->json([
            'message' => 'Refreshed versions for '.$result['refreshed'].' hub(s).',
            'refreshed' => $result['refreshed'],
            'results' => $result['results'],
            'overview' => $this->codeUpdates->overviewPayload(),
        ]);
    }

    public function refreshHub(Hub $hub): JsonResponse
    {
        $codeUpdate = $this->codeUpdates->refreshHub($hub);

        return response()->json([
            'message' => $codeUpdate['error']
                ? 'Version check finished with errors.'
                : 'Hub version refreshed.',
            'hub' => $hub->fresh()->toAdminArray(),
            'code_update' => $codeUpdate,
        ]);
    }

    public function markHub(Request $request, Hub $hub): JsonResponse
    {
        $validated = $request->validate([
            'version' => ['required', 'string', 'max:64'],
            'backend_version' => ['nullable', 'string', 'max:64'],
            'frontend_version' => ['nullable', 'string', 'max:64'],
        ]);

        try {
            $codeUpdate = $this->codeUpdates->markManual($hub, $validated, $request->user());
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Recorded version '.$codeUpdate['reported_version'].' for '.$hub->name.'.',
            'hub' => $hub->fresh()->toAdminArray(),
            'code_update' => $codeUpdate,
        ]);
    }
}
