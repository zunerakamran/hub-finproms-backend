<?php

namespace App\Casts;

use App\Support\WebsiteCompliance\WcDatabaseContext;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * Encrypted secrets that live on a content-hub DB (WC cPanel credentials).
 *
 * When Central Power Admin acts remotely, Eloquent writes go to the white-label
 * database over a remote connection — but Crypt still uses Central's APP_KEY.
 * That produces ciphertext myhub cannot decrypt ("The MAC is invalid").
 *
 * Remote writes therefore store a portable JSON envelope the content hub can
 * read without Central's APP_KEY. Local writes still use normal APP_KEY encryption.
 */
class ContentHubSecretEncrypted implements CastsAttributes
{
    public const REMOTE_PLAIN_FLAG = '_wc_remote_plain';

    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            return $value;
        }

        $portable = self::parseRemotePlainEnvelope($value);
        if ($portable !== null) {
            return $portable;
        }

        try {
            return Crypt::decryptString($value);
        } catch (Throwable) {
            return SafeEncrypted::looksLikeLaravelCiphertext($value) ? null : $value;
        }
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        $plain = (string) $value;

        // Already a portable envelope (e.g. re-saving without change) — keep as-is.
        if (self::parseRemotePlainEnvelope($plain) !== null) {
            return $plain;
        }

        // Central (or any deploy) writing into another hub's WC DB via remote connection.
        if (WcDatabaseContext::active() && WcDatabaseContext::hubId()) {
            return json_encode([
                self::REMOTE_PLAIN_FLAG => true,
                'value' => $plain,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        if (! filled(config('app.key'))) {
            throw new \RuntimeException(
                'No application encryption key has been specified. Set APP_KEY in .env.'
            );
        }

        return Crypt::encryptString($plain);
    }

    public static function parseRemotePlainEnvelope(string $value): ?string
    {
        $trimmed = trim($value);
        if ($trimmed === '' || ($trimmed[0] ?? '') !== '{') {
            return null;
        }

        $decoded = json_decode($trimmed, true);
        if (! is_array($decoded) || ($decoded[self::REMOTE_PLAIN_FLAG] ?? false) !== true) {
            return null;
        }

        if (! array_key_exists('value', $decoded)) {
            return null;
        }

        $inner = $decoded['value'];

        return is_string($inner) || is_numeric($inner) ? (string) $inner : null;
    }
}
