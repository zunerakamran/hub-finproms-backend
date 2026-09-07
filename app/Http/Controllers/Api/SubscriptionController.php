<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionPlan;
use App\Services\StripeSubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Stripe\Exception\ApiErrorException;

class SubscriptionController extends Controller
{
    public function __construct(
        private readonly StripeSubscriptionService $stripeSubscriptions
    ) {}

    public function plans(): JsonResponse
    {
        $plans = SubscriptionPlan::query()
            ->where('is_active', true)
            ->orderBy('price')
            ->get();

        return response()->json([
            'plans' => $plans,
        ]);
    }

    public function checkout(Request $request, SubscriptionPlan $plan): JsonResponse
    {
        if (! $plan->is_active) {
            return response()->json([
                'message' => 'This subscription plan is not available.',
            ], 422);
        }

        if (! config('services.stripe.secret')) {
            return response()->json([
                'message' => 'Stripe is not configured. Add STRIPE_SECRET to your .env file.',
            ], 500);
        }

        try {
            $session = $this->stripeSubscriptions->createCheckoutSession($request->user(), $plan);
        } catch (ApiErrorException $e) {
            return response()->json([
                'message' => 'Unable to start Stripe checkout.',
                'error' => $e->getMessage(),
            ], 502);
        }

        return response()->json([
            'checkout_url' => $session->url,
            'session_id' => $session->id,
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

        return response()->json([
            'message' => 'Subscription activated. Credits have been added.',
            'subscription' => $subscription->load('plan'),
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
}
