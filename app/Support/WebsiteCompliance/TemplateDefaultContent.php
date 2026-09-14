<?php

namespace App\Support\WebsiteCompliance;

use App\Models\WebsiteCompliance\Template;

/**
 * Canonical template defaults for new advisor section rows.
 * Loads from database/data/website-compliance/{slug}-dummy-content.json.
 */
class TemplateDefaultContent
{
    public static function pathForSlug(string $slug = 'template4'): string
    {
        $safe = preg_replace('/[^a-z0-9_-]/i', '', $slug) ?: 'template4';
        $path = database_path("data/website-compliance/{$safe}-dummy-content.json");
        if (file_exists($path)) {
            return $path;
        }

        return database_path('data/website-compliance/template4-dummy-content.json');
    }

    /** @return array<string, mixed> */
    public static function load(string $slug = 'template4'): array
    {
        $path = self::pathForSlug($slug);
        if (! file_exists($path)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Prefer on-disk JSON; fall back to templates.dummy_content.
     *
     * @return array<string, mixed>
     */
    public static function forTemplate(?Template $template, ?string $slug = null): array
    {
        $slug = $slug ?: ($template?->slug ?: 'template4');
        $fromFile = self::load($slug);
        if (! empty($fromFile)) {
            return $fromFile;
        }

        if (! $template) {
            $template = Template::where('slug', $slug)->first() ?? Template::first();
        }
        if (! $template) {
            return [];
        }

        $raw = $template->dummy_content ?? null;
        if (is_string($raw)) {
            $trimmed = trim($raw);
            if ($trimmed === '' || $trimmed === '{}' || $trimmed === '[]') {
                return [];
            }
            $decoded = json_decode($raw, true);

            return is_array($decoded) && ! empty($decoded) ? $decoded : [];
        }

        return is_array($raw) && ! empty($raw) ? $raw : [];
    }

    public static function encode(mixed $content): string
    {
        if (is_string($content)) {
            return $content;
        }

        return (string) json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }
}
