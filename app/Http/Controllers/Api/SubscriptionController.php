<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionPlan;
use App\Models\UserSubscription;
use App\Services\BankTransferSubscriptionService;
use App\Services\HubService;
use App\Services\InvoiceService;
use App\Services\PaymentSettingsService;
use App\Services\StripeSubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Stripe\Exception\ApiErrorException;

class SubscriptionController extends Controller
{
    public function __construct(
        private readonly StripeSubscriptionService $stripeSubscriptions,
        private readonly BankTransferSubscriptionService $bankTransferSubscriptions,
        private readonly InvoiceService $invoices,
        private readonly PaymentSettingsService $paymentSettings,
        private readonly HubService $hubs
    ) {}

    public function plans(): JsonResponse
    {
        $plans = SubscriptionPlan::query()
            ->where('is_active', true)
            ->orderBy('price')
            ->get();

        return response()->json([
            'plans' => $plans,
            'payment_methods' => $this->availablePaymentMethods(),
        ]);
    }

    public function adminPlans(): JsonResponse
    {
        $plans = SubscriptionPlan::query()
            ->orderBy('price')
            ->get();

        return response()->json([
            'plans' => $plans,
        ]);
    }

    public function storePlan(Request $request): JsonResponse
    {
        $validated = $this->validatePlan($request);

        if ($path = $this->storePlanImage($request)) {
            $validated['image_path'] = $path;
        }

        $plan = SubscriptionPlan::create($validated);

        return response()->json([
            'message' => 'Subscription plan created successfully.',
            'plan' => $plan,
        ], 201);
    }

    public function updatePlan(Request $request, SubscriptionPlan $plan): JsonResponse
    {
        $validated = $this->validatePlan($request, updating: true);

        if ($request->hasFile('image')) {
            if ($plan->image_path) {
                Storage::disk('public')->delete($plan->image_path);
            }
            $validated['image_path'] = $this->storePlanImage($request);
        }

        $plan->fill($validated);
        $plan->save();

        return response()->json([
            'message' => 'Subscription plan updated successfully.',
            'plan' => $plan->fresh(),
        ]);
    }

    public function destroyPlan(SubscriptionPlan $plan): JsonResponse
    {
        if ($plan->subscriptions()->exists()) {
            return response()->json([
                'message' => 'Cannot delete a plan that has subscriptions. Deactivate it instead.',
            ], 422);
        }

        $plan->delete();

        return response()->json([
            'message' => 'Subscription plan deleted successfully.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePlan(Request $request, bool $updating = false): array
    {
        $required = $updating ? 'sometimes' : 'required';

        foreach (['features', 'benefits'] as $listField) {
            if (! $request->has($listField)) {
                continue;
            }

            $value = $request->input($listField);
            if (is_string($value)) {
                $decoded = json_decode($value, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $request->merge([$listField => $decoded]);
                } else {
                    $lines = array_values(array_filter(array_map(
                        static fn (string $line): string => trim($line),
                        preg_split('/\r\n|\r|\n/', $value) ?: []
                    ), static fn (string $line): bool => $line !== ''));
                    $request->merge([$listField => $lines]);
                }
            }
        }

        $validated = $request->validate([
            'name' => [$required, 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'overview' => ['nullable', 'string'],
            'features' => ['nullable', 'array'],
            'features.*' => ['string', 'max:500'],
            'benefits' => ['nullable', 'array'],
            'benefits.*' => ['string', 'max:500'],
            'price' => [$required, 'numeric', 'min:0.01'],
            'credits' => [$required, 'integer', 'min:1'],
            'duration_days' => [$required, 'integer', 'min:1'],
            'is_active' => ['sometimes', 'boolean'],
            'image' => [
                $updating ? 'sometimes' : 'nullable',
                'image',
                'max:5120',
                'mimes:jpg,jpeg,png,gif,webp',
            ],
        ]);

        unset($validated['image']);

        if ($request->has('is_active')) {
            $validated['is_active'] = filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN);
        } elseif (! $updating) {
            $validated['is_active'] = true;
        }

        return $validated;
    }

    private function storePlanImage(Request $request): ?string
    {
        if (! $request->hasFile('image')) {
            return null;
        }

        return $request->file('image')->store('subscription-plans', 'public');
    }

    public function checkout(Request $request, SubscriptionPlan $plan): JsonResponse
    {
        if (! $this->hubs->can('public_subscribe') && ! $this->hubs->can('paid_credits')) {
            return response()->json([
                'message' => 'Self-serve subscriptions are disabled for this hub.',
            ], 403);
        }

        if (! $plan->is_active) {
            return response()->json([
                'message' => 'This subscription plan is not available.',
            ], 422);
        }

        $validated = $request->validate([
            'payment_method' => ['required', 'string', 'in:stripe,bank_transfer'],
        ]);

        $paymentMethod = $validated['payment_method'];
        $methods = collect($this->availablePaymentMethods())->keyBy('id');

        if (! ($methods[$paymentMethod]['available'] ?? false)) {
            return response()->json([
                'message' => $methods[$paymentMethod]['unavailable_reason']
                    ?? 'This payment method is not available.',
            ], 422);
        }

        if ($paymentMethod === 'bank_transfer') {
            return $this->checkoutBankTransfer($request, $plan);
        }

        return $this->checkoutStripe($request, $plan);
    }

    private function checkoutStripe(Request $request, SubscriptionPlan $plan): JsonResponse
    {
        try {
            $session = $this->stripeSubscriptions->createCheckoutSession($request->user(), $plan);
        } catch (ApiErrorException $e) {
            return response()->json([
                'message' => 'Unable to start Stripe checkout.',
                'error' => $e->getMessage(),
            ], 502);
        }

        return response()->json([
            'payment_method' => 'stripe',
            'checkout_url' => $session->url,
            'session_id' => $session->id,
        ]);
    }

    private function checkoutBankTransfer(Request $request, SubscriptionPlan $plan): JsonResponse
    {
        $subscription = $this->bankTransferSubscriptions->createPendingSubscription(
            $request->user(),
            $plan
        );

        $autoConfirmed = $subscription->payment_status === 'paid';
        $invoice = $autoConfirmed
            ? $this->invoices->createForSubscription($subscription)
            : null;

        return response()->json([
            'payment_method' => 'bank_transfer',
            'auto_confirmed' => $autoConfirmed,
            'message' => $autoConfirmed
                ? 'Test bank transfer completed. Credits have been added.'
                : 'Bank transfer order created. Use the reference below when paying.',
            'subscription' => $subscription->load('plan'),
            'invoice' => $invoice,
            'bank_details' => $this->bankTransferSubscriptions->bankDetails(),
            'payment_reference' => $subscription->payment_reference,
            'amount' => (string) $subscription->amount_paid,
            'user' => $request->user()->fresh(),
        ]);
    }

    public function confirm(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'session_id' => ['required', 'string'],
        ]);

        try {
            $subscription = $this->stripeSubscriptions->fulfillCheckoutSession($validated['session_id']);
        } catch (ApiErrorException $e) {
            return response()->json([
                'message' => 'Unable to confirm Stripe payment.',
                'error' => $e->getMessage(),
            ], 502);
        }

        if (! $subscription) {
            return response()->json([
                'message' => 'Payment not completed yet.',
            ], 422);
        }

        if ($subscription->user_id !== $request->user()->id && ! $request->user()->isAdmin()) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $invoice = $this->invoices->createForSubscription($subscription);

        return response()->json([
            'message' => 'Subscription activated. Credits have been added.',
            'subscription' => $subscription->load('plan'),
            'invoice' => $invoice,
            'user' => $request->user()->fresh(),
        ]);
    }

    public function mySubscriptions(Request $request): JsonResponse
    {
        $subscriptions = $request->user()
            ->subscriptions()
            ->with('plan')
            ->latest()
            ->get();

        return response()->json([
            'subscriptions' => $subscriptions,
            'credits' => $request->user()->credits,
        ]);
    }

    /**
     * TEMPORARY admin endpoints for bank transfer — remove with BANK_TRANSFER_ENABLED.
     */
    public function pendingBankTransfers(): JsonResponse
    {
        if (! $this->bankTransferSubscriptions->isEnabled()) {
            return response()->json([
                'message' => 'Bank transfer is disabled.',
            ], 404);
        }

        $subscriptions = UserSubscription::query()
            ->with(['plan', 'user:id,name,email'])
            ->where('payment_method', 'bank_transfer')
            ->where('payment_status', 'pending')
            ->latest()
            ->get();

        return response()->json([
            'subscriptions' => $subscriptions,
        ]);
    }

    public function confirmBankTransfer(Request $request, UserSubscription $subscription): JsonResponse
    {
        if (! $this->bankTransferSubscriptions->isEnabled()) {
            return response()->json([
                'message' => 'Bank transfer is disabled.',
            ], 404);
        }

        if ($subscription->payment_method !== 'bank_transfer') {
            return response()->json([
                'message' => 'This subscription was not paid by bank transfer.',
            ], 422);
        }

        if ($subscription->payment_status === 'paid') {
            return response()->json([
                'message' => 'This bank transfer was already confirmed.',
                'subscription' => $subscription->load('plan', 'user'),
            ]);
        }

        try {
            $subscription = $this->bankTransferSubscriptions->markPaidAndGrantCredits($subscription);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }

        $invoice = $this->invoices->createForSubscription($subscription);

        return response()->json([
            'message' => 'Bank transfer confirmed. Credits have been added.',
            'subscription' => $subscription,
            'invoice' => $invoice,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function availablePaymentMethods(): array
    {
        return $this->paymentSettings->publicMethods(
            fn () => $this->bankTransferSubscriptions->bankDetails()
        );
    }
}
