<?php

namespace App\Services;

use App\Models\Setting;

class PaymentSettingsService
{
    /**
     * @return array{
     *   stripe: array{id: string, label: string, enabled: bool, configured: bool, available: bool, unavailable_reason: ?string},
     *   bank_transfer: array{id: string, label: string, enabled: bool, auto_confirm: bool, available: bool, unavailable_reason: ?string}
     * }
     */
    public function methods(): array
    {
        $stripeEnabled = $this->isStripeEnabled();
        $stripeConfigured = $this->isStripeConfigured();
        $bankEnabled = $this->isBankTransferEnabled();

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
                        ? 'Stripe is not configured yet. Add STRIPE_SECRET in the environment.'
                        : null),
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
     * @return list<array<string, mixed>>
     */
    public function publicMethods(?callable $bankDetails = null): array
    {
        $methods = $this->methods();
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

    public function isStripeConfigured(): bool
    {
        return filled(config('services.stripe.secret'));
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
     * @param  array{stripe_enabled?: bool, bank_transfer_enabled?: bool, bank_transfer_auto_confirm?: bool}  $payload
     * @return array{stripe: array<string, mixed>, bank_transfer: array<string, mixed>}
     */
    public function update(array $payload): array
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

        return $this->methods();
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
