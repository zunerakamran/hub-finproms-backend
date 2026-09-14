<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ActingHubService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Shared-hub dashboard hub switcher (Control white labelled hubs).
 */
class ActingHubController extends Controller
{
    public function __construct(
        private readonly ActingHubService $actingHubs
    ) {}

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $payload = $this->actingHubs->switcherPayload($user);

        if ($payload === null) {
            return response()->json([
                'message' => 'Hub switcher is not available for your role on this hub.',
                'hub_switcher' => null,
            ], 403);
        }

        return response()->json([
            'hub_switcher' => $payload,
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'hub_id' => ['nullable', 'integer', 'exists:hubs,id'],
        ]);

        try {
            $hub = $this->actingHubs->setActingHub(
                $request->user(),
                isset($validated['hub_id']) ? (int) $validated['hub_id'] : null
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (HttpException $e) {
            return response()->json(['message' => $e->getMessage()], $e->getStatusCode());
        }

        return response()->json([
            'message' => $hub->isWhiteLabel()
                ? 'Now controlling '.$hub->name.'. Dashboard tools use this hub’s capabilities; content is saved to its database.'
                : 'Switched back to the shared hub.',
            'hub_switcher' => $this->actingHubs->switcherPayload($request->user()->fresh()),
        ]);
    }
}
