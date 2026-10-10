<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\HubCodeUpdateService;
use Illuminate\Http\JsonResponse;

class AppVersionController extends Controller
{
    public function __construct(
        private readonly HubCodeUpdateService $codeUpdates,
    ) {}

    /**
     * Public: running code version for this deploy (Central polls content hubs).
     */
    public function show(): JsonResponse
    {
        return response()->json($this->codeUpdates->localVersionPayload());
    }
}
