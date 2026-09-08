<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\PostPurchase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PurchaseController extends Controller
{
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

        if ($user->credits < $post->credits_cost) {
            return response()->json([
                'message' => 'Insufficient credits. Please buy a subscription to get more credits.',
                'credits' => $user->credits,
                'required' => $post->credits_cost,
            ], 422);
        }

        $purchase = DB::transaction(function () use ($user, $post) {
            $user->decrement('credits', $post->credits_cost);

            $purchase = PostPurchase::create([
                'user_id' => $user->id,
                'post_id' => $post->id,
                'credits_spent' => $post->credits_cost,
                'purchased_at' => now(),
            ]);

            $post->increment('buy_count');

            return $purchase;
        });

        $user->refresh();
        $post->refresh();
        $post->load('creator:id,name');
        $post->setAttribute('is_purchased', true);

        return response()->json([
            'message' => 'Post purchased successfully.',
            'purchase' => $purchase,
            'post' => $post,
            'user' => $user,
        ], 201);
    }

    public function myPurchases(Request $request): JsonResponse
    {
        $purchases = $request->user()
            ->purchases()
            ->with(['post.creator:id,name'])
            ->latest('purchased_at')
            ->paginate((int) $request->integer('per_page', 12));

        return response()->json($purchases);
    }
}
