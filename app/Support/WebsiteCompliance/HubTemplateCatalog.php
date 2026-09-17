<?php

namespace App\Support\WebsiteCompliance;

use App\Models\Hub;
use App\Services\ActingHubService;
use Throwable;

/**
 * Maps showcase website templates to the hub deploy(s) that own them.
 *
 * Isolation is DB-per-hub: each Laravel deploy only seeds / keeps templates
 * listed for its HUB_SLUG. template4 belongs on myhub; shared must not store it.
 * When Power Admin acts on a white-label from shared, ownership follows that hub.
 */
class HubTemplateCatalog
{
    public static function currentHubSlug(): string
    {
        $acting = self::actingWhiteLabelHub();
        if ($acting) {
            return (string) $acting->slug;
        }

        return (string) config('hub.current_slug', 'shared');
    }

    /**
     * Showcase template slugs allowed on this deploy / acting hub.
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

        $acting = self::actingWhiteLabelHub();
        if ($acting) {
            if (filled($acting->frontend_url)) {
                return rtrim((string) $acting->frontend_url, '/');
            }
            if (filled($acting->api_url)) {
                // api_url is often https://myhub.../api — strip /api for site root
                return rtrim(preg_replace('#/api/?$#', '', (string) $acting->api_url) ?: (string) $acting->api_url, '/');
            }
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

    private static function actingWhiteLabelHub(): ?Hub
    {
        try {
            $user = auth('sanctum')->user() ?? auth()->user();
            if (! $user) {
                return null;
            }

            $acting = app(ActingHubService::class);
            if (! $acting->isActingOnWhiteLabel($user)) {
                return null;
            }

            return $acting->actingHub($user);
        } catch (Throwable) {
            return null;
        }
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
