<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PaymentSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PowerAdminPaymentMethodController extends Controller
{
    public function __construct(
        private readonly PaymentSettingsService $paymentSettings
    ) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'payment_methods' => $this->paymentSettings->methods(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'stripe_enabled' => ['sometimes', 'boolean'],
            'bank_transfer_enabled' => ['sometimes', 'boolean'],
            'bank_transfer_auto_confirm' => ['sometimes', 'boolean'],
        ]);

        if ($validated === []) {
            return response()->json([
                'message' => 'No payment method settings provided.',
            ], 422);
        }

        $methods = $this->paymentSettings->update($validated);

        return response()->json([
            'message' => 'Payment methods updated successfully.',
            'payment_methods' => $methods,
        ]);
    }
}
