<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Hub;
use App\Models\Setting;
use App\Services\HubService;
use App\Services\PaymentSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PowerAdminPaymentMethodController extends Controller
{
    public function __construct(
        private readonly PaymentSettingsService $paymentSettings,
        private readonly HubService $hubs
    ) {}

    public function index(Request $request): JsonResponse
    {
        $hub = $this->resolveHub($request);

        return response()->json([
            'payment_methods' => $this->paymentSettings->methods($hub),
            'hub' => [
                'id' => $hub->id,
                'name' => $hub->name,
                'slug' => $hub->slug,
                'type' => $hub->type,
                'stripe' => $hub->stripeConfigForAdmin(),
            ],
            'hubs' => Hub::query()
                ->orderByRaw('CASE WHEN type = ? THEN 0 ELSE 1 END', [Hub::TYPE_SHARED])
                ->orderBy('name')
                ->get(['id', 'name', 'slug', 'type'])
                ->values(),
            'platform_stripe' => $this->platformStripeForAdmin(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'hub_id' => ['sometimes', 'nullable', 'integer', 'exists:hubs,id'],
            'stripe_enabled' => ['sometimes', 'boolean'],
            'bank_transfer_enabled' => ['sometimes', 'boolean'],
            'bank_transfer_auto_confirm' => ['sometimes', 'boolean'],
            'stripe_key' => ['sometimes', 'nullable', 'string', 'max:255'],
            'stripe_secret' => ['sometimes', 'nullable', 'string', 'max:255'],
            'stripe_webhook_secret' => ['sometimes', 'nullable', 'string', 'max:255'],
            'stripe_currency' => ['sometimes', 'nullable', 'string', 'size:3'],
            'clear_stripe_secret' => ['sometimes', 'boolean'],
            'clear_stripe_webhook_secret' => ['sometimes', 'boolean'],
            'hub_stripe_key' => ['sometimes', 'nullable', 'string', 'max:255'],
            'hub_stripe_secret' => ['sometimes', 'nullable', 'string', 'max:255'],
            'hub_stripe_webhook_secret' => ['sometimes', 'nullable', 'string', 'max:255'],
            'hub_stripe_currency' => ['sometimes', 'nullable', 'string', 'size:3'],
            'clear_hub_stripe_secret' => ['sometimes', 'boolean'],
            'clear_hub_stripe_webhook_secret' => ['sometimes', 'boolean'],
        ]);

        if ($validated === []) {
            return response()->json([
                'message' => 'No payment method settings provided.',
            ], 422);
        }

        $hub = $this->resolveHub($request);
        $methods = $this->paymentSettings->update($validated, $hub);
        $hub = $hub->fresh();

        return response()->json([
            'message' => 'Payment methods updated successfully.',
            'payment_methods' => $methods,
            'hub' => [
                'id' => $hub->id,
                'name' => $hub->name,
                'slug' => $hub->slug,
                'type' => $hub->type,
                'stripe' => $hub->stripeConfigForAdmin(),
            ],
            'platform_stripe' => $this->platformStripeForAdmin(),
        ]);
    }

    private function resolveHub(Request $request): Hub
    {
        $hubId = $request->input('hub_id');
        if ($hubId) {
            return Hub::query()->findOrFail((int) $hubId);
        }

        return $this->hubs->current();
    }

    /**
     * @return array<string, mixed>
     */
    private function platformStripeForAdmin(): array
    {
        return [
            'key' => Setting::getValue(Setting::KEY_STRIPE_KEY, null),
            'secret_set' => filled(Setting::getValue(Setting::KEY_STRIPE_SECRET, null)),
            'webhook_secret_set' => filled(Setting::getValue(Setting::KEY_STRIPE_WEBHOOK_SECRET, null)),
            'currency' => Setting::getValue(Setting::KEY_STRIPE_CURRENCY, null)
                ?: (config('services.stripe.currency') ?: 'gbp'),
            'env_secret_set' => filled(config('services.stripe.secret')),
            'env_webhook_set' => filled(config('services.stripe.webhook_secret')),
        ];
    }
}
