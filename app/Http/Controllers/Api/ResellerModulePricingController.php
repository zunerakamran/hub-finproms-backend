<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Hub;
use App\Services\ActingHubService;
use App\Services\CapabilitiesMatrixService;
use App\Services\ModuleInvoiceSettingsService;
use App\Services\ModuleResellerPricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class ResellerModulePricingController extends Controller
{
    public const CAPABILITY = 'dashboard_manage_reseller_module_pricing';

    public function __construct(
        private readonly ActingHubService $actingHubs,
        private readonly CapabilitiesMatrixService $matrix,
        private readonly ModuleResellerPricingService $resellerPricing,
        private readonly ModuleInvoiceSettingsService $invoiceSettings
    ) {}

    public function index(Request $request): JsonResponse
    {
        $hub = $this->assertCanManage($request);
        $settings = $this->invoiceSettings->forHub($hub);

        return response()->json([
            'pricing' => $this->resellerPricing->listForHub($hub),
            'network_resells' => (bool) $settings->network_resells,
            'message' => $settings->network_resells
                ? null
                : 'Turn on Network resells under Module invoice settings to set network→user prices.',
            'formula' => 'Only modules whose main Module prices use per user / per user (slots) appear here. Edit the user one-time amount only when the main one-time unit is per user; edit recurring only when the main recurring unit is per user. Amounts here are what users pay the network. The network still pays FinProms at the main Module prices.',
            'target_hub' => [
                'id' => $hub->id,
                'name' => $hub->name,
                'slug' => $hub->slug,
                'type' => $hub->type,
            ],
        ]);
    }

    public function update(Request $request, string $moduleKey): JsonResponse
    {
        $hub = $this->assertCanManage($request);
        $validated = $request->validate([
            'amount' => ['sometimes', 'numeric', 'min:0'],
            'recurring_amount' => ['sometimes', 'numeric', 'min:0'],
            'currency' => ['sometimes', 'nullable', 'string', 'size:3'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        try {
            $row = $this->resellerPricing->upsert($hub, $moduleKey, $validated);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Reseller module price saved.',
            'pricing' => $row,
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
                'message' => 'Enable “Set reseller module prices” in Power Admin → Capabilities.',
            ], 403));
        }

        return $hub;
    }
}
