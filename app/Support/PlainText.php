<?php

namespace App\Support;

/**
 * Helpers for turning rich-text HTML into plain text (audit/activity summaries).
 */
final class PlainText
{
    public static function fromHtml(?string $html): string
    {
        if ($html === null || $html === '') {
            return '';
        }

        $decoded = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $withoutTags = strip_tags($decoded);
        $normalized = preg_replace('/\s+/u', ' ', $withoutTags) ?? $withoutTags;

        return trim($normalized);
    }
}
