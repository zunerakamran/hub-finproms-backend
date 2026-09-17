<?php

namespace App\Http\Controllers\Api\WebsiteCompliance;

use App\Http\Controllers\Controller;
use App\Models\WebsiteCompliance\Page;
use App\Models\WebsiteCompliance\Section;
use App\Models\WebsiteCompliance\TemplateRequest;
use App\Services\WebsiteCompliance\AdvisorSectionService;
use App\Services\WebsiteCompliance\CpanelSyncService;
use App\Support\WebsiteCompliance\HubTemplateCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
            'primary_color' => $templateRequest->primary_color ?: self::ADVISOR_PRIMARY,
            'secondary_color' => $templateRequest->secondary_color ?: self::ADVISOR_SECONDARY,
            'logo_url' => CpanelSyncService::absoluteAssetUrl($templateRequest->logo_url),
            'favicon_url' => CpanelSyncService::absoluteAssetUrl($templateRequest->favicon_url),
            'template_request_id' => $templateRequest->id,
            'advisor_id' => $templateRequest->advisor_id ?? $templateRequest->assigned_advisor_id,
            'site_url' => $siteUrl,
            'template_name' => $templateRequest->template_name,
        ];
    }
}
