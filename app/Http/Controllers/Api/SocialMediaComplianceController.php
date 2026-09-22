<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SocialMediaComplianceRequest;
use App\Models\User;
use App\Models\Hub;
use App\Services\ActingHubService;
use App\Services\ActivityLogService;
use App\Services\CapabilitiesMatrixService;
use App\Services\FirmComplianceVisibilityService;
use App\Services\SocialMediaComplianceService;
use App\Services\HubService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SocialMediaComplianceController extends Controller
{
    public function __construct(
        private readonly SocialMediaComplianceService $compliance,
        private readonly HubService $hubs,
        private readonly CapabilitiesMatrixService $matrix,
        private readonly ActivityLogService $activityLogs,
        private readonly ActingHubService $actingHubs,
        private readonly FirmComplianceVisibilityService $firmVisibility
    ) {}

    /**
     * Hub whose Social Media Compliance module / matrix applies for this actor.
     */
    private function smcHub(?User $user): Hub
    {
        if ($user) {
            return $this->actingHubs->capabilityHub($user, 'smc_view_all_requests');
        }

        return $this->hubs->current();
    }

    public function reviewers(Request $request): JsonResponse
    {
        $hub = $this->smcHub($request->user());
        $this->compliance->assertModuleEnabled($hub);

        return response()->json([
            'data' => $this->compliance->reviewersForHub($hub)->values(),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $hub = $this->smcHub($user);
        $filters = $this->listFilters($request);

        $paginator = $this->compliance->listForActor($hub, $user, $filters);

        return response()->json([
            'data' => collect($paginator->items())->map(
                fn (SocialMediaComplianceRequest $row) => $row->toApiArray()
            )->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'filters' => $filters,
            'statuses' => SocialMediaComplianceRequest::STATUSES,
        ]);
    }

    public function mine(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $hub = $this->smcHub($user);
        $this->compliance->assertModuleEnabled($hub);

        $role = (string) $user->role;
        $canOwn = $this->matrix->roleCan($hub, $role, 'smc_view_own_requests')
            || $this->matrix->roleCan($hub, $role, 'smc_submit_request');
        if (! $canOwn) {
            return response()->json([
                'message' => 'This capability is disabled for your role on this hub.',
                'capability' => 'smc_view_own_requests',
            ], 403);
        }

        $filters = $this->listFilters($request);

        $paginator = SocialMediaComplianceRequest::query()
            ->with(['currentVersionRow', 'assignee:id,name,email', 'post:id,title,type', 'user:id,name,email'])
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->paginate(max(1, min(100, (int) ($filters['per_page'] ?? 20))));

        return response()->json([
            'data' => collect($paginator->items())->map(
                fn (SocialMediaComplianceRequest $row) => $row->toApiArray()
            )->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function show(Request $request, SocialMediaComplianceRequest $socialMediaComplianceRequest): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $hub = $this->smcHub($user);
        $this->compliance->assertModuleEnabled($hub);
        $this->authorizeView($hub, $user, $socialMediaComplianceRequest);

        return response()->json([
            'data' => $socialMediaComplianceRequest->fresh([
                'currentVersionRow',
                'versions',
                'assignee:id,name,email',
                'post',
                'user:id,name,email',
            ])->toApiArray(includeVersions: true),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $hub = $this->smcHub($user);

        $validated = $request->validate([
            'post_id' => ['required', 'integer', 'exists:posts,id'],
            'description' => ['required', 'string', 'max:10000'],
            'image' => ['nullable', 'image', 'max:5120'],
        ]);

        $compliance = $this->compliance->submit($hub, $user, [
            'post_id' => (int) $validated['post_id'],
            'description' => $validated['description'],
            'image' => $request->file('image'),
        ], $request);

        return response()->json([
            'message' => 'Social media compliance request submitted.',
            'data' => $compliance->toApiArray(includeVersions: true),
        ], 201);
    }

    public function resubmit(Request $request, SocialMediaComplianceRequest $socialMediaComplianceRequest): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $hub = $this->smcHub($user);

        $validated = $request->validate([
            'description' => ['required', 'string', 'max:10000'],
            'image' => ['nullable', 'image', 'max:5120'],
        ]);

        $compliance = $this->compliance->resubmit($hub, $user, $socialMediaComplianceRequest, [
            'description' => $validated['description'],
            'image' => $request->file('image'),
        ], $request);

        return response()->json([
            'message' => 'Social media compliance request resubmitted.',
            'data' => $compliance->toApiArray(includeVersions: true),
        ]);
    }

    public function confirmFeedback(Request $request, SocialMediaComplianceRequest $socialMediaComplianceRequest): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $hub = $this->smcHub($user);

        $request->validate([
            'image' => ['nullable', 'image', 'max:5120'],
        ]);

        $compliance = $this->compliance->confirmApprovedWithFeedback(
            $hub,
            $user,
            $socialMediaComplianceRequest,
            ['image' => $request->file('image')],
            $request
        );

        return response()->json([
            'message' => 'Request confirmed as approved.',
            'data' => $compliance->toApiArray(includeVersions: true),
        ]);
    }

    public function assign(Request $request, SocialMediaComplianceRequest $socialMediaComplianceRequest): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $hub = $this->smcHub($user);

        $validated = $request->validate([
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $assignTo = array_key_exists('assigned_to', $validated) && $validated['assigned_to']
            ? (int) $validated['assigned_to']
            : null;

        $compliance = $this->compliance->assign($hub, $user, $socialMediaComplianceRequest, $assignTo, $request);

        return response()->json([
            'message' => $assignTo ? 'Request assigned.' : 'Request unassigned.',
            'data' => $compliance->toApiArray(),
        ]);
    }

    public function review(Request $request, SocialMediaComplianceRequest $socialMediaComplianceRequest): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $hub = $this->smcHub($user);

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:'.implode(',', SocialMediaComplianceRequest::STATUSES)],
            'feedback' => ['nullable', 'string', 'max:10000'],
        ]);

        $compliance = $this->compliance->review($hub, $user, $socialMediaComplianceRequest, $validated, $request);

        return response()->json([
            'message' => 'Review saved.',
            'data' => $compliance->toApiArray(includeVersions: true),
        ]);
    }

    public function report(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $hub = $this->smcHub($user);
        $filters = $this->listFilters($request);

        $report = $this->compliance->report($hub, $filters, $user);

        $this->activityLogs->log([
            'action' => 'smc.reports.view',
            'description' => 'Viewed social media compliance report',
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
            'statuses' => SocialMediaComplianceRequest::STATUSES,
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        /** @var User $user */
        $user = $request->user();
        $hub = $this->smcHub($user);
        $filters = $this->listFilters($request);
        $report = $this->compliance->report($hub, $filters, $user);

        $this->activityLogs->log([
            'action' => 'smc.reports.export',
            'description' => 'Exported social media compliance report',
            'user' => $user,
            'hub' => $hub,
            'request' => $request,
            'status_code' => 200,
            'properties' => ['filters' => $filters, 'total' => $report['summary']['total']],
        ]);

        $filename = 'social-media-compliance-report-'.now()->format('Y-m-d').'.csv';
        $headers = [
            'ID', 'Submitted By', 'Submitter Email', 'Post ID', 'Post Title',
            'Current Version', 'Version Count', 'Description', 'Image URL', 'Status',
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
                    $row['post_id'],
                    $row['post_title'],
                    $row['current_version'],
                    $row['version_count'],
                    $row['description'],
                    $row['image_url'],
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
        $hub = $this->smcHub($user);
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
            'action' => 'smc.charts.approver_workload',
            'description' => 'Viewed compliance approver workload chart',
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
        $hub = $this->smcHub($user);
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
            'action' => 'smc.charts.advisor_comparison',
            'description' => 'Viewed compliance advisor comparison chart',
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

    private function authorizeView($hub, User $user, SocialMediaComplianceRequest $compliance): void
    {
        $role = (string) $user->role;
        $isOwner = (int) $compliance->user_id === (int) $user->id;
        $canOwn = $this->matrix->roleCan($hub, $role, 'smc_view_own_requests')
            || $this->matrix->roleCan($hub, $role, 'smc_submit_request');
        $canAll = $this->matrix->roleCan($hub, $role, 'smc_view_all_requests')
            || $this->matrix->roleCan($hub, $role, 'smc_assign_requests');
        $canReviewAssigned = $this->matrix->roleCan($hub, $role, 'smc_review_requests')
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
