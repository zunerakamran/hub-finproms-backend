<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bundle;
use App\Models\BundlePurchase;
use App\Models\Post;
use App\Models\PostPurchase;
use App\Services\HubService;
use App\Services\InvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PurchaseController extends Controller
{
    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly HubService $hubs
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
                'message' => 'Insufficient credits. Buy a plan or top up — 1 credit = £1.',
                'credits' => $user->credits,
                'required' => $post->credits_cost,
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
                'message' => 'Insufficient credits. Buy a plan or top up — 1 credit = £1.',
                'credits' => $user->credits,
                'required' => $bundle->credits_cost,
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

    public function myPurchases(Request $request): JsonResponse
    {
        $user = $request->user();
        $hub = app(\App\Services\HubService::class)->current();
        $matrix = app(\App\Services\CapabilitiesMatrixService::class);
        $role = (string) $user->role;

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
}
