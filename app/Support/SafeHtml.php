<?php

namespace App\Support;

/**
 * NFR-SEC-APP / NFR-SEC-VAL — allowlist sanitizer for stored rich HTML.
 * Used via InputSanitizer::richHtml() for Terms / Privacy / Cookies / GC / SMC /
 * tickets / WC / posts. Complements SPA DOMPurify.
 */
final class SafeHtml
{
    /**
     * Tags aligned with hub-finproms-frontend src/utils/richText.js ALLOWED_TAGS.
     */
    private const ALLOWED_TAGS = [
        'p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'strike', 'del',
        'ul', 'ol', 'li', 'a', 'span', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'blockquote', 'pre', 'code', 'sub', 'sup', 'img', 'div',
    ];

    public static function sanitize(?string $html): string
    {
        if ($html === null || $html === '') {
            return '';
        }

        $html = preg_replace('#<(script|style|iframe|object|embed|link|meta|base|form)[^>]*>.*?</\1>#is', '', $html) ?? $html;
        $html = preg_replace('#<(script|style|iframe|object|embed|link|meta|base|form)[^>]*/?>#is', '', $html) ?? $html;

        // Drop inline event handlers (onclick, onerror, …).
        $html = preg_replace('/\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? $html;

        // Neutralize javascript:/data: URLs in href/src.
        $html = preg_replace(
            '/\s(href|src|xlink:href)\s*=\s*(["\'])\s*(javascript|data|vbscript):/i',
            ' $1=$2#blocked:',
            $html
        ) ?? $html;

        $allowed = '<'.implode('><', self::ALLOWED_TAGS).'>';
        $cleaned = strip_tags($html, $allowed);

        // Force safe rel on target=_blank links.
        $cleaned = preg_replace_callback(
            '/<a\b([^>]*)>/i',
            static function (array $m): string {
                $attrs = $m[1];
                if (preg_match('/\btarget\s*=\s*(["\'])_blank\1/i', $attrs)) {
                    if (! preg_match('/\brel\s*=/i', $attrs)) {
                        $attrs .= ' rel="noopener noreferrer"';
                    }
                }

                return '<a'.$attrs.'>';
            },
            $cleaned
        ) ?? $cleaned;

        return trim($cleaned);
    }
}
