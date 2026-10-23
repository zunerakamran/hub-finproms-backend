<?php

namespace App\Services;

/**
 * RFC 6238 TOTP (compatible with Microsoft Authenticator, Google Authenticator, etc.).
 */
class TotpService
{
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** Digits in the one-time code. */
    public const DIGITS = 6;

    /** Time step in seconds. */
    public const PERIOD = 30;

    /**
     * Generate a new Base32 secret (160-bit / 32 characters).
     */
    public function generateSecret(int $bytes = 20): string
    {
        return $this->base32Encode(random_bytes($bytes));
    }

    /**
     * otpauth:// URI for QR enrollment (issuer + account label).
     */
    public function otpauthUri(string $secret, string $accountName, string $issuer): string
    {
        $label = rawurlencode($issuer.':'.$accountName);
        $query = http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::PERIOD,
        ], '', '&', PHP_QUERY_RFC3986);

        return 'otpauth://totp/'.$label.'?'.$query;
    }

    /**
     * Current TOTP code for a secret (for tests / local verification helpers).
     */
    public function currentCode(string $secret): string
    {
        $secret = strtoupper(preg_replace('/\s+/', '', $secret) ?? '');
        $binary = $this->base32Decode($secret);
        if ($binary === null || $binary === '') {
            return str_repeat('0', self::DIGITS);
        }

        return $this->codeAt($binary, (int) floor(time() / self::PERIOD));
    }

    /**
     * Verify a user-entered code against the secret (allows ±$window steps).
     */
    public function verify(string $secret, string $code, int $window = 1): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (! preg_match('/^\d{'.self::DIGITS.'}$/', $code)) {
            return false;
        }

        $secret = strtoupper(preg_replace('/\s+/', '', $secret) ?? '');
        $binary = $this->base32Decode($secret);
        if ($binary === null || $binary === '') {
            return false;
        }

        $timeSlice = (int) floor(time() / self::PERIOD);
        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals($this->codeAt($binary, $timeSlice + $i), $code)) {
                return true;
            }
        }

        return false;
    }

    private function codeAt(string $binarySecret, int $timeSlice): string
    {
        $time = pack('N*', 0, $timeSlice);
        $hash = hash_hmac('sha1', $time, $binarySecret, true);
        $offset = ord($hash[19]) & 0x0F;
        $value = (
            ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF)
        );

        $otp = $value % (10 ** self::DIGITS);

        return str_pad((string) $otp, self::DIGITS, '0', STR_PAD_LEFT);
    }

    private function base32Encode(string $data): string
    {
        $alphabet = self::BASE32_ALPHABET;
        $binary = '';
        foreach (str_split($data) as $char) {
            $binary .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';
        foreach (str_split($binary, 5) as $chunk) {
            if (strlen($chunk) < 5) {
                $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            }
            $encoded .= $alphabet[bindec($chunk)];
        }

        return $encoded;
    }

    private function base32Decode(string $input): ?string
    {
        $input = strtoupper(preg_replace('/[^A-Z2-7]/', '', $input) ?? '');
        if ($input === '') {
            return null;
        }

        $alphabet = self::BASE32_ALPHABET;
        $binary = '';
        foreach (str_split($input) as $char) {
            $pos = strpos($alphabet, $char);
            if ($pos === false) {
                return null;
            }
            $binary .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }

        $data = '';
        foreach (str_split($binary, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $data .= chr(bindec($chunk));
            }
        }

        return $data;
    }
}
