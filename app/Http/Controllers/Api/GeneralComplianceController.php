<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GeneralComplianceRequest;
use App\Models\Hub;
use App\Models\User;
use App\Services\ActingHubService;
use App\Services\ActivityLogService;
use App\Services\CapabilitiesMatrixService;
use App\Services\FirmComplianceVisibilityService;
use App\Services\GeneralComplianceService;
use App\Services\HubService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GeneralComplianceController extends Controller
{
    public function __construct(
        private readonly GeneralComplianceService $compliance,
        private readonly HubService $hubs,
        private readonly CapabilitiesMatrixService $matrix,
        private readonly ActivityLogService $activityLogs,
        private readonly ActingHubService $actingHubs,
        private readonly FirmComplianceVisibilityService $firmVisibility
    ) {}

    /**
     * Hub whose General Compliance module / matrix applies for this actor.
     */
    private function gcHub(?User $user): Hub
    {
        if ($user) {
            return $this->actingHubs->capabilityHub($user, 'gc_view_all_requests');
        }

        return $this->hubs->current();
    }

    public function reviewers(Request $request): JsonResponse
    {
        $hub = $this->gcHub($request->user());
        $this->compliance->assertModuleEnabled($hub);

        return response()->json([
            'data' => $this->compliance->reviewersForHub($hub)->values(),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $hub = $this->gcHub($user);
        $filters = $this->listFilters($request);

        $paginator = $this->compliance->listForActor($hub, $user, $filters);

        return response()->json([
            'data' => collect($paginator->items())->map(
                fn (GeneralComplianceRequest $row) => $row->toApiArray()
            )->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'filters' => $filters,
            'statuses' => GeneralComplianceRequest::STATUSES,
        ]);
    }

    public function mine(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $hub = $this->gcHub($user);
        $this->compliance->assertModuleEnabled($hub);

        $role = (string) $user->role;
        $canOwn = $this->matrix->roleCan($hub, $role, 'gc_view_own_requests')
            || $this->matrix->roleCan($hub, $role, 'gc_submit_request');
        if (! $canOwn) {
            return response()->json([
                'message' => 'This capability is disabled for your role on this hub.',
                'capability' => 'gc_view_own_requests',
            ], 403);
        }

        $filters = $this->listFilters($request);

        $paginator = GeneralComplianceRequest::query()
            ->with(['currentVersionRow.attachments', 'assignee:id,name,email', 'user:id,name,email'])
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->paginate(max(1, min(100, (int) ($filters['per_page'] ?? 20))));

        return response()->json([
            'data' => collect($paginator->items())->map(
                fn (GeneralComplianceRequest $row) => $row->toApiArray()
            )->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function show(Request $request, GeneralComplianceRequest $generalComplianceRequest): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $hub = $this->gcHub($user);
        $this->compliance->assertModuleEnabled($hub);
        $this->authorizeView($hub, $user, $generalComplianceRequest);

        return response()->json([
            'data' => $generalComplianceRequest->fresh([
                'currentVersionRow.attachments',
                'versions.attachments',
                'assignee:id,name,email',
                'user:id,name,email',
            ])->toApiArray(includeVersions: true),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $hub = $this->gcHub($user);

        $validated = $request->validate([
            'description' => ['required', 'string', 'max:10000'],
            'attachments' => ['required', 'array', 'min:1', 'max:'.GeneralComplianceService::MAX_ATTACHMENTS],
            'attachments.*' => [
                'required',
                'file',
                'mimes:'.GeneralComplianceService::ATTACHMENT_MIMES,
                'max:'.GeneralComplianceService::MAX_ATTACHMENT_KB,
            ],
        ]);

        $compliance = $this->compliance->submit($hub, $user, [
            'description' => $validated['description'],
            'attachments' => $request->file('attachments', []),
        ], $request);

        return response()->json([
            'message' => 'General compliance request submitted.',
            'data' => $compliance->toApiArray(includeVersions: true),
        ], 201);
    }

    public function resubmit(Request $request, GeneralComplianceRequest $generalComplianceRequest): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $hub = $this->gcHub($user);

        $validated = $request->validate([
            'description' => ['required', 'string', 'max:10000'],
            'attachments' => ['nullable', 'array', 'max:'.GeneralComplianceService::MAX_ATTACHMENTS],
            'attachments.*' => [
                'file',
                'mimes:'.GeneralComplianceService::ATTACHMENT_MIMES,
                'max:'.GeneralComplianceService::MAX_ATTACHMENT_KB,
            ],
        ]);

        $compliance = $this->compliance->resubmit($hub, $user, $generalComplianceRequest, [
            'description' => $validated['description'],
            'attachments' => $request->file('attachments', []) ?: [],
        ], $request);

        return response()->json([
            'message' => 'General compliance request resubmitted.',
            'data' => $compliance->toApiArray(includeVersions: true),
        ]);
    }

    public function confirmFeedback(Request $request, GeneralComplianceRequest $generalComplianceRequest): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $hub = $this->gcHub($user);

        $request->validate([
            'attachments' => ['nullable', 'array', 'max:'.GeneralComplianceService::MAX_ATTACHMENTS],
            'attachments.*' => [
                'file',
                'mimes:'.GeneralComplianceService::ATTACHMENT_MIMES,
                'max:'.GeneralComplianceService::MAX_ATTACHMENT_KB,
            ],
        ]);

        $compliance = $this->compliance->confirmApprovedWithFeedback(
            $hub,
            $user,
            $generalComplianceRequest,
            ['attachments' => $request->file('attachments', []) ?: []],
            $request
        );

        return response()->json([
            'message' => 'Request confirmed as approved.',
            'data' => $compliance->toApiArray(includeVersions: true),
        ]);
    }

    public function assign(Request $request, GeneralComplianceRequest $generalComplianceRequest): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $hub = $this->gcHub($user);

        $validated = $request->validate([
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $assignTo = array_key_exists('assigned_to', $validated) && $validated['assigned_to']
            ? (int) $validated['assigned_to']
            : null;

        $compliance = $this->compliance->assign($hub, $user, $generalComplianceRequest, $assignTo, $request);

        return response()->json([
            'message' => $assignTo ? 'Request assigned.' : 'Request unassigned.',
            'data' => $compliance->toApiArray(),
        ]);
    }

    public function review(Request $request, GeneralComplianceRequest $generalComplianceRequest): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $hub = $this->gcHub($user);

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:'.implode(',', GeneralComplianceRequest::STATUSES)],
            'feedback' => ['nullable', 'string', 'max:10000'],
        ]);

        $compliance = $this->compliance->review($hub, $user, $generalComplianceRequest, $validated, $request);

        return response()->json([
            'message' => 'Review saved.',
            'data' => $compliance->toApiArray(includeVersions: true),
        ]);
    }

    public function report(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $hub = $this->gcHub($user);
        $filters = $this->listFilters($request);

        $report = $this->compliance->report($hub, $filters, $user);

        $this->activityLogs->log([
            'action' => 'gc.reports.view',
            'description' => 'Viewed general compliance report',
            'user' => $user,
            'hub' => $hub,
            'request' => $request,
            'status_code' => 200,
            'properties' => ['filters' => $filters, 'total' => $report['summary']['total']],
        ]);

        return response()->json([
            'hub' => [
                'id' => $hub->id,
                'name' => $hub->name,
                'slug' => $hub->slug,
            ],
            'report' => $report,
            'statuses' => GeneralComplianceRequest::STATUSES,
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        /** @var User $user */
        $user = $request->user();
        $hub = $this->gcHub($user);
        $filters = $this->listFilters($request);
        $report = $this->compliance->report($hub, $filters, $user);

        $this->activityLogs->log([
            'action' => 'gc.reports.export',
            'description' => 'Exported general compliance report',
            'user' => $user,
            'hub' => $hub,
            'request' => $request,
            'status_code' => 200,
            'properties' => ['filters' => $filters, 'total' => $report['summary']['total']],
        ]);

        $filename = 'general-compliance-report-'.now()->format('Y-m-d').'.csv';
        $headers = [
            'ID', 'Submitted By', 'Submitter Email',
            'Current Version', 'Version Count', 'Description',
            'Attachment Count', 'Attachment Names', 'Status',
            'Assigned To', 'Assigned To Email', 'Reviewed By', 'Feedback',
            'Submission Date', 'Reviewed At',
        ];

        return response()->streamDownload(function () use ($report, $headers) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $headers);
            foreach ($report['rows'] as $row) {
                fputcsv($out, [
                    $row['id'],
                    $row['submitted_by'],
                    $row['submitter_email'],
                    $row['current_version'],
                    $row['version_count'],
                    $row['description'],
                    $row['attachment_count'],
                    $row['attachment_names'],
                    $row['status'],
                    $row['assigned_to'],
                    $row['assigned_to_email'],
                    $row['reviewed_by'],
                    $row['feedback'],
                    $row['submission_date'],
                    $row['reviewed_at'],
                ]);
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    public function approverWorkload(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $hub = $this->gcHub($user);
        $validated = $request->validate([
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date'],
        ]);

        $chart = $this->compliance->approverWorkload(
            $hub,
            $validated['from'] ?? null,
            $validated['to'] ?? null
        );

        $this->activityLogs->log([
            'action' => 'gc.charts.approver_workload',
            'description' => 'Viewed general compliance approver workload chart',
            'user' => $user,
            'hub' => $hub,
            'request' => $request,
            'status_code' => 200,
            'properties' => $validated,
        ]);

        return response()->json([
            'title' => 'Approver Workload',
            'chart' => $chart,
        ]);
    }

    public function advisorComparison(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $hub = $this->gcHub($user);
        $validated = $request->validate([
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date'],
            'status' => ['sometimes', 'nullable', 'string', 'max:80'],
        ]);

        $chart = $this->compliance->advisorComparison(
            $hub,
            $validated['from'] ?? null,
            $validated['to'] ?? null,
            $validated['status'] ?? null
        );

        $this->activityLogs->log([
            'action' => 'gc.charts.advisor_comparison',
            'description' => 'Viewed general compliance advisor comparison chart',
            'user' => $user,
            'hub' => $hub,
            'request' => $request,
            'status_code' => 200,
            'properties' => $validated,
        ]);

        return response()->json([
            'title' => 'Advisor Comparison',
            'chart' => $chart,
        ]);
    }

    private function authorizeView($hub, User $user, GeneralComplianceRequest $compliance): void
    {
        $role = (string) $user->role;
        $isOwner = (int) $compliance->user_id === (int) $user->id;
        $canOwn = $this->matrix->roleCan($hub, $role, 'gc_view_own_requests')
            || $this->matrix->roleCan($hub, $role, 'gc_submit_request');
        $canAll = $this->matrix->roleCan($hub, $role, 'gc_view_all_requests')
            || $this->matrix->roleCan($hub, $role, 'gc_assign_requests');
        $canReviewAssigned = $this->matrix->roleCan($hub, $role, 'gc_review_requests')
            && (int) $compliance->assigned_to === (int) $user->id;

        $allowed = ($isOwner && $canOwn) || $canReviewAssigned;
        if ($canAll) {
            if (! $compliance->relationLoaded('user')) {
                $compliance->load('user:id,firm_id');
            }
            $allowed = $allowed || $this->firmVisibility->actorCanViewRequest(
                $user,
                (int) $compliance->user_id,
                $compliance->user?->firm_id ? (int) $compliance->user->firm_id : null,
                $compliance->assigned_to ? (int) $compliance->assigned_to : null
            );
        }

        if ($allowed) {
            return;
        }

        abort(response()->json([
            'message' => 'You do not have permission to view this compliance request.',
        ], 403));
    }

    /**
     * @return array<string, mixed>
     */
    private function listFilters(Request $request): array
    {
        $validated = $request->validate([
            'user_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'assigned_to' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'status' => ['sometimes', 'nullable', 'string', 'max:80'],
            'q' => ['sometimes', 'nullable', 'string', 'max:255'],
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date'],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return [
            'user_id' => $validated['user_id'] ?? null,
            'assigned_to' => $validated['assigned_to'] ?? null,
            'status' => $validated['status'] ?? null,
            'q' => $validated['q'] ?? null,
            'from' => $validated['from'] ?? null,
            'to' => $validated['to'] ?? null,
            'per_page' => $validated['per_page'] ?? 20,
        ];
    }
}
