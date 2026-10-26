<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ActingHubService;
use App\Services\PaymentCardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Network / firm payment cards on /my-dashboard/payment-card.
 * Personal cards use ProfilePaymentCardController (no capability).
 */
class AdvisorPaymentCardController extends Controller
{
    public function __construct(
        private readonly PaymentCardService $cards,
        private readonly ActingHubService $actingHubs
    ) {}

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $hub = $this->actingHubs->targetHub($user);
        $scope = $this->resolveScope($request, $hub, $user);
        $firmId = $request->integer('firm_id') ?: null;

        try {
            $profile = $this->cards->profile($user, $hub, $scope, $firmId);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }

        $settings = app(\App\Services\ModuleInvoiceSettingsService::class)->forHub($hub);

        return response()->json([
            'payment_profile' => $profile,
            'available_scopes' => $this->availableScopes($user, $hub, $settings),
            'invoice_settings' => [
                'firms_pay_own' => (bool) $settings->firms_pay_own,
                'network_pays_all' => (bool) $settings->network_pays_all,
                'network_resells' => (bool) $settings->network_resells,
                'users_pay_own' => (bool) $settings->users_pay_own,
            ],
        ]);
    }

    public function setup(Request $request): JsonResponse
    {
        $user = $request->user();
        $hub = $this->actingHubs->targetHub($user);
        $scope = $this->resolveScope($request, $hub, $user);
        $firmId = $request->integer('firm_id') ?: null;

        try {
            $result = $this->cards->createSetupSession($user, $hub, $scope, $firmId);
        } catch (InvalidArgumentException $e) {
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
                ? 'Card saved for '.$profile['scope'].'.'
                : 'Checkout recorded; card not saved yet.',
            'payment_profile' => $profile,
        ]);
    }

    private function resolveScope(Request $request, $hub, $user): string
    {
        $scope = (string) $request->input('scope', '');
        if (in_array($scope, [PaymentCardService::SCOPE_NETWORK, PaymentCardService::SCOPE_FIRM], true)) {
            return $scope;
        }

        $matrix = app(\App\Services\CapabilitiesMatrixService::class);
        if ($matrix->userCan($hub, $user, 'dashboard_manage_network_payment_card')) {
            return PaymentCardService::SCOPE_NETWORK;
        }
        if ($matrix->userCan($hub, $user, 'dashboard_manage_firm_payment_card')) {
            return PaymentCardService::SCOPE_FIRM;
        }

        return PaymentCardService::SCOPE_NETWORK;
    }

    /**
     * @return list<array{scope: string, label: string, enabled: bool}>
     */
    private function availableScopes($user, $hub, $settings): array
    {
        $matrix = app(\App\Services\CapabilitiesMatrixService::class);

        return [
            [
                'scope' => PaymentCardService::SCOPE_NETWORK,
                'label' => 'Network card',
                'enabled' => $matrix->userCan($hub, $user, 'dashboard_manage_network_payment_card'),
            ],
            [
                'scope' => PaymentCardService::SCOPE_FIRM,
                'label' => 'Firm card',
                'enabled' => (bool) $settings->firms_pay_own
                    && $matrix->userCan($hub, $user, 'dashboard_manage_firm_payment_card'),
            ],
        ];
    }
}
