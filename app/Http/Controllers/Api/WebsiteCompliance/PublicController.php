<?php

namespace App\Http\Controllers\Api\WebsiteCompliance;

use App\Http\Controllers\Controller;
use App\Models\WebsiteCompliance\Page;
use App\Models\WebsiteCompliance\Section;
use App\Models\WebsiteCompliance\TemplateRequest;
use App\Services\WebsiteCompliance\AdvisorSectionService;
use App\Support\WebsiteCompliance\HubTemplateCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicController extends Controller
{
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

        if ($advisorId === '0' || $advisorId === 'showcase' || $advisorId === null || $advisorId === '') {
            $sectionsQuery = Section::where('page_id', $page->id)->whereNull('advisor_id');
        } else {
            $advisorIdInt = (int) $advisorId;
            $templateRequestId = $request->query('template_request_id');

            if ($templateRequestId) {
                $tr = TemplateRequest::find((int) $templateRequestId);
                AdvisorSectionService::ensureForAdvisor(
                    $advisorIdInt,
                    $tr?->template_name ?: HubTemplateCatalog::defaultSlug(),
                    false,
                    (int) $templateRequestId
                );
                $sectionsQuery = Section::where('page_id', $page->id)
                    ->where('advisor_id', $advisorIdInt)
                    ->where('template_request_id', (int) $templateRequestId);
            } else {
                $active = TemplateRequest::query()
                    ->where('status', 'deployed')
                    ->where(function ($q) use ($advisorIdInt) {
                        $q->where('advisor_id', $advisorIdInt)
                            ->orWhere('assigned_advisor_id', $advisorIdInt);
                    })
                    ->latest('id')
                    ->first();

                if ($active) {
                    AdvisorSectionService::ensureForAdvisor(
                        $advisorIdInt,
                        $active->template_name ?: HubTemplateCatalog::defaultSlug(),
                        false,
                        (int) $active->id
                    );
                    $sectionsQuery = Section::where('page_id', $page->id)
                        ->where('advisor_id', $advisorIdInt)
                        ->where('template_request_id', $active->id);
                } else {
                    $sectionsQuery = Section::where('page_id', $page->id)->whereRaw('1 = 0');
                }
            }
        }

        $sections = $sectionsQuery->orderBy('id')->get();

        return response()->json($this->publicPagePayload($page, $sections))
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

    private function publicPagePayload(Page $page, $sections): array
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

        return [
            'id' => $page->id,
            'name' => $page->title,
            'slug' => $page->slug,
            'primary_color' => '#0B1B3D',
            'secondary_color' => '#C8102E',
            'sections' => $sectionsMap,
            'sections_list' => $sectionsList,
        ];
    }
}
