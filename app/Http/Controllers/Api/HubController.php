<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\HubService;
use Illuminate\Http\JsonResponse;

class HubController extends Controller
{
    public function __construct(
        private readonly HubService $hubs
    ) {}

    /**
     * Public: current hub branding + checklist (for frontend feature gates).
     */
    public function current(): JsonResponse
    {
        $hub = $this->hubs->current();

        return response()->json([
            'hub' => $hub->toPublicArray(),
        ]);
    }
}
