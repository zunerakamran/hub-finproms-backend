<?php

namespace App\Http\Controllers\Api\WebsiteCompliance;

use App\Http\Controllers\Controller;
use App\Models\WebsiteCompliance\Page;
use App\Models\WebsiteCompliance\Section;
use App\Models\WebsiteCompliance\TemplateRequest;
use App\Services\WebsiteCompliance\AdvisorSectionService;
use App\Services\WebsiteCompliance\CpanelSyncService;
use App\Support\WebsiteCompliance\BrandColor;
use App\Support\WebsiteCompliance\HubTemplateCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;

class PublicController extends Controller
{
    /** Showcase template defaults (catalog / shared preview site). */
    private const SHOWCASE_PRIMARY = '#0f5c45';

    private const SHOWCASE_SECONDARY = '#0a3f30';

    /** Advisor site defaults when a TemplateRequest has no colours set. */
    private const ADVISOR_PRIMARY = '#0B1B3D';

    private const ADVISOR_SECONDARY = '#C8102E';

    public function getPage(string $slug): JsonResponse
    {
        $page = Page::with('sections')->where('slug', $slug)->firstOrFail();

        return response()->json($page);
    }

    public function getAllPages(): JsonResponse
    {
        return response()->json(Page::all());
    }

    public function getHomePageByAdvisor(Request $request): JsonResponse
    {
        $advisorId = $request->query('advisor_id');

        $page = Page::where('slug', 'home')->first();
        if (! $page) {
            return response()->json(['message' => 'Home page not found'], 404);
        }

        $templateRequest = null;

        if ($advisorId === '0' || $advisorId === 'showcase' || $advisorId === null || $advisorId === '') {
            $sectionsQuery = Section::where('page_id', $page->id)->whereNull('advisor_id');
        } else {
            $advisorIdInt = (int) $advisorId;
            $templateRequestId = $request->query('template_request_id');

            if ($templateRequestId) {
                $templateRequest = TemplateRequest::find((int) $templateRequestId);
                AdvisorSectionService::ensureForAdvisor(
                    $advisorIdInt,
                    $templateRequest?->template_name ?: HubTemplateCatalog::defaultSlug(),
                    false,
                    (int) $templateRequestId
                );
                $sectionsQuery = Section::where('page_id', $page->id)
                    ->where('advisor_id', $advisorIdInt)
                    ->where('template_request_id', (int) $templateRequestId);
            } else {
                $templateRequest = TemplateRequest::query()
                    ->where('status', 'deployed')
                    ->where(function ($q) use ($advisorIdInt) {
                        $q->where('advisor_id', $advisorIdInt)
                            ->orWhere('assigned_advisor_id', $advisorIdInt);
                    })
                    ->latest('id')
                    ->first();

                if ($templateRequest) {
                    AdvisorSectionService::ensureForAdvisor(
                        $advisorIdInt,
                        $templateRequest->template_name ?: HubTemplateCatalog::defaultSlug(),
                        false,
                        (int) $templateRequest->id
                    );
                    $sectionsQuery = Section::where('page_id', $page->id)
                        ->where('advisor_id', $advisorIdInt)
                        ->where('template_request_id', $templateRequest->id);
                } else {
                    $sectionsQuery = Section::where('page_id', $page->id)->whereRaw('1 = 0');
                }
            }
        }

        $sections = $sectionsQuery->orderBy('id')->get();

        return response()->json($this->publicPagePayload($page, $sections, $templateRequest))
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }

    public function getTemplateShowcase(string $slug): JsonResponse
    {
        $page = Page::where('slug', 'home')->first();

        if (! $page) {
            return response()->json(['message' => 'Home page not found. Please run the WebsiteComplianceTemplateSeeder.'], 404);
        }

        $sections = Section::where('page_id', $page->id)
            ->whereNull('advisor_id')
            ->orderBy('id')
            ->get();

        return response()->json($this->publicPagePayload($page, $sections))
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Section>|iterable<Section>  $sections
     * @return array<string, mixed>
     */
    private function publicPagePayload(Page $page, $sections, ?TemplateRequest $templateRequest = null): array
    {
        $sectionsList = [];
        $sectionsMap = [];

        foreach ($sections as $section) {
            if (isset($sectionsList[$section->name])) {
                continue;
            }

            $content = $section->content;
            if (is_string($content)) {
                $decoded = json_decode($content, true);
                $content = json_last_error() === JSON_ERROR_NONE ? $decoded : $content;
            }

            $label = $section->display_name ?: $section->name;
            $visible = $section->is_visible !== false;

            if ($visible) {
                $sectionsMap[$section->name] = $content;
            }

            $sectionsList[$section->name] = [
                'name' => $section->name,
                'section_key' => $section->section_key ?: strtolower((string) preg_replace('/[^a-z0-9]/i', '', $section->name)),
                'display_name' => $label,
                'is_visible' => $visible,
                'content' => $visible ? $content : null,
                'updated_at' => optional($section->updated_at)->toISOString(),
            ];
        }

        $sectionsList = array_values($sectionsList);
        $branding = $this->resolveBranding($templateRequest);

        return array_merge([
            'id' => $page->id,
            'name' => $page->title,
            'slug' => $page->slug,
            'sections' => $sectionsMap,
            'sections_list' => $sectionsList,
        ], $branding);
    }

    /**
     * Advisor preview must use TemplateRequest branding; showcase keeps catalog greens.
     *
     * @return array<string, mixed>
     */
    private function resolveBranding(?TemplateRequest $templateRequest): array
    {
        if (! $templateRequest) {
            return [
                'primary_color' => self::SHOWCASE_PRIMARY,
                'secondary_color' => self::SHOWCASE_SECONDARY,
                'logo_url' => null,
                'favicon_url' => null,
                'template_request_id' => null,
                'advisor_id' => null,
                'site_url' => null,
                'template_name' => null,
            ];
        }

        $siteUrl = $templateRequest->cpanel_domain
            ? rtrim((string) $templateRequest->cpanel_domain, '/')
            : null;

        return [
            'primary_color' => BrandColor::toHex($templateRequest->primary_color ?: null, self::ADVISOR_PRIMARY),
            'secondary_color' => BrandColor::toHex($templateRequest->secondary_color ?: null, self::ADVISOR_SECONDARY),
            'logo_url' => CpanelSyncService::absoluteAssetUrl($templateRequest->logo_url),
            'favicon_url' => CpanelSyncService::absoluteAssetUrl($templateRequest->favicon_url),
            'template_request_id' => $templateRequest->id,
            'advisor_id' => $templateRequest->advisor_id ?? $templateRequest->assigned_advisor_id,
            'site_url' => $siteUrl,
            'template_name' => $templateRequest->template_name,
        ];
    }

    /**
     * Reverse-proxy an advisor cPanel site so the hub can iframe it.
     * Live advisor hosts often send X-Frame-Options: SAMEORIGIN ("refused to connect").
     * Only the registered cpanel_domain for this TemplateRequest may be fetched (SSRF-safe).
     */
    public function embedAdvisorSite(Request $request, int $templateRequestId, ?string $path = null): Response
    {
        if (! in_array($request->method(), ['GET', 'HEAD'], true)) {
            abort(405);
        }

        $templateRequest = TemplateRequest::find($templateRequestId);
        if (! $templateRequest || ! filled($templateRequest->cpanel_domain)) {
            abort(404, 'Deployment site not configured');
        }

        $base = rtrim(CpanelSyncService::normalizeAdvisorSiteUrl($templateRequest->cpanel_domain), '/').'/';
        $path = str_replace('\\', '/', (string) $path);
        $path = ltrim($path, '/');
        if ($path !== '' && (str_contains($path, '..') || str_starts_with($path, '/'))) {
            abort(400, 'Invalid path');
        }

        $target = $base.$path;
        if ($qs = $request->getQueryString()) {
            $target .= '?'.$qs;
        }

        $baseHostPath = $this->embedUrlPrefix($base);
        $targetHostPath = $this->embedUrlPrefix($target);
        if ($baseHostPath === '' || ! str_starts_with($targetHostPath, $baseHostPath)) {
            abort(403, 'Target outside deployment site');
        }

        try {
            $upstream = Http::timeout(45)
                ->withOptions([
                    'verify' => false,
                    'allow_redirects' => ['max' => 5, 'strict' => true, 'referer' => true, 'track_redirects' => true],
                ])
                ->withHeaders([
                    'User-Agent' => 'FinProms-WC-Embed/1.0',
                    'Accept' => $request->header('Accept', '*/*'),
                ])
                ->get($target);
        } catch (\Throwable $e) {
            return response('Upstream advisor site unreachable: '.$e->getMessage(), 502)
                ->header('Content-Type', 'text/plain; charset=UTF-8')
                ->header('Content-Security-Policy', "frame-ancestors *");
        }

        $contentType = (string) ($upstream->header('Content-Type') ?: 'application/octet-stream');
        $body = $upstream->body();

        // Stamp advisor brand colours into HTML so section preview never
        // flashes showcase defaults before api.php / postMessage runs.
        if ($upstream->successful() && str_contains(strtolower($contentType), 'text/html')) {
            $body = $this->injectEmbedBranding($body, $base, $templateRequest);
        }

        // Drop framing headers from upstream; allow the hub (and any parent) to embed.
        return response($body, $upstream->status())
            ->header('Content-Type', $contentType)
            ->header('Cache-Control', 'private, no-store')
            ->header('Content-Security-Policy', "frame-ancestors *");
    }

    /**
     * Fetch advisor api.php colours and inject CSS + window bootstrap into HTML.
     * Falls back to TemplateRequest colours when api.php is unreachable.
     */
    private function injectEmbedBranding(string $html, string $siteBase, ?TemplateRequest $templateRequest = null): string
    {
        $primary = null;
        $secondary = null;

        try {
            $brandRes = Http::timeout(12)
                ->withOptions(['verify' => false])
                ->get(rtrim($siteBase, '/').'/api.php');
            if ($brandRes->successful()) {
                $json = $brandRes->json();
                if (is_array($json)) {
                    $primary = is_string($json['primary_color'] ?? null) ? $json['primary_color'] : null;
                    $secondary = is_string($json['secondary_color'] ?? null) ? $json['secondary_color'] : null;
                }
            }
        } catch (\Throwable) {
            // Best-effort only.
        }

        if (! $primary && filled($templateRequest?->primary_color)) {
            $primary = (string) $templateRequest->primary_color;
        }
        if (! $secondary && filled($templateRequest?->secondary_color)) {
            $secondary = (string) $templateRequest->secondary_color;
        }

        if (! $primary && ! $secondary) {
            return $html;
        }

        $primary = $primary ?: ($secondary ?: '#0B1B3D');
        $secondary = $secondary ?: $primary;
        $primaryJson = json_encode($primary);
        $secondaryJson = json_encode($secondary);

        $snippet = <<<HTML
<style id="wc-embed-brand">:root{--brand-primary:{$primary}!important;--brand-primary-dark:{$primary}!important;--brand-secondary:{$secondary}!important;--brand-secondary-hover:{$secondary}!important}</style>
<script>window.__WC_EMBED_BRANDING__={primary_color:{$primaryJson},secondary_color:{$secondaryJson}};</script>
HTML;

        if (stripos($html, '</head>') !== false) {
            return (string) preg_replace('/<\/head>/i', $snippet.'</head>', $html, 1);
        }

        return $snippet.$html;
    }

    /** Host + path prefix used for SSRF checks (scheme-insensitive, trailing slash normalized). */
    private function embedUrlPrefix(string $url): string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['host'])) {
            return '';
        }

        $host = strtolower((string) $parts['host']);
        $path = isset($parts['path']) ? rtrim((string) $parts['path'], '/') : '';

        return $host.$path;
    }
}
