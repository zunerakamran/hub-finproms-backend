<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdvisorPricingTier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Retired — module recurring slot tables replace advisor pricing tiers.
 */
class PowerAdminAdvisorPricingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return $this->retired();
    }

    public function store(Request $request): JsonResponse
    {
        return $this->retired();
    }

    public function update(Request $request, AdvisorPricingTier $tier): JsonResponse
    {
        return $this->retired();
    }

    public function destroy(AdvisorPricingTier $tier): JsonResponse
    {
        return $this->retired();
    }

    private function retired(): JsonResponse
    {
        return response()->json([
            'message' => 'Advisor pricing tiers are retired. Use Modules → Module prices (recurring slots) instead.',
            'retired' => true,
            'tiers' => [],
        ], 410);
    }
}
