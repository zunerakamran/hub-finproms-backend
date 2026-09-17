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

        return $payload;
    }

    protected function captureSnapshot(User $user): PlatformReport
    {
        $templatesTotal = Template::count();
        $templatesActive = Template::where('is_active', true)->count();

        $usersByRoleRaw = User::query()
            ->select('role', DB::raw('count(*) as total'))
            ->groupBy('role')
            ->pluck('total', 'role')
            ->toArray();

        $advisors = (int) (($usersByRoleRaw['advisor'] ?? 0) + ($usersByRoleRaw['editor'] ?? 0));
        $approvers = (int) ($usersByRoleRaw['approver'] ?? 0);
        $managers = (int) ($usersByRoleRaw['manager'] ?? 0);
        $clientAdmins = (int) ($usersByRoleRaw['client_admin'] ?? 0) + (int) ($usersByRoleRaw['admin'] ?? 0);
        // Control-plane roles live on shared only — never count them as tenant WC operators.
        $powerAdmins = 0;
        $usersTotal = $advisors + $approvers + $managers + $clientAdmins + $powerAdmins;

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

        return PlatformReport::create([
            'templates_total' => $templatesTotal,
            'templates_active' => $templatesActive,
            'templates_inactive' => max(0, $templatesTotal - $templatesActive),
            'users_total' => $usersTotal,
            'advisors_count' => $advisors,
            'approvers_count' => $approvers,
            'managers_count' => $managers,
            'client_admins_count' => $clientAdmins,
            'power_admins_count' => $powerAdmins,
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
            // Never store shared PA/FinProms user ids on the white-label users FK.
            'generated_by' => $this->gate->tenantUserIdOrNull($user),
            'generated_at' => now(),
        ]);
    }
}
