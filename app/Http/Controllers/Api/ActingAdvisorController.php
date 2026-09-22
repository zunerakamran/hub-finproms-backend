<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ActingAdvisorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ActingAdvisorController extends Controller
{
    public function __construct(
        private readonly ActingAdvisorService $actingAdvisors
    ) {}

    public function show(Request $request): JsonResponse
    {
        $payload = $this->actingAdvisors->switcherPayload($request->user());

        if ($payload === null) {
            return response()->json([
                'message' => 'Working on behalf of an advisor is only available to Admin-staff.',
                'acting_advisor_switcher' => null,
            ], 403);
        }

        return response()->json([
            'acting_advisor_switcher' => $payload,
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'advisor_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        try {
            $advisor = $this->actingAdvisors->setActingAdvisor(
                $request->user(),
                isset($validated['advisor_id']) ? (int) $validated['advisor_id'] : null
            );
        } catch (HttpException $e) {
            return response()->json(['message' => $e->getMessage()], $e->getStatusCode());
        }

        $fresh = $request->user()->fresh();

        return response()->json([
            'message' => $advisor
                ? 'Now working on behalf of '.$advisor->name.'.'
                : 'Cleared advisor selection.',
            'acting_advisor_switcher' => $this->actingAdvisors->switcherPayload($fresh),
        ]);
    }
}
