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
            'pricing' => $this->pricing->listPricing(),
            'formula' => 'one_time amount per enabled module',
            'target_hub' => [
                'id' => $hub->id,
                'name' => $hub->name,
                'slug' => $hub->slug,
                'charge_amount_per_module' => $hub->can('charge_amount_per_module'),
            ],
        ]);
    }

    public function update(Request $request, ModulePricing $pricing): JsonResponse
    {
        $validated = $request->validate([
            'amount' => ['sometimes', 'numeric', 'min:0'],
            'billing_unit' => ['sometimes', 'string', 'in:one_time,per_website'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);

        $row = $this->pricing->updatePricing($pricing, $validated);

        return response()->json([
            'message' => 'Module pricing updated.',
            'pricing' => $this->pricing->serialize($row),
        ]);
    }
}
