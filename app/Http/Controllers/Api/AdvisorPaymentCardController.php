<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Hub;
use App\Services\ActingHubService;
use App\Services\AdvisorBillingService;
use App\Services\CapabilitiesMatrixService;
use App\Services\ModuleInvoiceSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Payment card on file — gated by Capabilities matrix
 * (`dashboard_manage_payment_card`), not a hard-coded role.
 * Available when advisor billing and/or module charging is on.
 */
class AdvisorPaymentCardController extends Controller
{
    public function __construct(
        private readonly AdvisorBillingService $billing,
        private readonly CapabilitiesMatrixService $matrix,
        private readonly ActingHubService $actingHubs
    ) {}

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->assertCanManagePaymentCard($user);

        return response()->json([
            'payment_profile' => $this->billing->paymentProfile($user),
        ]);
    }

    public function setup(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->assertCanManagePaymentCard($user);

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
        $this->assertCanManagePaymentCard($user);

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

    private function assertCanManagePaymentCard(?\App\Models\User $user): Hub
    {
        if (! $user) {
            abort(response()->json([
                'message' => 'Authentication required.',
            ], 403));
        }

        $hub = $this->actingHubs->targetHub($user, null);

        if (! $this->matrix->userCan($hub, $user, ModuleInvoiceSettingsService::PAYMENT_CARD_CAPABILITY)) {
            abort(response()->json([
                'message' => 'Managing the payment card is disabled for your role. Enable “Manage payment card” in Power Admin → Capabilities.',
            ], 403));
        }

        if (! $this->billing->paymentCardEnabled($hub)) {
            abort(response()->json([
                'message' => 'Payment card is available when advisor billing or module charging is enabled for this hub.',
            ], 403));
        }

        return $hub;
    }
}
