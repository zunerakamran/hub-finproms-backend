<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\StripeSubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use UnexpectedValueException;

class StripeWebhookController extends Controller
{
    public function __construct(
        private readonly StripeSubscriptionService $stripeSubscriptions
    ) {}

    public function __invoke(Request $request): Response
    {
        $payload = $request->getContent();
        $signature = $request->header('Stripe-Signature');
        $secret = config('services.stripe.webhook_secret');

        try {
            if ($secret) {
                $event = Webhook::constructEvent($payload, $signature ?? '', $secret);
            } else {
                $event = json_decode($payload);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new UnexpectedValueException('Invalid payload');
                }
            }
        } catch (UnexpectedValueException|SignatureVerificationException $e) {
            Log::warning('Stripe webhook rejected', ['error' => $e->getMessage()]);

            return response('Invalid payload', 400);
        }

        $type = is_object($event) ? ($event->type ?? null) : null;

        if ($type === 'checkout.session.completed') {
            $sessionId = $event->data->object->id ?? null;

            if ($sessionId) {
                $this->stripeSubscriptions->fulfillCheckoutSession($sessionId);
            }
        }

        return response('OK', 200);
    }
}
