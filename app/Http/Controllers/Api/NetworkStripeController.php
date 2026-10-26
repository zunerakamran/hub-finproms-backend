<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Hub;
use App\Services\ActingHubService;
use App\Services\CapabilitiesMatrixService;
use App\Services\ModuleInvoiceSettingsService;
use App\Services\PaymentSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Network Stripe account — users pay here when network_resells is on.
 * FinProms platform Stripe remains separate (network → FinProms charges).
 */
class NetworkStripeController extends Controller
{
    public const CAPABILITY = 'dashboard_manage_network_stripe';

    public function __construct(
        private readonly ActingHubService $actingHubs,
        private readonly CapabilitiesMatrixService $matrix,
        private readonly ModuleInvoiceSettingsService $invoiceSettings,
        private readonly PaymentSettingsService $paymentSettings
    ) {}

    public function show(Request $request): JsonResponse
    {
        $hub = $this->assertCanManage($request);
        $settings = $this->invoiceSettings->forHub($hub);

        return response()->json([
            'network_resells' => (bool) $settings->network_resells,
            'message' => $settings->network_resells
                ? null
                : 'Turn on Network resells under Module invoice settings before collecting user payments on the network Stripe account.',
            'hub' => [
                'id' => $hub->id,
                'name' => $hub->name,
                'slug' => $hub->slug,
                'type' => $hub->type,
                'stripe' => $hub->stripeConfigForAdmin(),
            ],
            'hint' => 'Publishable key, secret, and webhook secret for the network Stripe account that receives user payments. Leave blank to clear overrides and fall back to platform keys (not recommended for reseller mode).',
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $hub = $this->assertCanManage($request);
        if (! $this->invoiceSettings->forHub($hub)->network_resells) {
            return response()->json([
                'message' => 'Network Stripe keys can be saved only while Module invoice settings → Network resells is on.',
            ], 422);
        }

        $validated = $request->validate([
            'hub_stripe_key' => ['sometimes', 'nullable', 'string', 'max:255'],
            'hub_stripe_secret' => ['sometimes', 'nullable', 'string', 'max:255'],
            'hub_stripe_webhook_secret' => ['sometimes', 'nullable', 'string', 'max:255'],
            'hub_stripe_currency' => ['sometimes', 'nullable', 'string', 'size:3'],
            'clear_hub_stripe_secret' => ['sometimes', 'boolean'],
            'clear_hub_stripe_webhook_secret' => ['sometimes', 'boolean'],
        ]);

        if ($validated === []) {
            return response()->json(['message' => 'No Stripe fields provided.'], 422);
        }

        try {
            $this->paymentSettings->update($validated, $hub);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $hub = $hub->fresh();

        return response()->json([
            'message' => 'Network Stripe settings saved.',
            'hub' => [
                'id' => $hub->id,
                'name' => $hub->name,
                'slug' => $hub->slug,
                'type' => $hub->type,
                'stripe' => $hub->stripeConfigForAdmin(),
            ],
        ]);
    }

    private function assertCanManage(Request $request): Hub
    {
        $hub = $this->actingHubs->targetHub(
            $request->user(),
            $request->integer('hub_id') ?: null
        );
        $user = $request->user();
        if (! $user || ! $this->matrix->userCan($hub, $user, self::CAPABILITY)) {
            abort(response()->json([
                'message' => 'Enable “Manage network Stripe keys” in Power Admin → Capabilities.',
            ], 403));
        }

        return $hub;
    }
}
