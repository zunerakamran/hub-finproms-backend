<?php

namespace App\Http\Controllers\Api\WebsiteCompliance;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WebsiteCompliance\ChangeRequest;
use App\Models\WebsiteCompliance\PlatformReport;
use App\Models\WebsiteCompliance\Template;
use App\Models\WebsiteCompliance\TemplateRequest;
use App\Services\ActivityLogService;
use App\Services\WebsiteCompliance\WebsiteComplianceGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class ReportController extends Controller
{
    public function __construct(
        private readonly WebsiteComplianceGate $gate,
        private readonly ActivityLogService $activityLogs
    ) {}

    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->gate->assertCan($user, 'wc_view_platform_report');

        try {
            $latest = PlatformReport::query()->latest('generated_at')->first();

            if (! $latest) {
                $latest = $this->captureSnapshot($user);
            }

            return response()->json($this->summaryPayload($latest, $user));
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

            return response()->json($this->summaryPayload($report, $user));
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

        $reports->getCollection()->transform(
            fn (PlatformReport $report) => $this->summaryPayload($report, $user)
        );

        return response()->json($reports);
    }

    /**
     * @return array<string, mixed>
     */
    protected function summaryPayload(PlatformReport $report, User $viewer): array
    {
        $payload = $report->toSummaryPayload();

        // Generator may be a shared control-plane user id that does not exist on the tenant DB.
        if (empty($payload['generated_by'])) {
            $payload['generated_by'] = [
                'id' => $viewer->id,
                'name' => $viewer->name,
            ];
        }

        return $this->enrichWithLiveWcStats($payload);
    }

    /**
     * Live WC operational extras (not stored on the snapshot row).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function enrichWithLiveWcStats(array $payload): array
    {
        $crStatus = $payload['change_requests']['by_status'] ?? [];
        $openCr = (int) ($crStatus['pending'] ?? 0)
            + (int) ($crStatus['under_review'] ?? 0)
            + (int) ($crStatus['scheduled'] ?? 0)
            + (int) ($crStatus['approved_with_feedback'] ?? 0);

        $awaitingAdvisor = TemplateRequest::query()
            ->where('status', 'pending')
            ->whereNull('assigned_advisor_id')
            ->whereNull('advisor_id')
            ->count();

        $awaitingApprover = ChangeRequest::query()
            ->where('status', 'pending')
            ->whereNull('approver_id')
            ->count();

        $avgVersion = (float) (ChangeRequest::query()->avg(DB::raw('COALESCE(current_version, 1)')) ?: 1);
        $resubmitted = ChangeRequest::query()
            ->where('current_version', '>', 1)
            ->count();

        $payload['deployments']['awaiting_advisor'] = $awaitingAdvisor;
        $payload['template_requests']['awaiting_advisor'] = $awaitingAdvisor;

        $payload['change_requests']['open'] = $openCr;
        $payload['change_requests']['awaiting_assignment'] = $awaitingApprover;
        $payload['change_requests']['avg_version'] = round($avgVersion, 2);
        $payload['change_requests']['resubmitted'] = $resubmitted;

        return $payload;
    }

    protected function captureSnapshot(User $user): PlatformReport
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

        // Role census columns remain on the table for backwards compatibility but are no longer
        // part of the WC report payload — keep zeros so we do not store hub HR data in WC reports.
        return PlatformReport::create([
            'templates_total' => $templatesTotal,
            'templates_active' => $templatesActive,
            'templates_inactive' => max(0, $templatesTotal - $templatesActive),
            'users_total' => 0,
            'advisors_count' => 0,
            'approvers_count' => 0,
            'managers_count' => 0,
            'client_admins_count' => 0,
            'power_admins_count' => 0,
            'template_requests_total' => TemplateRequest::count(),
            'template_requests_pending' => (int) ($templateRequestsByStatus['pending'] ?? 0),
            'template_requests_deployed' => (int) ($templateRequestsByStatus['deployed'] ?? 0),
            'template_requests_rejected' => (int) ($templateRequestsByStatus['rejected'] ?? 0),
            'template_requests_advisor_website' => (int) ($templateRequestsByType['advisor_website'] ?? 0),
            'template_requests_hub_main_website' => (int) ($templateRequestsByType['hub_main_website'] ?? 0),
            'template_requests_by_template' => $templateRequestsByTemplate,
            'change_requests_total' => ChangeRequest::count(),
            'change_requests_pending' => (int) ($changeRequestsByStatus['pending'] ?? 0),
            'change_requests_under_review' => (int) ($changeRequestsByStatus['under_review'] ?? 0),
            'change_requests_scheduled' => (int) ($changeRequestsByStatus['scheduled'] ?? 0),
            'change_requests_approved' => (int) ($changeRequestsByStatus['approved'] ?? 0),
            'change_requests_rejected' => (int) ($changeRequestsByStatus['rejected'] ?? 0),
            'change_requests_approved_with_feedback' => (int) ($changeRequestsByStatus['approved_with_feedback'] ?? 0),
            'generated_by' => $this->gate->tenantUserIdOrNull($user),
            'generated_at' => now(),
        ]);
    }
}
