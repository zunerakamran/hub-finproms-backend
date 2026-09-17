<?php

namespace App\Http\Controllers\Api\WebsiteCompliance;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WebsiteCompliance\ChangeRequest;
use App\Models\WebsiteCompliance\Section;
use App\Services\ActivityLogService;
use App\Services\WebsiteCompliance\ChangeRequestPublishService;
use App\Services\WebsiteCompliance\ChangeRequestWorkflowService;
use App\Services\WebsiteCompliance\WebsiteComplianceGate;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ChangeRequestController extends Controller
{
    public function __construct(
        private readonly WebsiteComplianceGate $gate,
        private readonly ActivityLogService $activityLogs,
        private readonly ChangeRequestWorkflowService $workflow
    ) {}

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->gate->assertCan($user, 'wc_submit_change_requests');

        if ($request->has('section_edits') && is_array($request->section_edits)) {
            $request->validate([
                'section_edits' => 'required|array|min:1',
                'section_edits.*.section_id' => 'required|exists:wc_sections,id',
                'section_edits.*.proposed_content' => 'required|string',
                'section_edits.*.current_content' => 'nullable|string',
            ]);

            $edits = $this->workflow->prepareAndLockEdits($request->section_edits, $user);
            $proposedContent = json_encode($edits);
            $primarySectionId = count($edits) === 1 ? $edits[0]['section_id'] : null;

            $changeRequest = ChangeRequest::create([
                'section_id' => $primarySectionId,
                'editor_id' => $this->gate->tenantUserIdOrNull($user),
                'proposed_content' => $proposedContent,
                'status' => ChangeRequest::STATUS_PENDING,
                'current_version' => 1,
            ]);

            $this->workflow->createVersionOne($changeRequest, $proposedContent);

            $this->activityLogs->log([
                'action' => 'wc.change_request.submit',
                'description' => 'Submitted change request for '.count($edits).' section(s)',
                'user' => $user,
                'subject' => $changeRequest,
                'request' => $request,
            ]);

            return response()->json($changeRequest->fresh(['editor', 'section', 'currentVersionRow'])->toApiArray(), 201);
        }

        $request->validate([
            'section_id' => 'required|exists:wc_sections,id',
            'proposed_content' => 'required|string',
            'current_content' => 'nullable|string',
        ]);

        $edits = $this->workflow->prepareAndLockEdits([
            [
                'section_id' => (int) $request->section_id,
                'proposed_content' => $request->proposed_content,
                'current_content' => $request->current_content,
            ],
        ], $user);
        $proposedContent = json_encode($edits);

        $changeRequest = ChangeRequest::create([
            'section_id' => $request->section_id,
            'editor_id' => $this->gate->tenantUserIdOrNull($user),
            'proposed_content' => $proposedContent,
            'status' => ChangeRequest::STATUS_PENDING,
            'current_version' => 1,
        ]);

        $this->workflow->createVersionOne($changeRequest, $proposedContent);

        $this->activityLogs->log([
            'action' => 'wc.change_request.submit',
            'description' => 'Submitted change request',
            'user' => $user,
            'subject' => $changeRequest,
            'request' => $request,
        ]);

        return response()->json($changeRequest->fresh(['editor', 'section', 'currentVersionRow'])->toApiArray(), 201);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->gate->assertModuleEnabled($user);

        if ($this->gate->can($user, 'wc_view_all_change_requests')) {
            $requests = ChangeRequest::with(['section', 'editor', 'approver', 'currentVersionRow'])->latest()->get();
        } elseif ($this->gate->can($user, 'wc_review_change_requests')) {
            $requests = ChangeRequest::with(['section', 'editor', 'approver', 'currentVersionRow'])->latest()->get();
        } else {
            $requests = ChangeRequest::with(['section', 'approver', 'currentVersionRow'])
                ->where('editor_id', $user->id)
                ->latest()
                ->get();
        }

        return response()->json($requests->map(fn (ChangeRequest $cr) => $cr->toApiArray())->values());
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->gate->assertModuleEnabled($user);

        $changeRequest = ChangeRequest::with([
            'section',
            'editor',
            'approver',
            'currentVersionRow',
            'versions',
        ])->findOrFail($id);

        if (
            (int) $changeRequest->editor_id !== (int) $user->id
            && ! $this->gate->can($user, 'wc_view_all_change_requests')
            && ! $this->gate->can($user, 'wc_review_change_requests')
            && ! $this->gate->can($user, 'wc_assign_change_requests')
        ) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return response()->json($changeRequest->toApiArray(true));
    }

    public function assign(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $this->gate->assertCan($user, 'wc_review_change_requests');

        $changeRequest = ChangeRequest::findOrFail($id);

        if ($changeRequest->status !== ChangeRequest::STATUS_PENDING) {
            return response()->json(['message' => 'Request already assigned or processed'], 409);
        }

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

        $changeRequest = ChangeRequest::findOrFail($id);

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

            $this->activityLogs->log([
                'action' => 'wc.change_request.schedule',
                'description' => 'Content approved and scheduled for '.$scheduledAt->toIso8601String(),
                'user' => $user,
                'subject' => $changeRequest,
                'request' => $request,
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
            'section_edits.*.section_id' => 'required|exists:wc_sections,id',
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
            'section_edits.*.section_id' => 'required_with:section_edits|exists:wc_sections,id',
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

        $changeRequest = ChangeRequest::with(['section', 'currentVersionRow'])->findOrFail($id);
        $decoded = json_decode((string) $changeRequest->resolvedProposedContent(), true);

        if (is_array($decoded)) {
            $items = [];
            foreach ($decoded as $editItem) {
                $sec = isset($editItem['section_id']) ? Section::find($editItem['section_id']) : null;
                $items[] = [
                    'section_id' => $editItem['section_id'] ?? null,
                    'section_name' => $editItem['section_name'] ?? ($sec ? $sec->name : 'Section'),
                    'current_content' => $editItem['current_content'] ?? ($sec ? $sec->content : null),
                    'proposed_content' => $editItem['proposed_content'],
                ];
            }

            return response()->json(['is_batch' => true, 'edits' => $items]);
        }

        return response()->json([
            'is_batch' => false,
            'current_content' => $changeRequest->section ? $changeRequest->section->content : null,
            'proposed_content' => $changeRequest->resolvedProposedContent(),
        ]);
    }
}
