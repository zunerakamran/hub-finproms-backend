<?php

namespace App\Http\Controllers\Api\WebsiteCompliance;

use App\Jobs\WebsiteCompliance\PublishScheduledChangeRequestJob;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WebsiteCompliance\ChangeRequest;
use App\Models\WebsiteCompliance\Section;
use App\Models\WebsiteCompliance\TemplateRequest;
use App\Services\ActingAdvisorService;
use App\Services\ActingHubService;
use App\Services\ActivityLogService;
use App\Services\FirmComplianceVisibilityService;
use App\Services\WebsiteCompliance\ChangeRequestPublishService;
use App\Services\WebsiteCompliance\ChangeRequestWorkflowService;
use App\Services\WebsiteCompliance\CpanelSyncService;
use App\Services\WebsiteCompliance\WebsiteComplianceGate;
use App\Support\WebsiteCompliance\WcDatabaseContext;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ChangeRequestController extends Controller
{
    public function __construct(
        private readonly WebsiteComplianceGate $gate,
        private readonly ActivityLogService $activityLogs,
        private readonly ChangeRequestWorkflowService $workflow,
        private readonly FirmComplianceVisibilityService $firmVisibility,
        private readonly ActingAdvisorService $actingAdvisors
    ) {}

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->gate->assertCan($user, 'wc_submit_change_requests');

        $subject = $this->actingAdvisors->requireSubject($user);
        $onBehalfById = $this->actingAdvisors->onBehalfById($user, $subject);
        $editorId = $this->gate->tenantUserIdOrNull($subject) ?? (int) $subject->id;

        if ($request->has('section_edits') && is_array($request->section_edits)) {
            $request->validate([
                'section_edits' => 'required|array|min:1',
                'section_edits.*.section_id' => ['required', Rule::exists(Section::class, 'id')],
                'section_edits.*.proposed_content' => 'required|string',
                'section_edits.*.current_content' => 'nullable|string',
            ]);

            $edits = $this->workflow->prepareAndLockEdits($request->section_edits, $subject);
            $proposedContent = json_encode($edits);
            $primarySectionId = count($edits) === 1 ? $edits[0]['section_id'] : null;

            $changeRequest = ChangeRequest::create([
                'section_id' => $primarySectionId,
                'editor_id' => $editorId,
                'on_behalf_by_user_id' => $onBehalfById,
                'proposed_content' => $proposedContent,
                'status' => ChangeRequest::STATUS_PENDING,
                'current_version' => 1,
            ]);

            $this->workflow->createVersionOne($changeRequest, $proposedContent);

            $this->activityLogs->log([
                'action' => 'wc.change_request.submit',
                'description' => 'Submitted change request for '.count($edits).' section(s)'
                    .($onBehalfById ? ' on behalf of user #'.$editorId : ''),
                'user' => $user,
                'subject' => $changeRequest,
                'request' => $request,
            ]);

            return response()->json($changeRequest->fresh(['editor', 'onBehalfBy', 'section', 'currentVersionRow'])->toApiArray(), 201);
        }

        $request->validate([
            'section_id' => ['required', Rule::exists(Section::class, 'id')],
            'proposed_content' => 'required|string',
            'current_content' => 'nullable|string',
        ]);

        $edits = $this->workflow->prepareAndLockEdits([
            [
                'section_id' => (int) $request->section_id,
                'proposed_content' => $request->proposed_content,
                'current_content' => $request->current_content,
            ],
        ], $subject);
        $proposedContent = json_encode($edits);

        $changeRequest = ChangeRequest::create([
            'section_id' => $request->section_id,
            'editor_id' => $editorId,
            'on_behalf_by_user_id' => $onBehalfById,
            'proposed_content' => $proposedContent,
            'status' => ChangeRequest::STATUS_PENDING,
            'current_version' => 1,
        ]);

        $this->workflow->createVersionOne($changeRequest, $proposedContent);

        $this->activityLogs->log([
            'action' => 'wc.change_request.submit',
            'description' => 'Submitted change request'
                .($onBehalfById ? ' on behalf of user #'.$editorId : ''),
            'user' => $user,
            'subject' => $changeRequest,
            'request' => $request,
        ]);

        return response()->json($changeRequest->fresh(['editor', 'onBehalfBy', 'section', 'currentVersionRow'])->toApiArray(), 201);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->gate->assertModuleEnabled($user);

        $with = [
            'section',
            'editor:id,name,email,firm_id',
            'editor.firm:id,name,is_central,compliance_visible_to_own,compliance_visible_to_central,compliance_visible_to_firm_id',
            'onBehalfBy:id,name,email',
            'approver',
            'currentVersionRow',
        ];
        // Pickup/assign write tenantUserIdOrNull; match the same id for scoping.
        $actorId = $this->gate->tenantUserIdOrNull($user) ?? (int) $user->id;
        // Approver role is always personal-queue scoped. Hub-wide history is for
        // managers/admins via wc_view_all_change_requests — never for Approver,
        // even if an older seeder left that flag on for the role.
        $canViewAll = $this->gate->can($user, 'wc_view_all_change_requests')
            && (string) $user->role !== User::ROLE_APPROVER;
        $canChangeStatus = $this->gate->can($user, 'wc_change_request_status');

        if ($canViewAll || $canChangeStatus) {
            $query = ChangeRequest::with($with)->latest();
            $this->firmVisibility->scopeQueryForActor($query, $user, 'editor', 'approver_id');
            $requests = $query->get();
        } elseif ($this->gate->can($user, 'wc_review_change_requests')) {
            // Approvers: unassigned pending for pickup + only requests they picked.
            $query = ChangeRequest::with($with)
                ->where(function ($q) use ($actorId) {
                    $q->where('approver_id', $actorId)
                        ->orWhere(function ($pending) {
                            $pending->where('status', ChangeRequest::STATUS_PENDING)
                                ->whereNull('approver_id');
                        });
                })
                ->latest();
            $this->firmVisibility->scopeQueryForActor($query, $user, 'editor', 'approver_id');
            $requests = $query->get();
        } elseif ($this->gate->can($user, 'wc_assign_change_requests')) {
            // Assign-only staff (no review): full inbox to route work.
            $query = ChangeRequest::with($with)->latest();
            $this->firmVisibility->scopeQueryForActor($query, $user, 'editor', 'approver_id');
            $requests = $query->get();
        } else {
            $subject = $this->actingAdvisors->subjectOrNull($user);
            $editorId = $subject
                ? ($this->gate->tenantUserIdOrNull($subject) ?? (int) $subject->id)
                : null;
            $requests = $editorId
                ? ChangeRequest::with($with)
                    ->where('editor_id', $editorId)
                    ->latest()
                    ->get()
                : collect();
        }

        return response()->json($requests->map(fn (ChangeRequest $cr) => $cr->toApiArray())->values());
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->gate->assertModuleEnabled($user);

        $changeRequest = ChangeRequest::with([
            'section',
            'editor.firm:id,name,is_central,compliance_visible_to_own,compliance_visible_to_central,compliance_visible_to_firm_id',
            'onBehalfBy:id,name,email',
            'approver',
            'currentVersionRow',
            'versions',
        ])->findOrFail($id);

        $actorId = $this->gate->tenantUserIdOrNull($user) ?? (int) $user->id;
        $subject = $this->actingAdvisors->subjectOrNull($user);
        $subjectId = $subject
            ? ($this->gate->tenantUserIdOrNull($subject) ?? (int) $subject->id)
            : $actorId;
        $isOwner = (int) $changeRequest->editor_id === (int) $subjectId
            || (int) $changeRequest->editor_id === (int) $actorId;
        $isAssignee = (int) ($changeRequest->approver_id ?? 0) === (int) $actorId;
        $isUnassignedPending = $changeRequest->status === ChangeRequest::STATUS_PENDING
            && empty($changeRequest->approver_id);
        $canViewAll = $this->gate->can($user, 'wc_view_all_change_requests')
            && (string) $user->role !== User::ROLE_APPROVER;
        $canAssign = $this->gate->can($user, 'wc_assign_change_requests');
        $canReview = $this->gate->can($user, 'wc_review_change_requests');
        $canChangeStatus = $this->gate->can($user, 'wc_change_request_status');

        $allowed =
            $isOwner
            || ($canReview && $isAssignee)
            || ($canReview && $isUnassignedPending)
            || (($canViewAll || $canChangeStatus || ($canAssign && ! $canReview)) && $this->firmVisibility->actorCanViewRequest(
                $user,
                $changeRequest->editor_id ? (int) $changeRequest->editor_id : null,
                $changeRequest->editor?->firm_id ? (int) $changeRequest->editor->firm_id : null,
                $changeRequest->approver_id ? (int) $changeRequest->approver_id : null
            ));

        // Reviewers may only open unassigned pending when firm visibility allows.
        if ($allowed && $canReview && $isUnassignedPending && ! $isOwner && ! $isAssignee) {
            $allowed = $this->firmVisibility->actorCanViewRequest(
                $user,
                $changeRequest->editor_id ? (int) $changeRequest->editor_id : null,
                $changeRequest->editor?->firm_id ? (int) $changeRequest->editor->firm_id : null,
                null
            );
        }

        if (! $allowed) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return response()->json($changeRequest->toApiArray(true));
    }

    public function changeStatus(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->gate->assertCan($user, 'wc_change_request_status');

        $validated = $request->validate([
            'status' => [
                'required',
                'string',
                Rule::in([
                    ChangeRequest::STATUS_PENDING,
                    ChangeRequest::STATUS_UNDER_REVIEW,
                    ChangeRequest::STATUS_REJECTED,
                    ChangeRequest::STATUS_APPROVED_WITH_FEEDBACK,
                ]),
            ],
            'comment' => ['nullable', 'string', 'max:10000'],
        ]);

        $changeRequest = ChangeRequest::with(['editor:id,firm_id', 'currentVersionRow', 'section'])
            ->findOrFail($id);

        $updated = $this->workflow->changeStatus($changeRequest, $user, $validated, $request);

        return response()->json([
            'message' => 'Status updated.',
            'change_request' => $updated->toApiArray(true),
        ]);
    }

    public function assign(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->gate->assertCan($user, 'wc_review_change_requests');

        $changeRequest = ChangeRequest::with('editor:id,firm_id')->findOrFail($id);

        if ($changeRequest->status !== ChangeRequest::STATUS_PENDING) {
            return response()->json(['message' => 'Request already assigned or processed'], 409);
        }

        $this->firmVisibility->assertActorCanActOnRequest(
            $user,
            $changeRequest->editor_id ? (int) $changeRequest->editor_id : null,
            $changeRequest->editor?->firm_id ? (int) $changeRequest->editor->firm_id : null,
            null
        );

        $changeRequest->update([
            'approver_id' => $this->gate->tenantUserIdOrNull($user),
            'status' => ChangeRequest::STATUS_UNDER_REVIEW,
        ]);

        $this->workflow->syncCurrentVersionStatus(
            $changeRequest->fresh(['currentVersionRow']),
            ChangeRequest::STATUS_UNDER_REVIEW
        );

        $this->activityLogs->log([
            'action' => 'wc.change_request.assign',
            'description' => 'Approver assigned request to themselves',
            'user' => $user,
            'subject' => $changeRequest,
            'request' => $request,
        ]);

        return response()->json($changeRequest->fresh(['editor', 'approver', 'section', 'currentVersionRow'])->toApiArray());
    }

    public function assignToApprover(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->gate->assertCan($user, 'wc_assign_change_requests');

        $request->validate([
            'approver_id' => ['required', Rule::exists(User::class, 'id')],
        ]);

        $changeRequest = ChangeRequest::with('editor.firm')->findOrFail($id);

        $this->firmVisibility->assertActorCanActOnRequest(
            $user,
            $changeRequest->editor_id ? (int) $changeRequest->editor_id : null,
            $changeRequest->editor?->firm_id ? (int) $changeRequest->editor->firm_id : null,
            $changeRequest->approver_id ? (int) $changeRequest->approver_id : null
        );

        $approver = User::query()->findOrFail((int) $request->approver_id);
        if (! $this->firmVisibility->userIsEligibleAssignee($approver, $changeRequest->editor?->firm)) {
            return response()->json([
                'message' => 'Selected reviewer is not allowed for this request’s firm visibility settings.',
                'errors' => [
                    'approver_id' => ['Selected reviewer is not allowed for this request’s firm visibility settings.'],
                ],
            ], 422);
        }

        $changeRequest->update([
            'approver_id' => $request->approver_id,
            'status' => ChangeRequest::STATUS_UNDER_REVIEW,
        ]);

        $this->workflow->syncCurrentVersionStatus(
            $changeRequest->fresh(['currentVersionRow']),
            ChangeRequest::STATUS_UNDER_REVIEW
        );

        $this->activityLogs->log([
            'action' => 'wc.change_request.assign',
            'description' => 'Assigned request to user ID '.$request->approver_id,
            'user' => $user,
            'subject' => $changeRequest,
            'request' => $request,
        ]);

        return response()->json($changeRequest->fresh(['editor', 'approver', 'section', 'currentVersionRow'])->toApiArray());
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $changeRequest = ChangeRequest::with(['section', 'currentVersionRow'])->findOrFail($id);
        $user = $request->user();

        $this->gate->assertCan($user, 'wc_review_change_requests');
        $this->workflow->assertReviewable($changeRequest, $user);

        if ($request->filled('scheduled_at')) {
            $scheduledAt = Carbon::parse($request->scheduled_at);

            if ($scheduledAt->lessThanOrEqualTo(now())) {
                $result = ChangeRequestPublishService::publish($changeRequest, $user->id);

                return response()->json([
                    'message' => $result['cpanel_synced']
                        ? 'Approved and published to the live advisor site.'
                        : 'Approved in the hub database, but the live site was not updated. Check Laravel logs and that cpanel_domain points to the live template URL (e.g. '.rtrim((string) config('app.url'), '/').'/template4)',
                    'status' => ChangeRequest::STATUS_APPROVED,
                    'cpanel_synced' => $result['cpanel_synced'],
                    'change_request' => $changeRequest->fresh(['editor', 'approver', 'section', 'currentVersionRow'])->toApiArray(),
                ]);
            }

            $changeRequest->update([
                'status' => ChangeRequest::STATUS_SCHEDULED,
                'scheduled_at' => $scheduledAt,
                'approver_id' => $changeRequest->approver_id ?: $this->gate->tenantUserIdOrNull($user),
            ]);

            $this->workflow->syncCurrentVersionStatus(
                $changeRequest->fresh(['currentVersionRow']),
                ChangeRequest::STATUS_SCHEDULED,
                $user
            );

            $hubId = null;
            if (WcDatabaseContext::active()) {
                $actingHub = app(ActingHubService::class)->actingHub($user);
                if ($actingHub->isWhiteLabel()) {
                    $hubId = (int) $actingHub->id;
                }
            }

            // Programmatic timer: delayed queue job fires at scheduled_at (requires queue worker).
            PublishScheduledChangeRequestJob::dispatch(
                (int) $changeRequest->id,
                $hubId,
                $scheduledAt->toIso8601String(),
            )->delay($scheduledAt);

            $this->activityLogs->log([
                'action' => 'wc.change_request.schedule',
                'description' => 'Content approved and scheduled for '.$scheduledAt->toIso8601String(),
                'user' => $user,
                'subject' => $changeRequest,
                'request' => $request,
                'properties' => [
                    'scheduled_at' => $scheduledAt->toIso8601String(),
                    'hub_id' => $hubId,
                    'dispatch' => 'delayed_job',
                ],
            ]);

            return response()->json([
                'message' => 'Change request approved & scheduled for '.$scheduledAt->toIso8601String(),
                'status' => ChangeRequest::STATUS_SCHEDULED,
                'scheduled_at' => $scheduledAt->toIso8601String(),
                'change_request' => $changeRequest->fresh(['editor', 'approver', 'section', 'currentVersionRow'])->toApiArray(),
            ]);
        }

        $result = ChangeRequestPublishService::publish($changeRequest, $user->id);

        return response()->json([
            'message' => $result['cpanel_synced']
                ? 'Approved and published to the live advisor site.'
                : 'Approved in the hub database, but the live site was not updated. Check Laravel logs and that cpanel_domain points to the live template URL (e.g. '.rtrim((string) config('app.url'), '/').'/template4)',
            'status' => ChangeRequest::STATUS_APPROVED,
            'cpanel_synced' => $result['cpanel_synced'],
            'change_request' => $changeRequest->fresh(['editor', 'approver', 'section', 'currentVersionRow'])->toApiArray(),
        ]);
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $request->validate(['rejection_reason' => 'required|string']);

        $changeRequest = ChangeRequest::with(['section', 'currentVersionRow'])->findOrFail($id);
        $user = $request->user();

        $this->gate->assertCan($user, 'wc_review_change_requests');
        $this->workflow->assertReviewable($changeRequest, $user);

        $proposed = $changeRequest->resolvedProposedContent();
        $this->workflow->unlockSectionsFromProposedContent($proposed, $changeRequest->section);

        $changeRequest->update([
            'status' => ChangeRequest::STATUS_REJECTED,
            'rejection_reason' => $request->rejection_reason,
            'feedback' => null,
            'scheduled_at' => null,
        ]);

        $this->workflow->syncCurrentVersionStatus(
            $changeRequest->fresh(['currentVersionRow']),
            ChangeRequest::STATUS_REJECTED,
            $user,
            $request->rejection_reason
        );

        $this->activityLogs->log([
            'action' => 'wc.change_request.reject',
            'description' => 'Change request rejected: '.$request->rejection_reason,
            'user' => $user,
            'subject' => $changeRequest,
            'request' => $request,
        ]);

        return response()->json([
            'message' => 'Request rejected and sections unlocked for advisor re-editing.',
            'change_request' => $changeRequest->fresh(['editor', 'approver', 'section', 'currentVersionRow'])->toApiArray(),
        ]);
    }

    public function approveWithFeedback(Request $request, int $id): JsonResponse
    {
        $request->validate(['feedback' => 'required|string|max:10000']);

        $user = $request->user();
        $this->gate->assertCan($user, 'wc_review_change_requests');

        $changeRequest = ChangeRequest::with(['section', 'currentVersionRow'])->findOrFail($id);
        $updated = $this->workflow->approveWithFeedback($changeRequest, $user, $request->feedback, $request);

        return response()->json([
            'message' => 'Request approved with feedback. Sections unlocked for the editor to address notes.',
            'status' => ChangeRequest::STATUS_APPROVED_WITH_FEEDBACK,
            'change_request' => $updated->toApiArray(),
        ]);
    }

    public function resubmit(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->gate->assertCan($user, 'wc_submit_change_requests');

        $request->validate([
            'section_edits' => 'required|array|min:1',
            'section_edits.*.section_id' => ['required', Rule::exists(Section::class, 'id')],
            'section_edits.*.proposed_content' => 'required|string',
            'section_edits.*.current_content' => 'nullable|string',
        ]);

        $changeRequest = ChangeRequest::findOrFail($id);
        $updated = $this->workflow->resubmit($changeRequest, $user, $request->section_edits, $request);

        return response()->json([
            'message' => 'Change request resubmitted for review.',
            'change_request' => $updated->toApiArray(true),
        ]);
    }

    public function confirmFeedback(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->gate->assertCan($user, 'wc_submit_change_requests');

        $request->validate([
            'section_edits' => 'nullable|array|min:1',
            'section_edits.*.section_id' => ['required_with:section_edits', Rule::exists(Section::class, 'id')],
            'section_edits.*.proposed_content' => 'required_with:section_edits|string',
            'section_edits.*.current_content' => 'nullable|string',
        ]);

        $changeRequest = ChangeRequest::with(['section', 'currentVersionRow'])->findOrFail($id);
        $sectionEdits = $request->has('section_edits') ? $request->section_edits : null;
        $result = $this->workflow->confirmFeedback($changeRequest, $user, $sectionEdits, $request);

        return response()->json([
            'message' => $result['cpanel_synced']
                ? 'Feedback confirmed and published to the live advisor site.'
                : 'Feedback confirmed in the hub database, but the live site was not updated.',
            'status' => ChangeRequest::STATUS_APPROVED,
            'cpanel_synced' => $result['cpanel_synced'],
            'change_request' => $result['change_request']->toApiArray(true),
        ]);
    }

    public function preview(Request $request, int $id): JsonResponse
    {
        $this->gate->assertModuleEnabled($request->user());

        $changeRequest = ChangeRequest::with(['section', 'currentVersionRow', 'versions'])->findOrFail($id);

        $versionNumber = $request->query('version');
        $proposedRaw = null;

        if ($versionNumber !== null && $versionNumber !== '') {
            $versionRow = $changeRequest->versions
                ->firstWhere('version_number', (int) $versionNumber);

            if (! $versionRow) {
                return response()->json(['message' => 'Version not found for this change request.'], 404);
            }

            $proposedRaw = $versionRow->proposed_content;
        } else {
            $proposedRaw = $changeRequest->resolvedProposedContent();
        }

        $decoded = json_decode((string) $proposedRaw, true);
        $branding = $this->resolvePreviewBranding($changeRequest, is_array($decoded) ? $decoded : null);

        if (is_array($decoded)) {
            $items = [];
            foreach ($decoded as $editItem) {
                $sec = isset($editItem['section_id']) ? Section::find($editItem['section_id']) : null;
                $items[] = [
                    'section_id' => $editItem['section_id'] ?? null,
                    'section_name' => $editItem['section_name'] ?? ($sec ? $sec->name : 'Section'),
                    'current_content' => $editItem['current_content'] ?? ($sec ? $sec->content : null),
                    'proposed_content' => $editItem['proposed_content'] ?? null,
                ];
            }

            return response()->json(array_merge([
                'is_batch' => true,
                'edits' => $items,
                'version_number' => $versionNumber !== null && $versionNumber !== ''
                    ? (int) $versionNumber
                    : (int) ($changeRequest->current_version ?: 1),
            ], $branding));
        }

        return response()->json(array_merge([
            'is_batch' => false,
            'current_content' => $changeRequest->section ? $changeRequest->section->content : null,
            'proposed_content' => $proposedRaw,
            'version_number' => $versionNumber !== null && $versionNumber !== ''
                ? (int) $versionNumber
                : (int) ($changeRequest->current_version ?: 1),
        ], $branding));
    }

    /**
     * Branding for in-hub preview must come from the advisor TemplateRequest,
     * not the shared showcase template colours.
     *
     * @param  array<int, array<string, mixed>>|null  $batchEdits
     * @return array<string, mixed>
     */
    private function resolvePreviewBranding(ChangeRequest $changeRequest, ?array $batchEdits): array
    {
        $section = $changeRequest->section;

        if (! $section && is_array($batchEdits)) {
            foreach ($batchEdits as $editItem) {
                if (! empty($editItem['section_id'])) {
                    $section = Section::find($editItem['section_id']);
                    if ($section) {
                        break;
                    }
                }
            }
        }

        $templateRequest = $this->resolveTemplateRequestForSection($section);

        if (! $templateRequest) {
            return [
                'primary_color' => null,
                'secondary_color' => null,
                'logo_url' => null,
                'favicon_url' => null,
                'template_request_id' => null,
                'advisor_id' => $section?->advisor_id,
                'site_url' => null,
                'template_name' => null,
            ];
        }

        $siteUrl = $templateRequest->cpanel_domain
            ? CpanelSyncService::normalizeAdvisorSiteUrl($templateRequest->cpanel_domain)
            : null;

        // Only pass colours that were actually saved on the deployment.
        // Invented defaults (hub greens / showcase navy-red) override the
        // template's own CSS in iframe previews.
        return [
            'primary_color' => $templateRequest->primary_color ?: null,
            'secondary_color' => $templateRequest->secondary_color ?: null,
            'logo_url' => CpanelSyncService::absoluteAssetUrl($templateRequest->logo_url),
            'favicon_url' => CpanelSyncService::absoluteAssetUrl($templateRequest->favicon_url),
            'template_request_id' => $templateRequest->id,
            'advisor_id' => $section?->advisor_id
                ?? $templateRequest->advisor_id
                ?? $templateRequest->assigned_advisor_id,
            'site_url' => $siteUrl !== '' ? $siteUrl : null,
            'template_name' => $templateRequest->template_name,
        ];
    }

    private function resolveTemplateRequestForSection(?Section $section): ?TemplateRequest
    {
        if (! $section) {
            return null;
        }

        if ($section->template_request_id) {
            $byId = TemplateRequest::find((int) $section->template_request_id);
            if ($byId) {
                return $byId;
            }
        }

        if (! $section->advisor_id) {
            return null;
        }

        return CpanelSyncService::findDeployedRequest($section->advisor_id);
    }
}
