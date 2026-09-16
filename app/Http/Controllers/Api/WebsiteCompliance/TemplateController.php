<?php

namespace App\Http\Controllers\Api\WebsiteCompliance;

use App\Http\Controllers\Controller;
use App\Models\WebsiteCompliance\Page;
use App\Models\WebsiteCompliance\Template;
use App\Services\ActivityLogService;
use App\Services\WebsiteCompliance\AdvisorSectionService;
use App\Services\WebsiteCompliance\ShowcaseSectionService;
use App\Services\WebsiteCompliance\TemplatePreviewCaptureService;
use App\Services\WebsiteCompliance\WebsiteComplianceGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

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
            ShowcaseSectionService::syncFromDefaults('template4', true);
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
                AdvisorSectionService::ensureForAdvisor(
                    (int) $advisorId,
                    $template->slug,
                    false,
                    (int) $templateRequestId
                );
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
            'slug' => 'nullable|string|max:255|unique:wc_templates,slug',
            'description' => 'nullable|string',
            'thumbnail_url' => 'nullable|string|max:500',
            'preview_url' => 'nullable|string|max:500',
            'dummy_content' => 'nullable|string',
            'is_active' => 'nullable|boolean',
        ]);

        $slug = $request->slug ? Str::slug($request->slug) : Str::slug($request->name);
        $previewUrl = $request->preview_url ?: $this->defaultPreviewUrl($slug);
        $thumbnailUrl = $request->thumbnail_url;

        if ($previewUrl && ! $thumbnailUrl) {
            $thumbnailUrl = app(TemplatePreviewCaptureService::class)->capture($previewUrl);
        }

        $template = Template::create([
            'name' => $request->name,
            'slug' => $slug,
            'description' => $request->description,
            'thumbnail_url' => $thumbnailUrl,
            'preview_url' => $previewUrl,
            'dummy_content' => $request->dummy_content,
            'is_active' => $request->has('is_active') ? (bool) $request->is_active : true,
        ]);

        $this->activityLogs->log([
            'action' => 'wc.template.create',
            'description' => "Created showcase template: {$template->name} ({$template->slug})",
            'user' => $user,
            'subject' => $template,
            'request' => $request,
        ]);

        return response()->json($template, 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->gate->assertCan($user, 'wc_manage_templates');

        $template = Template::findOrFail($id);

        $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'slug' => 'sometimes|required|string|max:255|unique:wc_templates,slug,'.$id,
            'description' => 'nullable|string',
            'thumbnail_url' => 'nullable|string|max:500',
            'preview_url' => 'nullable|string|max:500',
            'dummy_content' => 'nullable|string',
            'is_active' => 'nullable|boolean',
            'regenerate_preview' => 'nullable|boolean',
        ]);

        $data = $request->only(['name', 'description', 'thumbnail_url', 'preview_url', 'dummy_content']);
        if ($request->has('slug')) {
            $data['slug'] = Str::slug($request->slug);
        }
        if ($request->has('is_active')) {
            $data['is_active'] = (bool) $request->is_active;
        }

        $previewUrl = $data['preview_url'] ?? $template->preview_url;
        if (! $previewUrl && isset($data['slug'])) {
            $previewUrl = $this->defaultPreviewUrl($data['slug']);
            $data['preview_url'] = $previewUrl;
        }

        $shouldCapture = $request->boolean('regenerate_preview')
            || ($request->has('preview_url') && $previewUrl !== $template->preview_url);

        if ($shouldCapture && $previewUrl) {
            $captured = app(TemplatePreviewCaptureService::class)->capture($previewUrl);
            if ($captured) {
                $data['thumbnail_url'] = $captured;
            }
        }

        $template->update($data);

        $this->activityLogs->log([
            'action' => 'wc.template.update',
            'description' => "Updated template ID {$template->id}: {$template->name}",
            'user' => $user,
            'subject' => $template,
            'request' => $request,
        ]);

        return response()->json($template);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->gate->assertCan($user, 'wc_manage_templates');

        $template = Template::findOrFail($id);

        $this->activityLogs->log([
            'action' => 'wc.template.delete',
            'description' => "Deleted template: {$template->name} ({$template->slug})",
            'user' => $user,
            'subject' => $template,
            'request' => $request,
        ]);

        $template->delete();

        return response()->json(['message' => 'Template successfully deleted.']);
    }

    private function defaultPreviewUrl(string $slug): string
    {
        $base = rtrim((string) config('services.website_compliance.template_preview_base_url', 'https://sharedhub.fin-proms.com'), '/');

        return $base.'/'.trim($slug, '/').'/';
    }
}
