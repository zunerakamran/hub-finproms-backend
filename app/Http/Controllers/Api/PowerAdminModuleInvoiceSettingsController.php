<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ActingHubService;
use App\Services\ModuleInvoiceSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class PowerAdminModuleInvoiceSettingsController extends Controller
{
    public function __construct(
        private readonly ModuleInvoiceSettingsService $settings,
        private readonly ActingHubService $actingHubs
    ) {}

    public function show(Request $request): JsonResponse
    {
        $hub = $this->actingHubs->targetHub($request->user());
        $row = $this->settings->forHub($hub);

        return response()->json([
            'settings' => $this->settings->serialize($row),
            'target_hub' => [
                'id' => $hub->id,
                'name' => $hub->name,
                'slug' => $hub->slug,
                'type' => $hub->type,
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $hub = $this->actingHubs->targetHub($request->user());

        $validated = $request->validate([
            'network_pays_all' => ['sometimes', 'boolean'],
            'firms_pay_own' => ['sometimes', 'boolean'],
            'users_pay_own' => ['sometimes', 'boolean'],
            'network_resells' => ['sometimes', 'boolean'],
            'reseller_invoice_text' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'reseller_invoice_format' => ['sometimes', 'string', 'in:standard,compact,detailed'],
            'clear_reseller_logo' => ['sometimes', 'boolean'],
            'reseller_logo' => ['sometimes', 'nullable', 'file', 'image', 'max:4096'],
        ]);

        // FormData sends booleans as "true"/"false" strings — normalize.
        foreach (['network_pays_all', 'firms_pay_own', 'users_pay_own', 'network_resells', 'clear_reseller_logo'] as $boolKey) {
            if ($request->exists($boolKey)) {
                $validated[$boolKey] = $request->boolean($boolKey);
            }
        }

        try {
            $row = $this->settings->update(
                $hub,
                $validated,
                $request->file('reseller_logo')
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Module invoice settings updated.',
            'settings' => $this->settings->serialize($row),
        ]);
    }
}
