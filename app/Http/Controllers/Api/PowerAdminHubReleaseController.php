<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Hub;
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
        ]);

        try {
            $release = $this->codeUpdates->publishRelease($validated, $request->user());
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
            $codeUpdate = $this->codeUpdates->markManual($hub, $validated);
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
