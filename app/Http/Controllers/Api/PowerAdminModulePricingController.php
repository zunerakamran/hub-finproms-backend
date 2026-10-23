<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ModulePricing;
use App\Services\ActingHubService;
use App\Services\ModulePricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PowerAdminModulePricingController extends Controller
{
    public function __construct(
        private readonly ModulePricingService $pricing,
        private readonly ActingHubService $actingHubs
    ) {}

    public function index(Request $request): JsonResponse
    {
        $hub = $this->actingHubs->targetHub($request->user());

        return response()->json([
            'pricing' => $this->pricing->listPricingForHub($hub),
            'recurring_tiers' => $this->pricing->listRecurringTiers(),
            'formula' => 'one_time on module enable; catalogue filtered to this hub type; recurring Option B = rate(total active users with module) × batch',
            'target_hub' => [
                'id' => $hub->id,
                'name' => $hub->name,
                'slug' => $hub->slug,
                'type' => $hub->type,
                'charge_amount_per_module' => $hub->can('charge_amount_per_module'),
                'charge_recurring_per_module' => $hub->can('charge_recurring_per_module'),
                'module_keys' => $hub->moduleKeysForPage(),
            ],
        ]);
    }

    public function update(Request $request, ModulePricing $pricing): JsonResponse
    {
        $hub = $this->actingHubs->targetHub($request->user());
        if (! $this->pricing->pricingAppliesToHub($pricing, $hub)) {
            return response()->json([
                'message' => sprintf(
                    '“%s” pricing does not apply to this %s hub. Switch hub type / acting hub to edit it.',
                    $pricing->module_key,
                    $hub->type
                ),
            ], 422);
        }

        $validated = $request->validate([
            'amount' => ['sometimes', 'numeric', 'min:0'],
            'billing_unit' => ['sometimes', 'string', 'in:one_time,per_website'],
            'recurring_amount' => ['sometimes', 'numeric', 'min:0'],
            'recurring_billing_unit' => ['sometimes', 'string', 'in:none,per_network,per_adviser,per_user,per_website,per_firm'],
            'recurring_tier_slot' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:3'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);

        $row = $this->pricing->updatePricing($pricing, $validated);

        return response()->json([
            'message' => 'Module pricing updated.',
            'pricing' => $this->pricing->serialize($row),
        ]);
    }

    public function updateTier(Request $request, \App\Models\ModuleRecurringTier $tier): JsonResponse
    {
        $validated = $request->validate([
            'min_users' => ['sometimes', 'integer', 'min:0'],
            'max_users' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'rate_per_user' => ['sometimes', 'numeric', 'min:0'],
            'network_margin_per_user' => ['sometimes', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);

        $row = $this->pricing->updateRecurringTier($tier, $validated);

        return response()->json([
            'message' => 'Recurring tier updated.',
            'tier' => [
                'id' => $row->id,
                'slot' => (int) $row->slot,
                'min_users' => (int) $row->min_users,
                'max_users' => $row->max_users !== null ? (int) $row->max_users : null,
                'rate_per_user' => (float) $row->rate_per_user,
                'network_margin_per_user' => (float) $row->network_margin_per_user,
                'is_active' => (bool) $row->is_active,
            ],
        ]);
    }
}
