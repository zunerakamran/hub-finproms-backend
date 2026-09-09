<?php

namespace App\Services;

use App\Models\Hub;
use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

class PaymentSettingsService
{
    public function __construct(
        private readonly HubService $hubs
    ) {}

    /**
     * @return array{
     *   stripe: array<string, mixed>,
     *   bank_transfer: array<string, mixed>
     * }
     */
    public function methods(?Hub $hub = null): array
    {
        $hub ??= $this->hubs->current();
        $stripeEnabled = $this->isStripeEnabled();
        $stripeConfigured = $this->isStripeConfigured($hub);
        $bankEnabled = $this->isBankTransferEnabled();
        $creds = $this->resolvedStripeCredentials($hub);

        return [
            'stripe' => [
                'id' => 'stripe',
                'label' => config('payments.methods.stripe.label', 'Card (Stripe)'),
                'enabled' => $stripeEnabled,
                'configured' => $stripeConfigured,
                'available' => $stripeEnabled && $stripeConfigured,
                'unavailable_reason' => ! $stripeEnabled
                    ? 'Stripe payments are disabled by Power Admin.'
                    : (! $stripeConfigured
                        ? 'Stripe is not configured yet. Add Stripe Secret (and Key) in Power Admin → Payment methods.'
                        : null),
                'key' => $creds['key'],
                'secret_set' => filled($creds['secret']),
                'webhook_secret_set' => filled($creds['webhook_secret']),
                'currency' => $creds['currency'],
                'source' => $creds['source'],
            ],
            'bank_transfer' => [
                'id' => 'bank_transfer',
                'label' => config('payments.methods.bank_transfer.label', 'Bank transfer'),
                'enabled' => $bankEnabled,
                'auto_confirm' => $this->isBankTransferAutoConfirm(),
                'available' => $bankEnabled,
                'unavailable_reason' => $bankEnabled ? null : 'Bank transfer is disabled by Power Admin.',
            ],
        ];
    }

    /**
     * Resolve Stripe credentials: hub override → platform settings → .env.
     *
     * @return array{key: ?string, secret: ?string, webhook_secret: ?string, currency: string, source: string}
     */
    public function resolvedStripeCredentials(?Hub $hub = null): array
    {
        $hub ??= $this->hubs->current();

        $envKey = config('services.stripe.key') ?: null;
        $envSecret = config('services.stripe.secret') ?: null;
        $envWebhook = config('services.stripe.webhook_secret') ?: null;
        $envCurrency = (string) (config('services.stripe.currency') ?: 'gbp');

        $platformKey = Setting::getValue(Setting::KEY_STRIPE_KEY, null) ?: null;
        $platformSecret = Setting::getValue(Setting::KEY_STRIPE_SECRET, null) ?: null;
        $platformWebhook = Setting::getValue(Setting::KEY_STRIPE_WEBHOOK_SECRET, null) ?: null;
        $platformCurrency = Setting::getValue(Setting::KEY_STRIPE_CURRENCY, null) ?: null;

        $hubKey = $hub?->stripe_key ?: null;
        $hubSecret = $hub?->stripe_secret ?: null;
        $hubWebhook = $hub?->stripe_webhook_secret ?: null;
        $hubCurrency = $hub?->stripe_currency ?: null;

        $secret = $hubSecret ?: $platformSecret ?: $envSecret;
        $key = $hubKey ?: $platformKey ?: $envKey;
        $webhook = $hubWebhook ?: $platformWebhook ?: $envWebhook;
        $currency = strtolower((string) ($hubCurrency ?: $platformCurrency ?: $envCurrency ?: 'gbp'));

        $source = 'env';
        if ($hubSecret || $hubKey || $hubWebhook) {
            $source = 'hub';
        } elseif ($platformSecret || $platformKey || $platformWebhook) {
            $source = 'platform';
        }

        return [
            'key' => $key,
            'secret' => $secret,
            'webhook_secret' => $webhook,
            'currency' => $currency,
            'source' => $source,
        ];
    }

    public function applyStripeApiKey(?Hub $hub = null): void
    {
        $creds = $this->resolvedStripeCredentials($hub);
        if (! filled($creds['secret'])) {
            throw new \RuntimeException('Stripe secret is not configured for this hub.');
        }
        \Stripe\Stripe::setApiKey($creds['secret']);
    }

    public function stripeCurrency(?Hub $hub = null): string
    {
        return $this->resolvedStripeCredentials($hub)['currency'];
    }

    public function stripeWebhookSecret(?Hub $hub = null): ?string
    {
        return $this->resolvedStripeCredentials($hub)['webhook_secret'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function publicMethods(?callable $bankDetails = null, ?Hub $hub = null): array
    {
        $methods = $this->methods($hub);
        $list = [];

        $list[] = [
            'id' => $methods['stripe']['id'],
            'label' => $methods['stripe']['label'],
            'available' => $methods['stripe']['available'],
            'unavailable_reason' => $methods['stripe']['unavailable_reason'],
        ];

        $bank = $methods['bank_transfer'];
        $list[] = [
            'id' => $bank['id'],
            'label' => $bank['label'],
            'available' => $bank['available'],
            'unavailable_reason' => $bank['unavailable_reason'],
            'bank_details' => ($bank['available'] && $bankDetails) ? $bankDetails() : null,
        ];

        return $list;
    }

    public function isStripeEnabled(): bool
    {
        return Setting::getBool(
            Setting::KEY_PAYMENT_STRIPE_ENABLED,
            (bool) config('payments.methods.stripe.enabled', true)
        );
    }

    public function isStripeConfigured(?Hub $hub = null): bool
    {
        return filled($this->resolvedStripeCredentials($hub)['secret']);
    }

    public function isBankTransferEnabled(): bool
    {
        return Setting::getBool(
            Setting::KEY_PAYMENT_BANK_TRANSFER_ENABLED,
            (bool) config('payments.methods.bank_transfer.enabled', false)
        );
    }

    public function isBankTransferAutoConfirm(): bool
    {
        return Setting::getBool(
            Setting::KEY_PAYMENT_BANK_TRANSFER_AUTO_CONFIRM,
            (bool) config('payments.methods.bank_transfer.auto_confirm', true)
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{stripe: array<string, mixed>, bank_transfer: array<string, mixed>}
     */
    public function update(array $payload, ?Hub $hub = null): array
    {
        if (array_key_exists('stripe_enabled', $payload)) {
            Setting::setValue(Setting::KEY_PAYMENT_STRIPE_ENABLED, (bool) $payload['stripe_enabled']);
        }

        if (array_key_exists('bank_transfer_enabled', $payload)) {
            Setting::setValue(Setting::KEY_PAYMENT_BANK_TRANSFER_ENABLED, (bool) $payload['bank_transfer_enabled']);
        }

        if (array_key_exists('bank_transfer_auto_confirm', $payload)) {
            Setting::setValue(
                Setting::KEY_PAYMENT_BANK_TRANSFER_AUTO_CONFIRM,
                (bool) $payload['bank_transfer_auto_confirm']
            );
        }

        // Platform-wide Stripe credentials (apply to all hubs that have no override).
        if (array_key_exists('stripe_key', $payload) && is_string($payload['stripe_key'])) {
            Setting::setValue(Setting::KEY_STRIPE_KEY, trim($payload['stripe_key']));
        }
        if (array_key_exists('stripe_secret', $payload) && is_string($payload['stripe_secret']) && trim($payload['stripe_secret']) !== '') {
            Setting::setValue(Setting::KEY_STRIPE_SECRET, trim($payload['stripe_secret']));
        }
        if (array_key_exists('stripe_webhook_secret', $payload) && is_string($payload['stripe_webhook_secret']) && trim($payload['stripe_webhook_secret']) !== '') {
            Setting::setValue(Setting::KEY_STRIPE_WEBHOOK_SECRET, trim($payload['stripe_webhook_secret']));
        }
        if (array_key_exists('stripe_currency', $payload) && is_string($payload['stripe_currency'])) {
            Setting::setValue(Setting::KEY_STRIPE_CURRENCY, strtolower(trim($payload['stripe_currency'])) ?: 'gbp');
        }
        if (! empty($payload['clear_stripe_secret'])) {
            Setting::query()->where('key', Setting::KEY_STRIPE_SECRET)->delete();
            \Illuminate\Support\Facades\Cache::forget('setting:'.Setting::KEY_STRIPE_SECRET);
        }
        if (! empty($payload['clear_stripe_webhook_secret'])) {
            Setting::query()->where('key', Setting::KEY_STRIPE_WEBHOOK_SECRET)->delete();
            \Illuminate\Support\Facades\Cache::forget('setting:'.Setting::KEY_STRIPE_WEBHOOK_SECRET);
        }

        // Optional per-hub Stripe override (shared or white-label).
        if ($hub) {
            $dirty = false;
            if (array_key_exists('hub_stripe_key', $payload)) {
                $hub->stripe_key = filled($payload['hub_stripe_key']) ? trim((string) $payload['hub_stripe_key']) : null;
                $dirty = true;
            }
            if (array_key_exists('hub_stripe_secret', $payload) && filled($payload['hub_stripe_secret'])) {
                $hub->stripe_secret = trim((string) $payload['hub_stripe_secret']);
                $dirty = true;
            }
            if (array_key_exists('hub_stripe_webhook_secret', $payload) && filled($payload['hub_stripe_webhook_secret'])) {
                $hub->stripe_webhook_secret = trim((string) $payload['hub_stripe_webhook_secret']);
                $dirty = true;
            }
            if (array_key_exists('hub_stripe_currency', $payload)) {
                $hub->stripe_currency = filled($payload['hub_stripe_currency'])
                    ? strtolower(trim((string) $payload['hub_stripe_currency']))
                    : null;
                $dirty = true;
            }
            if (! empty($payload['clear_hub_stripe_secret'])) {
                $hub->stripe_secret = null;
                $dirty = true;
            }
            if (! empty($payload['clear_hub_stripe_webhook_secret'])) {
                $hub->stripe_webhook_secret = null;
                $dirty = true;
            }
            if ($dirty) {
                $hub->save();
                $this->hubs->forgetCurrentCache();
            }
        }

        return $this->methods($hub);
    }

    public function seedDefaultsFromConfig(): void
    {
        if (Setting::query()->where('key', Setting::KEY_PAYMENT_STRIPE_ENABLED)->doesntExist()) {
            Setting::setValue(
                Setting::KEY_PAYMENT_STRIPE_ENABLED,
                (bool) config('payments.methods.stripe.enabled', true)
            );
        }

        if (Setting::query()->where('key', Setting::KEY_PAYMENT_BANK_TRANSFER_ENABLED)->doesntExist()) {
            Setting::setValue(
                Setting::KEY_PAYMENT_BANK_TRANSFER_ENABLED,
                (bool) config('payments.methods.bank_transfer.enabled', false)
            );
        }

        if (Setting::query()->where('key', Setting::KEY_PAYMENT_BANK_TRANSFER_AUTO_CONFIRM)->doesntExist()) {
            Setting::setValue(
                Setting::KEY_PAYMENT_BANK_TRANSFER_AUTO_CONFIRM,
                (bool) config('payments.methods.bank_transfer.auto_confirm', true)
            );
        }
    }
}
