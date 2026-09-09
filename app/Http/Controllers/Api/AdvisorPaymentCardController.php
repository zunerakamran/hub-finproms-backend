<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AdvisorBillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Client admin card on file — shown on their dashboard when advisor billing is on.
 * Not part of the Power Admin capabilities matrix.
 */
class AdvisorPaymentCardController extends Controller
{
    public function __construct(
        private readonly AdvisorBillingService $billing
    ) {}

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->assertClientAdminPayer($user);

        return response()->json([
            'payment_profile' => $this->billing->paymentProfile($user),
        ]);
    }

    public function setup(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->assertClientAdminPayer($user);

        try {
            $result = $this->billing->createCardSetupSession($user);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Open Stripe to enter or update your card.',
            ...$result,
        ]);
    }

    public function confirm(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->assertClientAdminPayer($user);

        $validated = $request->validate([
            'session_id' => ['required', 'string'],
        ]);

        try {
            $profile = $this->billing->fulfillCardSetupSession($validated['session_id'], $user);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => $profile['has_saved_card']
                ? 'Card saved. Future advisor imports with Stripe will charge this card.'
                : 'Checkout recorded; card not saved yet.',
            'payment_profile' => $profile,
        ]);
    }

    private function assertClientAdminPayer(?\App\Models\User $user): void
    {
        if (! $user || (! $user->isClientAdmin() && ! $user->isPowerAdmin())) {
            abort(response()->json(['message' => 'Unauthorized.'], 403));
        }

        // White-label client admins manage their own card; FinProms/manager may
        // view the profile when billing is enabled (setup still saves on their user).
        if (! $this->billing->billingEnabled()) {
            abort(response()->json([
                'message' => 'Advisor billing is not enabled for this hub.',
            ], 403));
        }
    }
}
