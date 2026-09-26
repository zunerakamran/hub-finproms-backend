<?php

namespace App\Services;

use App\Models\Bundle;
use App\Models\BundlePurchase;
use App\Models\ContentCheckout;
use App\Models\Post;
use App\Models\PostPurchase;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Stripe\Checkout\Session;
use Stripe\Exception\ApiErrorException;

class ContentPurchaseCheckoutService
{
    private bool $apiKeySet = false;

    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly PaymentSettingsService $paymentSettings,
        private readonly HubService $hubs,
        private readonly BankTransferSubscriptionService $bankTransfer
    ) {}

    /**
     * Cash checkout (Stripe / bank transfer) is shared-hub only.
     * White-labelled hubs buy posts/reels/bundles with credits only.
     */
    public function cashPaymentsAllowed(): bool
    {
        $hub = $this->hubs->current();

        return $hub->isShared() && $this->hubs->can('one_off_purchase');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function availablePaymentMethods(): array
    {
        if (! $this->cashPaymentsAllowed()) {
            return [];
        }

        return $this->paymentSettings->publicMethods(
            fn () => $this->bankTransfer->bankDetails()
        );
    }

    public function oneOffPaymentsEnabled(): bool
    {
        return $this->cashPaymentsAllowed();
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ApiErrorException
     */
    public function checkoutPost(User $user, Post $post, string $paymentMethod): array
    {
        $this->assertCanCheckout($user, ContentCheckout::TYPE_POST, $post->id, $paymentMethod);

        if ($paymentMethod === 'bank_transfer') {
            return $this->checkoutBankTransfer($user, ContentCheckout::TYPE_POST, $post->id, (int) $post->credits_cost, $post->title);
        }

        return $this->checkoutStripe($user, ContentCheckout::TYPE_POST, $post->id, (int) $post->credits_cost, $post->title, 'Post');
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ApiErrorException
     */
    public function checkoutBundle(User $user, Bundle $bundle, string $paymentMethod): array
    {
        $this->assertCanCheckout($user, ContentCheckout::TYPE_BUNDLE, $bundle->id, $paymentMethod);

        $title = $bundle->title;
        if ($paymentMethod === 'bank_transfer') {
            return $this->checkoutBankTransfer($user, ContentCheckout::TYPE_BUNDLE, $bundle->id, (int) $bundle->credits_cost, $title);
        }

        return $this->checkoutStripe($user, ContentCheckout::TYPE_BUNDLE, $bundle->id, (int) $bundle->credits_cost, $title, 'Bundle');
    }

    public function fulfillStripeSession(string $sessionId): ?ContentCheckout
    {
        $this->ensureApiKey();
        $session = Session::retrieve($sessionId);

        if ($session->payment_status !== 'paid') {
            return null;
        }

        return $this->markPaidFromStripeSession($session);
    }

    public function markPaidFromStripeSession(Session $session): ?ContentCheckout
    {
        return DB::transaction(function () use ($session) {
            $checkout = ContentCheckout::query()
                ->where('stripe_session_id', $session->id)
                ->lockForUpdate()
                ->first();

            if (! $checkout) {
                $metaType = (string) ($session->metadata->type ?? '');
                if ($metaType !== 'content_purchase') {
                    return null;
                }

                $userId = (int) ($session->metadata->user_id ?? 0);
                $itemType = (string) ($session->metadata->item_type ?? '');
                $itemId = (int) ($session->metadata->item_id ?? 0);
                $creditsCost = (int) ($session->metadata->credits_cost ?? 0);

                if (! $userId || ! $itemId || ! in_array($itemType, [ContentCheckout::TYPE_POST, ContentCheckout::TYPE_BUNDLE], true)) {
                    return null;
                }

                $checkout = ContentCheckout::create([
                    'user_id' => $userId,
                    'item_type' => $itemType,
                    'item_id' => $itemId,
                    'credits_cost' => $creditsCost,
                    'amount' => $creditsCost,
                    'payment_method' => 'stripe',
                    'payment_status' => 'pending',
                    'stripe_session_id' => $session->id,
                ]);
            }

            if ($checkout->isPaid()) {
                return $checkout->load(['user', 'postPurchase.post', 'bundlePurchase.bundle']);
            }

            $checkout->stripe_payment_intent = is_string($session->payment_intent)
                ? $session->payment_intent
                : ($session->payment_intent->id ?? null);

            return $this->fulfillCheckout($checkout);
        });
    }

    public function markBankTransferPaid(ContentCheckout $checkout): ContentCheckout
    {
        return DB::transaction(function () use ($checkout) {
            $locked = ContentCheckout::query()
                ->whereKey($checkout->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->payment_method !== 'bank_transfer') {
                throw new \InvalidArgumentException('Checkout is not a bank transfer payment.');
            }

            if ($locked->isPaid()) {
                return $locked->load(['user', 'postPurchase.post', 'bundlePurchase.bundle']);
            }

            return $this->fulfillCheckout($locked);
        });
    }

    /**
     * @return list<ContentCheckout>
     */
    public function pendingBankTransfers(): array
    {
        return ContentCheckout::query()
            ->with(['user:id,name,email'])
            ->where('payment_method', 'bank_transfer')
            ->where('payment_status', 'pending')
            ->latest()
            ->get()
            ->all();
    }

    private function assertCanCheckout(User $user, string $itemType, int $itemId, string $paymentMethod): void
    {
        if (! $this->hubs->current()->isShared()) {
            throw new \InvalidArgumentException(
                'White-labelled hubs only support buying posts and bundles with credits.'
            );
        }

        if (! $this->hubs->can('one_off_purchase')) {
            throw new \InvalidArgumentException('One-off purchases are disabled for this hub. An active subscription is required.');
        }

        $methods = collect($this->availablePaymentMethods())->keyBy('id');
        if (! ($methods[$paymentMethod]['available'] ?? false)) {
            throw new \InvalidArgumentException(
                $methods[$paymentMethod]['unavailable_reason'] ?? 'This payment method is not available.'
            );
        }

        if ($itemType === ContentCheckout::TYPE_POST) {
            if ($user->hasPurchased(Post::query()->findOrFail($itemId))) {
                throw new \InvalidArgumentException('You already purchased this post.');
            }
        } else {
            if ($user->hasPurchasedBundle(Bundle::query()->findOrFail($itemId))) {
                throw new \InvalidArgumentException('You already purchased this bundle.');
            }
        }
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ApiErrorException
     */
    private function checkoutStripe(User $user, string $itemType, int $itemId, int $creditsCost, string $title, string $kindLabel): array
    {
        $this->ensureApiKey();
        $frontendUrl = $this->hubs->current()->frontendBaseUrl();
        $amountPence = (int) round($creditsCost * 100);

        $session = Session::create([
            'mode' => 'payment',
            'payment_method_types' => ['card'],
            'customer_email' => $user->email,
            'line_items' => [[
                'price_data' => [
                    'currency' => $this->paymentSettings->stripeCurrency(),
                    'product_data' => [
                        'name' => "{$kindLabel}: {$title}",
                        'description' => "One-off purchase — {$creditsCost} credits (£{$creditsCost})",
                    ],
                    'unit_amount' => $amountPence,
                ],
                'quantity' => 1,
            ]],
            'success_url' => $frontendUrl.'/purchases/success?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $frontendUrl.($itemType === ContentCheckout::TYPE_BUNDLE
                ? "/bundles/{$itemId}?canceled=1"
                : "/posts/{$itemId}?canceled=1"),
            'metadata' => [
                'type' => 'content_purchase',
                'user_id' => (string) $user->id,
                'item_type' => $itemType,
                'item_id' => (string) $itemId,
                'credits_cost' => (string) $creditsCost,
            ],
        ]);

        ContentCheckout::create([
            'user_id' => $user->id,
            'item_type' => $itemType,
            'item_id' => $itemId,
            'credits_cost' => $creditsCost,
            'amount' => $creditsCost,
            'payment_method' => 'stripe',
            'payment_status' => 'pending',
            'stripe_session_id' => $session->id,
        ]);

        return [
            'payment_method' => 'stripe',
            'checkout_url' => $session->url,
            'session_id' => $session->id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkoutBankTransfer(User $user, string $itemType, int $itemId, int $creditsCost, string $title): array
    {
        $checkout = ContentCheckout::create([
            'user_id' => $user->id,
            'item_type' => $itemType,
            'item_id' => $itemId,
            'credits_cost' => $creditsCost,
            'amount' => $creditsCost,
            'payment_method' => 'bank_transfer',
            'payment_status' => 'pending',
            'payment_reference' => $this->generateReference($user->id),
        ]);

        $autoConfirmed = false;
        $invoice = null;
        $purchase = null;

        if ($this->bankTransfer->autoConfirm()) {
            $checkout = $this->markBankTransferPaid($checkout);
            $autoConfirmed = true;
            $invoice = $checkout->isBundle()
                ? $this->invoices->createForBundlePurchase($checkout->bundlePurchase)
                : $this->invoices->createForPostPurchase($checkout->postPurchase);
            $purchase = $checkout->isBundle() ? $checkout->bundlePurchase : $checkout->postPurchase;
        }

        return [
            'payment_method' => 'bank_transfer',
            'auto_confirmed' => $autoConfirmed,
            'message' => $autoConfirmed
                ? 'Test bank transfer completed. Content unlocked.'
                : 'Bank transfer order created. Use the reference below when paying.',
            'checkout' => $checkout->fresh()->load(['user']),
            'purchase' => $purchase,
            'invoice' => $invoice,
            'bank_details' => $this->bankTransfer->bankDetails(),
            'payment_reference' => $checkout->payment_reference,
            'amount' => (string) $checkout->amount,
            'item_type' => $itemType,
            'item_title' => $title,
            'user' => $user->fresh(),
        ];
    }

    private function fulfillCheckout(ContentCheckout $checkout): ContentCheckout
    {
        $user = User::query()->whereKey($checkout->user_id)->lockForUpdate()->firstOrFail();

        if ($checkout->isBundle()) {
            $bundle = Bundle::query()->with('posts')->findOrFail($checkout->item_id);

            if ($user->hasPurchasedBundle($bundle)) {
                $existing = BundlePurchase::query()
                    ->where('user_id', $user->id)
                    ->where('bundle_id', $bundle->id)
                    ->firstOrFail();

                $checkout->update([
                    'payment_status' => 'paid',
                    'paid_at' => now(),
                    'bundle_purchase_id' => $existing->id,
                ]);

                return $checkout->fresh()->load(['user', 'bundlePurchase.bundle']);
            }

            $purchase = BundlePurchase::create([
                'user_id' => $user->id,
                'bundle_id' => $bundle->id,
                'credits_spent' => $checkout->credits_cost,
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
            $this->invoices->createForBundlePurchase($purchase);

            $checkout->update([
                'payment_status' => 'paid',
                'paid_at' => now(),
                'bundle_purchase_id' => $purchase->id,
            ]);

            return $checkout->fresh()->load(['user', 'bundlePurchase.bundle.posts']);
        }

        $post = Post::query()->findOrFail($checkout->item_id);

        if ($user->hasPurchased($post)) {
            $existing = PostPurchase::query()
                ->where('user_id', $user->id)
                ->where('post_id', $post->id)
                ->firstOrFail();

            $checkout->update([
                'payment_status' => 'paid',
                'paid_at' => now(),
                'post_purchase_id' => $existing->id,
            ]);

            return $checkout->fresh()->load(['user', 'postPurchase.post']);
        }

        $purchase = PostPurchase::create([
            'user_id' => $user->id,
            'post_id' => $post->id,
            'credits_spent' => $checkout->credits_cost,
            'purchased_at' => now(),
        ]);

        $post->increment('buy_count');
        $this->invoices->createForPostPurchase($purchase);

        $checkout->update([
            'payment_status' => 'paid',
            'paid_at' => now(),
            'post_purchase_id' => $purchase->id,
        ]);

        return $checkout->fresh()->load(['user', 'postPurchase.post']);
    }

    private function ensureApiKey(): void
    {
        if ($this->apiKeySet) {
            return;
        }

        $this->paymentSettings->applyStripeApiKey();
        $this->apiKeySet = true;
    }

    private function generateReference(int $userId): string
    {
        do {
            $reference = 'BT-CONTENT-'.$userId.'-'.Str::upper(Str::random(6));
        } while (ContentCheckout::query()->where('payment_reference', $reference)->exists());

        return $reference;
    }
}
