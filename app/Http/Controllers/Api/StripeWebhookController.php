<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Hub;
use App\Models\Setting;
use App\Services\AdvisorBillingService;
use App\Services\HubService;
use App\Services\PaymentSettingsService;
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
        private readonly StripeSubscriptionService $stripeSubscriptions,
        private readonly AdvisorBillingService $advisorBilling,
        private readonly PaymentSettingsService $paymentSettings,
        private readonly HubService $hubs
    ) {}

    public function __invoke(Request $request): Response
    {
        $payload = $request->getContent();
        $signature = $request->header('Stripe-Signature');

        try {
            $event = $this->parseEvent($payload, $signature);
        } catch (UnexpectedValueException|SignatureVerificationException $e) {
            Log::warning('Stripe webhook rejected', ['error' => $e->getMessage()]);

            return response('Invalid payload', 400);
        }

        $type = is_object($event) ? ($event->type ?? null) : null;
        $object = $event->data->object ?? null;

        if ($type === 'checkout.session.completed' && $object) {
            $sessionId = $object->id ?? null;
            $metaType = $object->metadata->type ?? null;

            if ($sessionId && $metaType === 'advisor_billing') {
                $this->advisorBilling->fulfillStripeSession($sessionId);
            } elseif ($sessionId) {
                $this->stripeSubscriptions->fulfillCheckoutSession($sessionId);
            }
        }

        if ($type === 'invoice.paid' && $object) {
            $this->advisorBilling->fulfillStripeSubscriptionInvoice($object);
        }

        return response('OK', 200);
    }

    /**
     * @return object
     */
    private function parseEvent(string $payload, ?string $signature): object
    {
        $secrets = $this->candidateWebhookSecrets();

        if ($secrets === []) {
            $event = json_decode($payload);
            if (json_last_error() !== JSON_ERROR_NONE || ! is_object($event)) {
                throw new UnexpectedValueException('Invalid payload');
            }

            return $event;
        }

        $lastException = null;
        foreach ($secrets as $secret) {
            try {
                return Webhook::constructEvent($payload, $signature ?? '', $secret);
            } catch (SignatureVerificationException $e) {
                $lastException = $e;
            }
        }

        throw $lastException ?? new UnexpectedValueException('Invalid payload');
    }

    /**
     * @return list<string>
     */
    private function candidateWebhookSecrets(): array
    {
        $secrets = [];

        $push = function (?string $value) use (&$secrets) {
            $value = is_string($value) ? trim($value) : '';
            if ($value !== '' && ! in_array($value, $secrets, true)) {
                $secrets[] = $value;
            }
        };

        $push($this->paymentSettings->stripeWebhookSecret($this->hubs->current()));
        $push(Setting::getValue(Setting::KEY_STRIPE_WEBHOOK_SECRET, null));
        $push(config('services.stripe.webhook_secret'));

        foreach (Hub::query()->whereNotNull('stripe_webhook_secret')->get() as $hub) {
            $push($hub->stripe_webhook_secret);
        }

        return $secrets;
    }
}
