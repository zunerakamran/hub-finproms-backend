<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdvisorPricingTier;
use App\Services\ActingHubService;
use App\Services\AdvisorPricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PowerAdminAdvisorPricingController extends Controller
{
    public function __construct(
        private readonly AdvisorPricingService $pricing,
        private readonly ActingHubService $actingHubs
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->pricing->seedDefaultsIfEmpty();

        $hub = $this->actingHubs->targetHub($request->user());
        $tiers = $this->pricing->listTiers();
        $count = $hub->isWhiteLabel() && $hub->hasRemoteDatabaseConfigured()
            ? $this->pricing->currentAdvisorCountForHub($hub)
            : $this->pricing->currentAdvisorCount();
        $quote = $this->pricing->quote($count);

        return response()->json([
            'tiers' => $tiers,
            'current_quote' => $quote,
            'formula' => 'amount = rate_per_advisor × advisor_count',
            'target_hub' => [
                'id' => $hub->id,
                'name' => $hub->name,
                'slug' => $hub->slug,
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'label' => ['nullable', 'string', 'max:255'],
            'min_advisors' => ['required', 'integer', 'min:1'],
            'rate_per_advisor' => ['required', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);

        $tier = $this->pricing->createTier($validated);

        return response()->json([
            'message' => 'Pricing tier created.',
            'tier' => $tier,
        ], 201);
    }

    public function update(Request $request, AdvisorPricingTier $tier): JsonResponse
    {
        $validated = $request->validate([
            'label' => ['sometimes', 'nullable', 'string', 'max:255'],
            'min_advisors' => ['sometimes', 'integer', 'min:1'],
            'rate_per_advisor' => ['sometimes', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);

        $tier = $this->pricing->updateTier($tier, $validated);

        return response()->json([
            'message' => 'Pricing tier updated.',
            'tier' => $tier,
        ]);
    }

    public function destroy(AdvisorPricingTier $tier): JsonResponse
    {
        $this->pricing->deleteTier($tier);

        return response()->json([
            'message' => 'Pricing tier deleted.',
        ]);
    }
}
