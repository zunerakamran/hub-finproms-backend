<?php

namespace App\Http\Controllers\Api\WebsiteCompliance;

use App\Http\Controllers\Controller;
use App\Models\WebsiteCompliance\Page;
use App\Models\WebsiteCompliance\Template;
use App\Models\WebsiteCompliance\TemplateRequest;
use App\Services\ActivityLogService;
use App\Services\WebsiteCompliance\AdvisorSectionService;
use App\Services\WebsiteCompliance\ShowcaseSectionService;
use App\Services\WebsiteCompliance\TemplatePreviewCaptureService;
use App\Services\WebsiteCompliance\WebsiteComplianceGate;
use App\Support\WebsiteCompliance\BrandColor;
use App\Support\WebsiteCompliance\HubTemplateCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class TemplateController extends Controller
{
    public function __construct(
        private readonly WebsiteComplianceGate $gate,
        private readonly ActivityLogService $activityLogs
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->gate->assertModuleEnabled($user);

        if (Template::count() === 0) {
            $defaultSlug = HubTemplateCatalog::defaultSlug();
            if ($defaultSlug) {
                ShowcaseSectionService::syncFromDefaults($defaultSlug, true);
            }
        }

        if ($request->query('all') || $this->gate->can($user, 'wc_manage_templates')) {
            $templates = Template::latest()->get();
        } else {
            $templates = Template::where('is_active', true)->latest()->get();
        }

        return response()->json($templates);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $this->gate->assertModuleEnabled($request->user());
        $template = Template::with(['pages.sections'])->findOrFail($id);

        return response()->json($template);
    }

    public function getTemplatePages(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->gate->assertModuleEnabled($user);

        $template = Template::findOrFail($id);
        $advisorId = $request->query('advisor_id')
            ?? ($user && $user->isAdvisor() ? $user->id : null);

        if ($advisorId) {
            $templateRequestId = $request->query('template_request_id');
            if ($templateRequestId) {
                $deployment = TemplateRequest::find((int) $templateRequestId);
                if ($deployment && $deployment->isOnSite()) {
                    AdvisorSectionService::ensureForAdvisor(
                        (int) $advisorId,
                        $template->slug,
                        false,
                        (int) $templateRequestId
                    );
                }
            }
        }

        $pagesQuery = Page::with(['sections' => function ($q) use ($advisorId, $id, $request) {
            $q->where(function ($sub) use ($id) {
                $sub->where('template_id', $id)->orWhereNull('template_id');
            });
            if ($advisorId) {
                $q->where('advisor_id', $advisorId);
                $templateRequestId = $request->query('template_request_id');
                if ($templateRequestId) {
                    $q->where('template_request_id', (int) $templateRequestId);
                } else {
                    $q->whereNotNull('template_request_id');
                }
            }
        }])->where(function ($q) use ($id) {
            $q->where('template_id', $id)->orWhereNull('template_id');
        });

        return response()->json([
            'template' => $template,
            'pages' => $pagesQuery->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->gate->assertCan($user, 'wc_manage_templates');

        $request->validate([
            'name' => 'required|string|max:255',
            'slug' => [
                'nullable',
                'string',
                'max:255',
                // Use the Template model so uniqueness hits the acting white-labelled DB.
                Rule::unique(Template::class, 'slug'),
            ],
            'description' => 'nullable|string',
            'thumbnail_url' => 'nullable|string|max:500',
            'preview_url' => 'nullable|string|max:500',
            'dummy_content' => 'nullable|string',
            'is_active' => 'nullable|boolean',
            'color_schemes' => 'nullable|array|max:20',
            'color_schemes.*.name' => 'nullable|string|max:100',
            'color_schemes.*.primary' => 'nullable|string|max:50',
            'color_schemes.*.secondary' => 'nullable|string|max:50',
            'available_pages' => 'nullable|array|max:40',
            'available_pages.*.name' => 'nullable|string|max:150',
            'available_pages.*.slug' => 'nullable|string|max:150',
            'available_pages.*.description' => 'nullable|string|max:1000',
        ]);

        $slug = $request->slug ? Str::slug($request->slug) : Str::slug($request->name);

        // Local HUB_SLUG deploys stay catalog-bound. Remote PA/FinProms write to the
        // acting white-labelled DB, so that hub may register any showcase slug it needs.
        if (
            ! $this->gate->isRemoteControlPlaneOperator($user)
            && ! HubTemplateCatalog::allows($slug)
            && HubTemplateCatalog::allowedSlugs() !== []
        ) {
            return response()->json([
                'message' => "Template slug [{$slug}] is not owned by hub [".HubTemplateCatalog::currentHubSlug().'].',
                'allowed_templates' => HubTemplateCatalog::allowedSlugs(),
            ], 422);
        }

        $previewUrl = $request->preview_url ?: $this->defaultPreviewUrl($slug);
        $thumbnailUrl = $request->thumbnail_url;

        if ($previewUrl && ! $thumbnailUrl) {
            try {
                $thumbnailUrl = app(TemplatePreviewCaptureService::class)->capture($previewUrl);
            } catch (\Throwable $e) {
                report($e);
                $thumbnailUrl = null;
            }
        }

        $existing = Template::query()->where('slug', $slug)->first();
        if ($existing) {
            return response()->json([
                'message' => "A template with slug [{$slug}] already exists on this hub.",
                'template' => $existing,
            ], 422);
        }

        $template = Template::create([
            'name' => $request->name,
            'slug' => $slug,
            'description' => $request->description,
            'thumbnail_url' => $thumbnailUrl,
            'preview_url' => $previewUrl,
            'dummy_content' => $request->dummy_content,
            'color_schemes' => $this->normalizeColorSchemes($request->input('color_schemes')),
            'available_pages' => $this->normalizeAvailablePages($request->input('available_pages')),
            'is_active' => $request->has('is_active') ? (bool) $request->is_active : true,
        ]);

        try {
            $this->activityLogs->log([
                'action' => 'wc.template.create',
                'description' => "Created showcase template: {$template->name} ({$template->slug})",
                'user' => $user,
                'subject' => $template,
                'request' => $request,
            ]);
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json($template, 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->gate->assertCan($user, 'wc_manage_templates');

        $template = Template::findOrFail($id);

        $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'slug' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                Rule::unique(Template::class, 'slug')->ignore($id),
            ],
            'description' => 'nullable|string',
            'thumbnail_url' => 'nullable|string|max:500',
            'preview_url' => 'nullable|string|max:500',
            'dummy_content' => 'nullable|string',
            'is_active' => 'nullable|boolean',
            'regenerate_preview' => 'nullable|boolean',
            'color_schemes' => 'nullable|array|max:20',
            'color_schemes.*.name' => 'nullable|string|max:100',
            'color_schemes.*.primary' => 'nullable|string|max:50',
            'color_schemes.*.secondary' => 'nullable|string|max:50',
            'available_pages' => 'nullable|array|max:40',
            'available_pages.*.name' => 'nullable|string|max:150',
            'available_pages.*.slug' => 'nullable|string|max:150',
            'available_pages.*.description' => 'nullable|string|max:1000',
        ]);

        $data = $request->only(['name', 'description', 'thumbnail_url', 'preview_url', 'dummy_content']);
        if ($request->has('slug')) {
            $data['slug'] = Str::slug($request->slug);
            if (
                ! $this->gate->isRemoteControlPlaneOperator($user)
                && ! HubTemplateCatalog::allows($data['slug'])
                && HubTemplateCatalog::allowedSlugs() !== []
            ) {
                return response()->json([
                    'message' => "Template slug [{$data['slug']}] is not owned by hub [".HubTemplateCatalog::currentHubSlug().'].',
                    'allowed_templates' => HubTemplateCatalog::allowedSlugs(),
                ], 422);
            }
        }
        if ($request->has('is_active')) {
            $data['is_active'] = (bool) $request->is_active;
        }
        if ($request->exists('color_schemes')) {
            $data['color_schemes'] = $this->normalizeColorSchemes($request->input('color_schemes'));
        }
        if ($request->exists('available_pages')) {
            $data['available_pages'] = $this->normalizeAvailablePages($request->input('available_pages'));
        }

        $previewUrl = $data['preview_url'] ?? $template->preview_url;
        if (! $previewUrl && isset($data['slug'])) {
            $previewUrl = $this->defaultPreviewUrl($data['slug']);
            $data['preview_url'] = $previewUrl;
        }

        $shouldCapture = $request->boolean('regenerate_preview')
            || ($request->has('preview_url') && $previewUrl !== $template->preview_url);

        if ($shouldCapture && $previewUrl) {
            try {
                $captured = app(TemplatePreviewCaptureService::class)->capture($previewUrl);
                if ($captured) {
                    $data['thumbnail_url'] = $captured;
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }

        $template->update($data);

        try {
            $this->activityLogs->log([
                'action' => 'wc.template.update',
                'description' => "Updated template ID {$template->id}: {$template->name}",
                'user' => $user,
                'subject' => $template,
                'request' => $request,
            ]);
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json($template);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->gate->assertCan($user, 'wc_manage_templates');

        $template = Template::findOrFail($id);

        try {
            $this->activityLogs->log([
                'action' => 'wc.template.delete',
                'description' => "Deleted template: {$template->name} ({$template->slug})",
                'user' => $user,
                'subject' => $template,
                'request' => $request,
            ]);
        } catch (\Throwable $e) {
            report($e);
        }

        $template->delete();

        return response()->json(['message' => 'Template successfully deleted.']);
    }

    private function defaultPreviewUrl(string $slug): string
    {
        return HubTemplateCatalog::previewUrlFor($slug);
    }

    /**
     * @param  mixed  $raw
     * @return list<array{name: string, primary: string, secondary: string}>
     */
    private function normalizeColorSchemes(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $schemes = [];
        foreach ($raw as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $primary = BrandColor::toHex(
                isset($row['primary']) ? (string) $row['primary'] : null,
                '#0B1B3D'
            );
            $secondary = BrandColor::toHex(
                isset($row['secondary']) ? (string) $row['secondary'] : null,
                '#C8102E'
            );
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                $name = 'Scheme '.((int) $index + 1);
            }

            $schemes[] = [
                'name' => Str::limit($name, 100, ''),
                'primary' => $primary,
                'secondary' => $secondary,
            ];
        }

        return array_values($schemes);
    }

    /**
     * @param  mixed  $raw
     * @return list<array{name: string, slug: string, description: string}>
     */
    private function normalizeAvailablePages(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $pages = [];
        $seen = [];
        foreach ($raw as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $name = trim((string) ($row['name'] ?? ''));
            $slug = Str::slug((string) ($row['slug'] ?? $name));
            if ($name === '' && $slug === '') {
                continue;
            }
            if ($name === '') {
                $name = 'Page '.((int) $index + 1);
            }
            if ($slug === '') {
                $slug = 'page-'.((int) $index + 1);
            }
            if (isset($seen[$slug])) {
                continue;
            }
            $seen[$slug] = true;

            $pages[] = [
                'name' => Str::limit($name, 150, ''),
                'slug' => Str::limit($slug, 150, ''),
                'description' => Str::limit(trim((string) ($row['description'] ?? '')), 1000, ''),
            ];
        }

        return array_values($pages);
    }
}
