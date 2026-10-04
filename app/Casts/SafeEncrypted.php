<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * Encrypted string that returns null instead of crashing when APP_KEY changed.
 *
 * Also tolerates legacy plaintext values written before encryption was enabled
 * (decrypt fails and payload does not look like a Laravel ciphertext).
 */
class SafeEncrypted implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (Throwable) {
            // Wrong APP_KEY / corrupt ciphertext → null. Plain legacy → return as-is
            // until the next write (or security:encrypt-stored-secrets) encrypts it.
            return self::looksLikeLaravelCiphertext((string) $value) ? null : $value;
        }
    }

    public static function looksLikeLaravelCiphertext(string $value): bool
    {
        $decoded = base64_decode($value, true);
        if ($decoded === false) {
            return false;
        }

        $json = json_decode($decoded, true);

        return is_array($json)
            && isset($json['iv'], $json['value'], $json['mac']);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! filled(config('app.key'))) {
            throw new \RuntimeException(
                'No application encryption key has been specified. '
                .'On Central set APP_KEY in public_html/api/.env (restore the existing key if you have it, '
                .'otherwise run: php artisan key:generate), then: php artisan config:clear && php artisan cache:clear'
            );
        }

        return Crypt::encryptString((string) $value);
    }
}
