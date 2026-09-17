<?php

namespace App\Support\WebsiteCompliance;

/**
 * Maps showcase website templates to the hub deploy(s) that own them.
 *
 * Isolation is DB-per-hub: each Laravel deploy only seeds / keeps templates
 * listed for its HUB_SLUG. template4 belongs on myhub; shared must not store it.
 */
class HubTemplateCatalog
{
    public static function currentHubSlug(): string
    {
        return (string) config('hub.current_slug', 'shared');
    }

    /**
     * Showcase template slugs allowed on this deploy.
     *
     * @return list<string>
     */
    public static function allowedSlugs(): array
    {
        $override = config('services.website_compliance.showcase_templates');
        if (is_string($override) && trim($override) !== '') {
            return self::normalizeSlugList(explode(',', $override));
        }

        $map = config('services.website_compliance.hub_showcase_templates', []);
        $hubSlug = self::currentHubSlug();

        if (! is_array($map) || ! array_key_exists($hubSlug, $map)) {
            return [];
        }

        $entry = $map[$hubSlug];
        if (! is_array($entry)) {
            return [];
        }

        return self::normalizeSlugList($entry);
    }

    public static function allows(string $slug): bool
    {
        $safe = self::sanitizeSlug($slug);

        return $safe !== '' && in_array($safe, self::allowedSlugs(), true);
    }

    public static function defaultSlug(): ?string
    {
        $allowed = self::allowedSlugs();

        return $allowed[0] ?? null;
    }

    public static function previewBaseUrl(): string
    {
        $configured = config('services.website_compliance.template_preview_base_url');
        if (is_string($configured) && trim($configured) !== '') {
            return rtrim(trim($configured), '/');
        }

        return rtrim((string) config('app.url', ''), '/');
    }

    public static function previewUrlFor(string $slug): string
    {
        $safe = self::sanitizeSlug($slug) ?: 'template';

        return self::previewBaseUrl().'/'.$safe.'/';
    }

    public static function sanitizeSlug(?string $slug): string
    {
        return (string) preg_replace('/[^a-z0-9_-]/i', '', (string) $slug);
    }

    /**
     * @param  list<mixed>  $slugs
     * @return list<string>
     */
    private static function normalizeSlugList(array $slugs): array
    {
        $out = [];
        foreach ($slugs as $slug) {
            if (! is_string($slug) && ! is_numeric($slug)) {
                continue;
            }
            $safe = self::sanitizeSlug((string) $slug);
            if ($safe !== '' && ! in_array($safe, $out, true)) {
                $out[] = $safe;
            }
        }

        return $out;
    }
}
