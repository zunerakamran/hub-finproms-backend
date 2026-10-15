<?php

namespace App\Support;

/**
 * NFR-SEC-VAL — server-side sanitization helpers for user-supplied strings.
 *
 * Field-type aware:
 * - plain(): strip tags / null bytes / most control chars (names, subjects, …)
 * - richHtml(): allowlisted HTML via SafeHtml (Terms, compliance bodies, …)
 * - wire(): null-byte + control-char scrub only (middleware; keeps intentional HTML)
 */
final class InputSanitizer
{
    /**
     * Scrub binary / control noise from any string that travels on the wire.
     * Does not strip HTML tags (rich fields use richHtml() after validation).
     */
    public static function wire(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        // Null bytes and other C0 controls except tab / LF / CR.
        $cleaned = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? $value;

        return $cleaned;
    }

    /**
     * Plain-text fields: wire scrub + strip tags + collapse whitespace trim.
     */
    public static function plain(?string $value): string
    {
        $cleaned = self::wire($value);
        if ($cleaned === '') {
            return '';
        }

        $cleaned = strip_tags($cleaned);
        $cleaned = html_entity_decode($cleaned, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim($cleaned);
    }

    /**
     * Stored rich HTML rendered in the SPA (Quill / legal pages / WC sections).
     */
    public static function richHtml(?string $value): string
    {
        $cleaned = self::wire($value);
        if ($cleaned === '') {
            return '';
        }

        if (! config('security_app.sanitize_rich_html', true)) {
            return $cleaned;
        }

        return SafeHtml::sanitize($cleaned);
    }

    /**
     * Apply plain() to listed keys of a validated array (in place).
     *
     * @param  array<string, mixed>  $data
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    public static function plainKeys(array $data, array $keys): array
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $data) || $data[$key] === null) {
                continue;
            }
            if (is_string($data[$key])) {
                $data[$key] = self::plain($data[$key]);
            }
        }

        return $data;
    }

    /**
     * Apply richHtml() to listed keys of a validated array (in place).
     *
     * @param  array<string, mixed>  $data
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    public static function richHtmlKeys(array $data, array $keys): array
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $data) || $data[$key] === null) {
                continue;
            }
            if (is_string($data[$key])) {
                $data[$key] = self::richHtml($data[$key]);
            }
        }

        return $data;
    }

    /**
     * Recursively scrub strings in a request payload (middleware).
     *
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    public static function deepWire(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::deepWire($value);
            } elseif (is_string($value)) {
                $data[$key] = self::wire($value);
            }
        }

        return $data;
    }
}
