<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bundle;
use App\Models\BundlePurchase;
use App\Models\ContentCheckout;
use App\Models\Post;
use App\Models\PostPurchase;
use App\Services\ContentPurchaseCheckoutService;
use App\Services\HubService;
use App\Services\InvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Stripe\Exception\ApiErrorException;

class PurchaseController extends Controller
{
    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly HubService $hubs,
        private readonly ContentPurchaseCheckoutService $contentCheckouts
    ) {}

    public function purchasePost(Request $request, Post $post): JsonResponse
    {
        if (! $post->is_active) {
            return response()->json([
                'message' => 'This post is not available for purchase.',
            ], 422);
        }

        $user = $request->user();

        if ($user->hasPurchased($post)) {
            return response()->json([
                'message' => 'You already purchased this post.',
                'post' => $post,
            ], 422);
        }

        if ($request->filled('payment_method')) {
            return $this->checkoutWithPaymentMethod($request, fn (string $method) => $this->contentCheckouts->checkoutPost($user, $post, $method));
        }

        $hubUnlimited = $this->hubs->can('unlimited_credits');
        $unlimited = $user->hasUnlimitedCredits($hubUnlimited);
        $hasSubscription = $user->hasActiveSubscription();

        if (! $hasSubscription && ! $unlimited && ! $this->hubs->can('one_off_purchase')) {
            return response()->json([
                'message' => 'One-off purchases are disabled for this hub. An active subscription is required.',
            ], 403);
        }

        if (! $unlimited && $user->credits < $post->credits_cost) {
            return response()->json([
                'message' => 'Insufficient credits. Pay with an enabled payment method, or buy a plan — 1 credit = £1.',
                'credits' => $user->credits,
                'required' => $post->credits_cost,
                'payment_methods' => $this->hubs->can('one_off_purchase')
                    ? $this->contentCheckouts->availablePaymentMethods()
                    : [],
            ], 422);
        }

        $creditsToSpend = $unlimited ? 0 : $post->credits_cost;

        [$purchase, $invoice] = DB::transaction(function () use ($user, $post, $creditsToSpend, $unlimited) {
            if (! $unlimited && $creditsToSpend > 0) {
                $user->decrement('credits', $creditsToSpend);
            }

            $purchase = PostPurchase::create([
                'user_id' => $user->id,
                'post_id' => $post->id,
                'credits_spent' => $creditsToSpend,
                'purchased_at' => now(),
            ]);

            $post->increment('buy_count');

            $invoice = $this->invoices->createForPostPurchase($purchase);

            return [$purchase, $invoice];
        });

        $user->refresh();
        $post->refresh();
        $post->load('creator:id,name');
        $post->setAttribute('is_purchased', true);

        return response()->json([
            'message' => 'Post purchased successfully.',
            'purchase' => $purchase,
            'invoice' => $invoice,
            'post' => $post,
            'user' => $user,
        ], 201);
    }

    public function purchaseBundle(Request $request, Bundle $bundle): JsonResponse
    {
        if (! $bundle->is_active) {
            return response()->json([
                'message' => 'This bundle is not available for purchase.',
            ], 422);
        }

        $bundle->load('posts');

        if ($bundle->posts->isEmpty()) {
            return response()->json([
                'message' => 'This bundle has no posts.',
            ], 422);
        }

        $user = $request->user();

        if ($user->hasPurchasedBundle($bundle)) {
            return response()->json([
                'message' => 'You already purchased this bundle.',
                'bundle' => $bundle,
            ], 422);
        }

        if ($request->filled('payment_method')) {
            return $this->checkoutWithPaymentMethod($request, fn (string $method) => $this->contentCheckouts->checkoutBundle($user, $bundle, $method));
        }

        $hubUnlimited = $this->hubs->can('unlimited_credits');
        $unlimited = $user->hasUnlimitedCredits($hubUnlimited);
        $hasSubscription = $user->hasActiveSubscription();

        if (! $hasSubscription && ! $unlimited && ! $this->hubs->can('one_off_purchase')) {
            return response()->json([
                'message' => 'One-off purchases are disabled for this hub. An active subscription is required.',
            ], 403);
        }

        if (! $unlimited && $user->credits < $bundle->credits_cost) {
            return response()->json([
                'message' => 'Insufficient credits. Pay with an enabled payment method, or buy a plan — 1 credit = £1.',
                'credits' => $user->credits,
                'required' => $bundle->credits_cost,
                'payment_methods' => $this->hubs->can('one_off_purchase')
                    ? $this->contentCheckouts->availablePaymentMethods()
                    : [],
            ], 422);
        }

        $creditsToSpend = $unlimited ? 0 : $bundle->credits_cost;

        [$purchase, $invoice] = DB::transaction(function () use ($user, $bundle, $creditsToSpend, $unlimited) {
            if (! $unlimited && $creditsToSpend > 0) {
                $user->decrement('credits', $creditsToSpend);
            }

            $purchase = BundlePurchase::create([
                'user_id' => $user->id,
                'bundle_id' => $bundle->id,
                'credits_spent' => $creditsToSpend,
                'purchased_at' => now(),
            ]);

            foreach ($bundle->posts as $post) {
                if ($user->hasPurchased($post)) {
                    continue;
                }

                PostPurchase::create([
                    'user_id' => $user->id,
                    'post_id' => $post->id,
                    'credits_spent' => 0,
                    'purchased_at' => now(),
                ]);

                $post->increment('buy_count');
            }

            $bundle->increment('buy_count');

            $invoice = $this->invoices->createForBundlePurchase($purchase);

            return [$purchase, $invoice];
        });

        $user->refresh();
        $bundle->refresh();
        $bundle->load(['creator:id,name', 'posts.creator:id,name']);
        $bundle->loadCount('posts');
        $bundle->setAttribute('is_purchased', true);

        return response()->json([
            'message' => 'Bundle purchased successfully.',
            'purchase' => $purchase,
            'invoice' => $invoice,
            'bundle' => $bundle,
            'user' => $user,
        ], 201);
    }

    public function confirm(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'session_id' => ['required', 'string'],
        ]);

        try {
            $checkout = $this->contentCheckouts->fulfillStripeSession($validated['session_id']);
        } catch (ApiErrorException $e) {
            return response()->json([
                'message' => 'Unable to confirm Stripe payment.',
                'error' => $e->getMessage(),
            ], 502);
        }

        if (! $checkout) {
            return response()->json([
                'message' => 'Payment not completed yet.',
            ], 422);
        }

        if ($checkout->user_id !== $request->user()->id && ! $request->user()->isAdmin()) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        return $this->paidCheckoutResponse($checkout, 'Content unlocked. Payment confirmed.');
    }

    public function confirmContentBankTransfer(Request $request, ContentCheckout $checkout): JsonResponse
    {
        if ($checkout->payment_method !== 'bank_transfer') {
            return response()->json([
                'message' => 'This checkout was not paid by bank transfer.',
            ], 422);
        }

        if ($checkout->isPaid()) {
            return $this->paidCheckoutResponse($checkout, 'This bank transfer was already confirmed.');
        }

        try {
            $checkout = $this->contentCheckouts->markBankTransferPaid($checkout);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }

        return $this->paidCheckoutResponse($checkout, 'Bank transfer confirmed. Content unlocked.');
    }

    public function myPurchases(Request $request): JsonResponse
    {
        $user = $request->user();
        $hub = app(\App\Services\HubService::class)->current();
        $matrix = app(\App\Services\CapabilitiesMatrixService::class);
        $role = $matrix->effectiveRoleFor($user);

        if (! $matrix->roleCan($hub, $role, 'general_show_purchases')) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $purchases = $user
            ->purchases()
            ->with(['post.creator:id,name'])
            ->latest('purchased_at')
            ->paginate((int) $request->integer('per_page', 12));

        return response()->json($purchases);
    }

    /**
     * @param  callable(string): array<string, mixed>  $checkout
     */
    private function checkoutWithPaymentMethod(Request $request, callable $checkout): JsonResponse
    {
        $validated = $request->validate([
            'payment_method' => ['required', 'string', 'in:stripe,bank_transfer'],
        ]);

        try {
            $result = $checkout($validated['payment_method']);
        } catch (\InvalidArgumentException $e) {
            $status = str_contains(strtolower($e->getMessage()), 'disabled') ? 403 : 422;

            return response()->json(['message' => $e->getMessage()], $status);
        } catch (ApiErrorException $e) {
            return response()->json([
                'message' => 'Unable to start Stripe checkout.',
                'error' => $e->getMessage(),
            ], 502);
        } catch (\RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }

        if (($result['payment_method'] ?? null) === 'bank_transfer' && ($result['auto_confirmed'] ?? false)) {
            $checkoutModel = $result['checkout'] ?? null;
            if ($checkoutModel instanceof ContentCheckout) {
                $payload = $this->paidCheckoutResponse($checkoutModel, $result['message'] ?? 'Content unlocked.')->getData(true);

                return response()->json(array_merge($payload, [
                    'payment_method' => 'bank_transfer',
                    'auto_confirmed' => true,
                    'bank_details' => $result['bank_details'] ?? null,
                    'payment_reference' => $result['payment_reference'] ?? null,
                    'amount' => $result['amount'] ?? null,
                ]), 201);
            }
        }

        return response()->json($result, ($result['payment_method'] ?? null) === 'stripe' ? 200 : 201);
    }

    private function paidCheckoutResponse(ContentCheckout $checkout, string $message): JsonResponse
    {
        $checkout->loadMissing(['user', 'postPurchase.post.creator:id,name', 'bundlePurchase.bundle.posts.creator:id,name', 'bundlePurchase.bundle.creator:id,name']);

        $invoice = null;
        $payload = [
            'message' => $message,
            'checkout' => $checkout,
            'user' => $checkout->user,
        ];

        if ($checkout->isBundle() && $checkout->bundlePurchase) {
            $bundle = $checkout->bundlePurchase->bundle;
            $bundle?->loadCount('posts');
            $bundle?->setAttribute('is_purchased', true);
            $invoice = $this->invoices->createForBundlePurchase($checkout->bundlePurchase);
            $payload['purchase'] = $checkout->bundlePurchase;
            $payload['bundle'] = $bundle;
            $payload['invoice'] = $invoice;
        }

        if ($checkout->isPost() && $checkout->postPurchase) {
            $post = $checkout->postPurchase->post;
            $post?->setAttribute('is_purchased', true);
            $invoice = $this->invoices->createForPostPurchase($checkout->postPurchase);
            $payload['purchase'] = $checkout->postPurchase;
            $payload['post'] = $post;
            $payload['invoice'] = $invoice;
        }

        return response()->json($payload);
    }
}
