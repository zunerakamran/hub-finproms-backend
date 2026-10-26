<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ActingHubService;
use App\Services\PaymentCardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Personal payment card (My payment method) — no capability; only while users_pay_own.
 */
class ProfilePaymentCardController extends Controller
{
    public function __construct(
        private readonly PaymentCardService $cards,
        private readonly ActingHubService $actingHubs
    ) {}

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $hub = $this->actingHubs->targetHub($user);

        try {
            $profile = $this->cards->profile($user, $hub, PaymentCardService::SCOPE_PERSONAL);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }

        return response()->json(['payment_profile' => $profile]);
    }

    public function setup(Request $request): JsonResponse
    {
        $user = $request->user();
        $hub = $this->actingHubs->targetHub($user);

        try {
            $result = $this->cards->createSetupSession($user, $hub, PaymentCardService::SCOPE_PERSONAL);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Open Stripe to enter or update your personal card.',
            ...$result,
        ]);
    }

    public function confirm(Request $request): JsonResponse
    {
        $user = $request->user();
        $hub = $this->actingHubs->targetHub($user);
        $validated = $request->validate([
            'session_id' => ['required', 'string'],
        ]);

        try {
            $profile = $this->cards->fulfillSetupSession($validated['session_id'], $user, $hub);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => ($profile['has_saved_card'] ?? false)
                ? 'Personal card saved.'
                : 'Checkout recorded; card not saved yet.',
            'payment_profile' => $profile,
        ]);
    }
}
