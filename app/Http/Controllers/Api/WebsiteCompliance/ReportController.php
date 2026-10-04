<?php

namespace App\Http\Controllers\Api\WebsiteCompliance;

use App\Http\Controllers\Controller;
use App\Models\ComplianceAuditEvent;
use App\Models\User;
use App\Models\WebsiteCompliance\ChangeRequest;
use App\Models\WebsiteCompliance\PlatformReport;
use App\Models\WebsiteCompliance\Template;
use App\Models\WebsiteCompliance\TemplateRequest;
use App\Support\WebsiteCompliance\WcDatabaseContext;
use App\Services\ActivityLogService;
use App\Services\ComplianceAuditTrailService;
use App\Services\WebsiteCompliance\WebsiteComplianceGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ReportController extends Controller
{
    public function __construct(
        private readonly WebsiteComplianceGate $gate,
        private readonly ActivityLogService $activityLogs,
        private readonly ComplianceAuditTrailService $auditTrail
    ) {}

    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->gate->assertCan($user, 'wc_view_platform_report');

        try {
            // Always return live WC metrics. Snapshots are for audit / Refresh only.
            $latest = PlatformReport::query()->latest('generated_at')->first();

            return response()->json($this->liveSummaryPayload($user, $latest));
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Unable to load the platform summary.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function refresh(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->gate->assertCan($user, 'wc_view_platform_report');

        try {
            $report = $this->captureSnapshot($user);

            $this->activityLogs->log([
                'action' => 'wc.platform_report.refresh',
                'description' => 'Generated a new platform summary report snapshot.',
                'user' => $user,
                'subject' => $report,
                'request' => $request,
            ]);

            return response()->json($this->liveSummaryPayload($user, $report));
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Unable to load the platform summary.',
                'error' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->gate->assertCan($user, 'wc_view_platform_report');

        $reports = PlatformReport::query()
            ->latest('generated_at')
            ->paginate(20);

        // Historical snapshots stay as stored — do not overlay today's live counts.
        $reports->getCollection()->transform(
            fn (PlatformReport $report) => $this->snapshotPayload($report, $user)
        );

        return response()->json($reports);
    }

    /**
     * Detailed change-request report with full audit trail per request.
     */
    public function changeRequests(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->gate->assertCan($user, 'wc_view_platform_report');

        $limit = max(1, min(500, (int) $request->integer('limit', 500)));

        $rows = ChangeRequest::query()
            ->with([
                'editor:id,name,email,role,firm_id',
                'editor.firm:id,name',
                'onBehalfBy:id,name,email,role',
                'approver:id,name,email,role',
                'currentVersionRow',
                'section:id,name,display_name',
            ])
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        $auditByRequest = $this->auditTrail->forSubjects(
            ComplianceAuditEvent::MODULE_WC,
            $rows->pluck('id')->all()
        );

        $byStatus = [];
        foreach (ChangeRequest::STATUSES as $status) {
            $byStatus[$status] = 0;
        }

        $export = [];
        foreach ($rows as $row) {
            $status = (string) $row->status;
            $byStatus[$status] = ($byStatus[$status] ?? 0) + 1;
            $auditTrail = $auditByRequest[(int) $row->id] ?? [];

            $export[] = [
                'id' => $row->id,
                'status' => $status,
                'current_version' => (int) ($row->current_version ?: 1),
                'submitted_by' => $row->onBehalfBy?->name ?: $row->editor?->name,
                'submitter_email' => $row->editor?->email,
                'submitter_role' => $row->editor?->role,
                'firm_name' => $row->editor?->firm?->name,
                'on_behalf_by' => $row->onBehalfBy?->name,
                'on_behalf_by_email' => $row->onBehalfBy?->email,
                'on_behalf_by_role' => $row->onBehalfBy?->role,
                'assigned_to' => $row->approver?->name,
                'assigned_to_email' => $row->approver?->email,
                'assigned_to_role' => $row->approver?->role,
                'reviewed_by' => $row->currentVersionRow?->reviewed_by,
                'feedback' => $row->feedback ?: $row->currentVersionRow?->feedback,
                'rejection_reason' => $row->rejection_reason,
                'scheduled_at' => optional($row->scheduled_at)?->toDateTimeString(),
                'section_name' => $row->section?->display_name ?: $row->section?->name,
                'submission_date' => optional($row->created_at)?->toDateTimeString(),
                'reviewed_at' => optional($row->currentVersionRow?->reviewed_at)?->toDateTimeString(),
                'audit_trail' => $auditTrail,
                'audit_trail_summary' => $this->auditTrail->summarizeForExport($auditTrail),
            ];
        }

        $hub = $this->gate->hubFor($user, 'wc_view_platform_report');
        $requestIds = $rows->pluck('id')->map(fn ($id) => (int) $id)->all();

        $this->activityLogs->log([
            'action' => 'wc.reports.change_requests',
            'description' => 'Viewed website compliance change-request audit report',
            'user' => $user,
            'hub' => $hub,
            'request' => $request,
            'properties' => ['total' => count($export)],
        ]);

        return response()->json([
            'report' => [
                'summary' => [
                    'total' => count($export),
                    'by_status' => $byStatus,
                ],
                'rows' => $export,
                'audit_events' => $this->auditTrail->forHub(
                    ComplianceAuditEvent::MODULE_WC,
                    $hub,
                    $requestIds
                ),
                'hub' => [
                    'id' => $hub->id,
                    'name' => $hub->name,
                    'slug' => $hub->slug,
                ],
            ],
            'statuses' => ChangeRequest::STATUSES,
        ]);
    }

    /**
     * Live WC summary for the dashboard (always current DB counts).
     *
     * @return array<string, mixed>
     */
    protected function liveSummaryPayload(User $viewer, ?PlatformReport $latestSnapshot = null): array
    {
        $stats = $this->collectLiveWcStats();
        $payload = $this->formatSummaryFromStats($stats);

        $payload['id'] = $latestSnapshot?->id;
        $payload['generated_at'] = now()->toIso8601String();
        $payload['generated_by'] = $this->resolveGeneratedBy($latestSnapshot, $viewer);
        $payload['snapshot_at'] = optional($latestSnapshot?->generated_at)?->toIso8601String();

        return $payload;
    }

    /**
     * Stored snapshot payload (history list) without live overlays.
     *
     * @return array<string, mixed>
     */
    protected function snapshotPayload(PlatformReport $report, User $viewer): array
    {
        $payload = $report->toSummaryPayload();

        if (empty($payload['generated_by'])) {
            $payload['generated_by'] = [
                'id' => $viewer->id,
                'name' => $viewer->name,
            ];
        }

        return $payload;
    }

    /**
     * @return array{
     *   templates_total: int,
     *   templates_active: int,
     *   template_requests_total: int,
     *   template_requests_by_status: array<string, int>,
     *   template_requests_by_type: array<string, int>,
     *   template_requests_by_template: list<array{template_name: string, total: int}>,
     *   change_requests_total: int,
     *   change_requests_by_status: array<string, int>,
     *   awaiting_advisor: int,
     *   awaiting_approver: int,
     *   avg_version: float,
     *   resubmitted: int
     * }
     */
    protected function collectLiveWcStats(): array
    {
        $templatesTotal = Template::count();
        $templatesActive = Template::where('is_active', true)->count();

        $templateRequestsByStatus = TemplateRequest::query()
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn ($n) => (int) $n)
            ->toArray();

        $templateRequestsByType = TemplateRequest::query()
            ->select('request_type', DB::raw('count(*) as total'))
            ->groupBy('request_type')
            ->pluck('total', 'request_type')
            ->map(fn ($n) => (int) $n)
            ->toArray();

        $templateRequestsByTemplate = TemplateRequest::query()
            ->select('template_name', DB::raw('count(*) as total'))
            ->groupBy('template_name')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => [
                'template_name' => $row->template_name ?: 'unknown',
                'total' => (int) $row->total,
            ])
            ->values()
            ->all();

        $changeRequestsByStatus = ChangeRequest::query()
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn ($n) => (int) $n)
            ->toArray();

        // Pending deployments still needing an advisor assigned (manager assign queue).
        $awaitingAdvisor = TemplateRequest::query()
            ->where('status', 'pending')
            ->whereNull('assigned_advisor_id')
            ->count();

        $awaitingApprover = ChangeRequest::query()
            ->where('status', ChangeRequest::STATUS_PENDING)
            ->whereNull('approver_id')
            ->count();

        [$avgVersion, $resubmitted] = $this->changeRequestVersionStats();

        return [
            'templates_total' => $templatesTotal,
            'templates_active' => $templatesActive,
            'template_requests_total' => TemplateRequest::count(),
            'template_requests_by_status' => $templateRequestsByStatus,
            'template_requests_by_type' => $templateRequestsByType,
            'template_requests_by_template' => $templateRequestsByTemplate,
            'change_requests_total' => ChangeRequest::count(),
            'change_requests_by_status' => $changeRequestsByStatus,
            'awaiting_advisor' => $awaitingAdvisor,
            'awaiting_approver' => $awaitingApprover,
            'avg_version' => $avgVersion,
            'resubmitted' => $resubmitted,
        ];
    }

    /**
     * @param  array<string, mixed>  $stats
     * @return array<string, mixed>
     */
    protected function formatSummaryFromStats(array $stats): array
    {
        $trStatus = $stats['template_requests_by_status'];
        $trType = $stats['template_requests_by_type'];
        $crStatus = $stats['change_requests_by_status'];

        $deployments = [
            'total' => (int) $stats['template_requests_total'],
            'by_status' => [
                'pending' => (int) ($trStatus['pending'] ?? 0),
                // Legacy "deployed" counts as staging under the new lifecycle.
                'staging' => (int) (($trStatus['staging'] ?? 0) + ($trStatus['deployed'] ?? 0)),
                'ready_for_live' => (int) ($trStatus['ready_for_live'] ?? 0),
                'live' => (int) ($trStatus['live'] ?? 0),
                'deployed' => (int) (($trStatus['staging'] ?? 0) + ($trStatus['deployed'] ?? 0)),
                'rejected' => (int) ($trStatus['rejected'] ?? 0),
            ],
            'by_type' => [
                'advisor_website' => (int) ($trType['advisor_website'] ?? 0),
                'hub_main_website' => (int) ($trType['hub_main_website'] ?? 0),
            ],
            'by_template' => $stats['template_requests_by_template'],
            'awaiting_advisor' => (int) $stats['awaiting_advisor'],
        ];

        $openCr = (int) ($crStatus['pending'] ?? 0)
            + (int) ($crStatus['under_review'] ?? 0)
            + (int) ($crStatus['approved'] ?? 0)
            + (int) ($crStatus['scheduled'] ?? 0)
            + (int) ($crStatus['approved_with_feedback'] ?? 0);

        return [
            'templates' => [
                'total' => (int) $stats['templates_total'],
                'active' => (int) $stats['templates_active'],
                'inactive' => max(0, (int) $stats['templates_total'] - (int) $stats['templates_active']),
            ],
            'deployments' => $deployments,
            // Keep legacy key for older clients
            'template_requests' => [
                'total' => $deployments['total'],
                'by_status' => $deployments['by_status'],
                'by_type' => $deployments['by_type'],
                'by_template' => $deployments['by_template'],
                'awaiting_advisor' => $deployments['awaiting_advisor'],
            ],
            'change_requests' => [
                'total' => (int) $stats['change_requests_total'],
                'by_status' => [
                    'pending' => (int) ($crStatus['pending'] ?? 0),
                    'under_review' => (int) ($crStatus['under_review'] ?? 0),
                    'scheduled' => (int) ($crStatus['scheduled'] ?? 0),
                    'approved' => (int) ($crStatus['approved'] ?? 0),
                    'published' => (int) ($crStatus['published'] ?? 0),
                    'rejected' => (int) ($crStatus['rejected'] ?? 0),
                    'approved_with_feedback' => (int) ($crStatus['approved_with_feedback'] ?? 0),
                ],
                'open' => $openCr,
                'awaiting_assignment' => (int) $stats['awaiting_approver'],
                'avg_version' => round((float) $stats['avg_version'], 2),
                'resubmitted' => (int) $stats['resubmitted'],
            ],
        ];
    }

    /**
     * @return array{0: float, 1: int}
     */
    protected function changeRequestVersionStats(): array
    {
        $connection = WcDatabaseContext::connection()
            ?: (new ChangeRequest)->getConnectionName()
            ?: (string) config('database.default');

        if (! Schema::connection($connection)->hasTable('wc_change_requests')
            || ! Schema::connection($connection)->hasColumn('wc_change_requests', 'current_version')) {
            return [1.0, 0];
        }

        $avgVersion = (float) (ChangeRequest::query()->avg(DB::raw('COALESCE(current_version, 1)')) ?: 1);
        $resubmitted = (int) ChangeRequest::query()->where('current_version', '>', 1)->count();

        return [$avgVersion, $resubmitted];
    }

    /**
     * @return array{id: int, name: string}|null
     */
    protected function resolveGeneratedBy(?PlatformReport $latestSnapshot, User $viewer): ?array
    {
        if ($latestSnapshot) {
            try {
                $generator = $latestSnapshot->generator;
                if ($generator) {
                    return [
                        'id' => $generator->id,
                        'name' => $generator->name,
                    ];
                }
            } catch (Throwable) {
                // Shared control-plane user id may not exist on the tenant DB.
            }
        }

        return [
            'id' => $viewer->id,
            'name' => $viewer->name,
        ];
    }

    protected function captureSnapshot(User $user): PlatformReport
    {
        $stats = $this->collectLiveWcStats();
        $trStatus = $stats['template_requests_by_status'];
        $trType = $stats['template_requests_by_type'];
        $crStatus = $stats['change_requests_by_status'];

        // Role census columns remain on the table for backwards compatibility but are no longer
        // part of the WC report payload — keep zeros so we do not store hub HR data in WC reports.
        return PlatformReport::create([
            'templates_total' => $stats['templates_total'],
            'templates_active' => $stats['templates_active'],
            'templates_inactive' => max(0, $stats['templates_total'] - $stats['templates_active']),
            'users_total' => 0,
            'advisors_count' => 0,
            'approvers_count' => 0,
            'managers_count' => 0,
            'client_admins_count' => 0,
            'power_admins_count' => 0,
            'template_requests_total' => $stats['template_requests_total'],
            'template_requests_pending' => (int) ($trStatus['pending'] ?? 0),
            'template_requests_deployed' => (int) ($trStatus['deployed'] ?? 0),
            'template_requests_rejected' => (int) ($trStatus['rejected'] ?? 0),
            'template_requests_advisor_website' => (int) ($trType['advisor_website'] ?? 0),
            'template_requests_hub_main_website' => (int) ($trType['hub_main_website'] ?? 0),
            'template_requests_by_template' => $stats['template_requests_by_template'],
            'change_requests_total' => $stats['change_requests_total'],
            'change_requests_pending' => (int) ($crStatus['pending'] ?? 0),
            'change_requests_under_review' => (int) ($crStatus['under_review'] ?? 0),
            'change_requests_scheduled' => (int) ($crStatus['scheduled'] ?? 0),
            'change_requests_approved' => (int) ($crStatus['approved'] ?? 0),
            'change_requests_published' => (int) ($crStatus['published'] ?? 0),
            'change_requests_rejected' => (int) ($crStatus['rejected'] ?? 0),
            'change_requests_approved_with_feedback' => (int) ($crStatus['approved_with_feedback'] ?? 0),
            'generated_by' => $this->gate->tenantUserIdOrNull($user),
            'generated_at' => now(),
        ]);
    }
}
