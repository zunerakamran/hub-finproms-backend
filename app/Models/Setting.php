<?php

namespace App\Models;

use App\Casts\SafeEncrypted;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Throwable;

class Setting extends Model
{
    public const KEY_NEW_BANNER_DAYS = 'new_banner_days';

    public const KEY_PAYMENT_STRIPE_ENABLED = 'payment_stripe_enabled';

    public const KEY_PAYMENT_BANK_TRANSFER_ENABLED = 'payment_bank_transfer_enabled';

    public const KEY_PAYMENT_BANK_TRANSFER_AUTO_CONFIRM = 'payment_bank_transfer_auto_confirm';

    /** Platform-wide Stripe credentials (fallback when hub has none). */
    public const KEY_STRIPE_KEY = 'stripe_key';

    public const KEY_STRIPE_SECRET = 'stripe_secret';

    public const KEY_STRIPE_WEBHOOK_SECRET = 'stripe_webhook_secret';

    public const KEY_STRIPE_CURRENCY = 'stripe_currency';

    protected $fillable = [
        'key',
        'value',
    ];

    public static function getValue(string $key, mixed $default = null): mixed
    {
        $setting = Cache::remember("setting:{$key}", 60, function () use ($key) {
            return static::query()->where('key', $key)->first();
        });

        if (! $setting) {
            return $default;
        }

        return $setting->value ?? $default;
    }

    public static function getBool(string $key, bool $default = false): bool
    {
        $value = static::getValue($key, null);

        if ($value === null) {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    public static function setValue(string $key, mixed $value): self
    {
        $setting = static::query()->updateOrCreate(
            ['key' => $key],
            ['value' => is_bool($value) ? ($value ? '1' : '0') : (string) $value]
        );

        Cache::forget("setting:{$key}");

        return $setting;
    }

    /**
     * Read a secret setting (AES via APP_KEY). Tolerates legacy plaintext.
     */
    public static function getSecret(string $key, mixed $default = null): mixed
    {
        $value = static::getValue($key, null);
        if ($value === null || $value === '') {
            return $default;
        }

        try {
            return Crypt::decryptString((string) $value);
        } catch (Throwable) {
            return SafeEncrypted::looksLikeLaravelCiphertext((string) $value)
                ? $default
                : $value;
        }
    }

    /**
     * Persist a secret setting encrypted at rest.
     */
    public static function setSecret(string $key, string $value): self
    {
        return static::setValue($key, Crypt::encryptString($value));
    }

    public static function newBannerDays(): int
    {
        return max(0, (int) static::getValue(self::KEY_NEW_BANNER_DAYS, 7));
    }
}
