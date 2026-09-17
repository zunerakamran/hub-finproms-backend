<?php

namespace App\Http\Controllers\Api\WebsiteCompliance;

use App\Http\Controllers\Controller;
use App\Models\WebsiteCompliance\Section;
use App\Models\WebsiteCompliance\TemplateRequest;
use App\Services\ActivityLogService;
use App\Services\WebsiteCompliance\AdvisorSectionService;
use App\Services\WebsiteCompliance\CpanelSyncService;
use App\Services\WebsiteCompliance\WebsiteComplianceGate;
use App\Support\WebsiteCompliance\HubTemplateCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SectionController extends Controller
{
    public function __construct(
        private readonly WebsiteComplianceGate $gate,
        private readonly ActivityLogService $activityLogs
    ) {}

    public function index(Request $request, int $pageId): JsonResponse
    {
        $user = $request->user();
        $this->gate->assertModuleEnabled($user);

        $query = Section::with(['template', 'advisor', 'lockedByUser'])->where('page_id', $pageId);

        $advisorId = $user && $user->isAdvisor()
            ? $user->id
            : $request->query('advisor_id');

        $templateRequestId = $request->query('template_request_id');

        if ($advisorId) {
            $resolved = $this->resolveDeploymentScope(
                (int) $advisorId,
                $templateRequestId ? (int) $templateRequestId : null,
                $user
            );

            if ($resolved === null) {
                return response()->json([]);
            }

            if ($resolved === false) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }

            [$scopedAdvisorId, $scopedRequestId, $templateSlug] = $resolved;

            AdvisorSectionService::ensureForAdvisor(
                (int) $scopedAdvisorId,
                $templateSlug,
                false,
                (int) $scopedRequestId
            );

            $query->where('advisor_id', $scopedAdvisorId)
                ->where('template_request_id', $scopedRequestId);
        }

        if ($request->has('template_id')) {
            $query->where('template_id', $request->query('template_id'));
        }

        return response()->json($query->orderBy('id')->get());
    }

    /**
     * @return array{0:int,1:int,2:string}|null|false
     */
    private function resolveDeploymentScope(int $advisorId, ?int $templateRequestId, $user)
    {
        if ($templateRequestId) {
            $tr = TemplateRequest::find($templateRequestId);
            if (! $tr) {
                return null;
            }

            if ($user && $user->isAdvisor()) {
                $allowed = (int) ($tr->advisor_id ?? 0) === (int) $user->id
                    || (int) ($tr->assigned_advisor_id ?? 0) === (int) $user->id;
                if (! $allowed) {
                    return false;
                }
            }

            $scopedAdvisorId = (int) ($tr->assigned_advisor_id ?? $tr->advisor_id ?? $advisorId);
            if ($scopedAdvisorId <= 0) {
                return null;
            }

            return [
                $scopedAdvisorId,
                (int) $tr->id,
                $tr->template_name ?: HubTemplateCatalog::defaultSlug(),
            ];
        }

        $active = TemplateRequest::query()
            ->where('status', 'deployed')
            ->where(function ($q) use ($advisorId) {
                $q->where('advisor_id', $advisorId)
                    ->orWhere('assigned_advisor_id', $advisorId);
            })
            ->latest('id')
            ->first();

        if (! $active) {
            return null;
        }

        $scopedAdvisorId = (int) ($active->assigned_advisor_id ?? $active->advisor_id ?? $advisorId);
        if ($scopedAdvisorId <= 0) {
            return null;
        }

        return [
            $scopedAdvisorId,
            (int) $active->id,
            $active->template_name ?: HubTemplateCatalog::defaultSlug(),
        ];
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $this->gate->assertModuleEnabled($request->user());

        return response()->json(Section::with(['template', 'advisor', 'lockedByUser'])->findOrFail($id));
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->gate->assertCan($user, 'wc_edit_sections');

        $request->validate([
            'page_id' => 'required|exists:wc_pages,id',
            'name' => 'required|string',
            'content' => 'nullable|string',
            'template_id' => 'nullable|exists:wc_templates,id',
            'advisor_id' => 'nullable|exists:users,id',
        ]);

        $data = $request->only('page_id', 'name', 'content', 'template_id', 'advisor_id');
        if (empty($data['advisor_id']) && $user->isAdvisor()) {
            $data['advisor_id'] = $user->id;
        }

        $section = Section::create($data);

        return response()->json($section->load(['template', 'advisor', 'lockedByUser']), 201);
    }

    public function lock(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if (! $this->gate->can($user, 'wc_edit_sections') && ! $this->gate->can($user, 'wc_publish_live_content')) {
            $this->gate->assertCan($user, 'wc_edit_sections');
        }

        $section = Section::findOrFail($id);

        if ($section->is_locked && $section->locked_by !== $user->id) {
            if (! $section->locked_by) {
                return response()->json(['message' => 'This section has a pending or scheduled change request and cannot be edited until it is reviewed.'], 409);
            }
            $lockedUser = $section->lockedByUser ? $section->lockedByUser->name : 'another user';

            return response()->json(['message' => 'Section is locked by '.$lockedUser], 409);
        }

        $section->update([
            'is_locked' => true,
            'locked_by' => $user->id,
        ]);

        $this->activityLogs->log([
            'action' => 'wc.section.lock',
            'description' => 'Section locked by '.$user->name,
            'user' => $user,
            'subject' => $section,
            'request' => $request,
        ]);

        return response()->json(['message' => 'Section locked', 'section' => $section->load('lockedByUser')]);
    }

    public function unlock(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if (! $this->gate->can($user, 'wc_edit_sections') && ! $this->gate->can($user, 'wc_publish_live_content')) {
            $this->gate->assertCan($user, 'wc_edit_sections');
        }

        $section = Section::findOrFail($id);
        $section->update(['is_locked' => false, 'locked_by' => null]);

        $this->activityLogs->log([
            'action' => 'wc.section.unlock',
            'description' => 'Section unlocked',
            'user' => $user,
            'subject' => $section,
            'request' => $request,
        ]);

        return response()->json(['message' => 'Section unlocked']);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->gate->assertCan($user, 'wc_manage_deployment_sections');

        $request->validate([
            'name' => 'nullable|string|max:255',
            'display_name' => 'nullable|string|max:255',
            'is_visible' => 'nullable|boolean',
        ]);

        $section = Section::findOrFail($id);
        $updates = [];

        if ($request->has('name') && $request->filled('name')) {
            $updates['name'] = $request->name;
        }
        if ($request->has('display_name')) {
            $updates['display_name'] = $request->display_name ?: null;
        }
        if ($request->has('is_visible')) {
            $updates['is_visible'] = (bool) $request->is_visible;
        }

        if (empty($updates)) {
            return response()->json(['message' => 'No changes provided'], 422);
        }

        $section->update($updates);

        $this->activityLogs->log([
            'action' => 'wc.section.update',
            'description' => 'Updated section settings: '.json_encode($updates),
            'user' => $user,
            'subject' => $section,
            'request' => $request,
        ]);

        if ($section->template_request_id) {
            $templateRequest = TemplateRequest::find($section->template_request_id);
            if ($templateRequest) {
                CpanelSyncService::pushToTemplateRequestCpanel($templateRequest);
            }
        } elseif ($section->advisor_id) {
            CpanelSyncService::pushToAdvisorCpanel($section->advisor_id);
        }

        return response()->json($section->fresh()->load(['template', 'advisor', 'lockedByUser']));
    }
}
