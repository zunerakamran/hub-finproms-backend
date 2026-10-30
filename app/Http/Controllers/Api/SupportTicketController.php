<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Hub;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\ActingHubService;
use App\Services\ActivityLogService;
use App\Services\HubService;
use App\Services\SupportTicketService;
use App\Support\DateFormat;
use App\Support\InputSanitizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SupportTicketController extends Controller
{
    public function __construct(
        private readonly SupportTicketService $tickets,
        private readonly HubService $hubs,
        private readonly ActingHubService $actingHubs,
        private readonly ActivityLogService $activityLogs
    ) {}

    private function ticketHub(?User $user): Hub
    {
        if ($user) {
            return $this->actingHubs->capabilityHub($user, 'st_view_all_tickets');
        }

        return $this->hubs->current();
    }

    public function options(Request $request): JsonResponse
    {
        $hub = $this->ticketHub($request->user());
        $this->tickets->assertModuleEnabled($hub);

        return response()->json($this->tickets->options());
    }

    public function mine(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $hub = $this->ticketHub($user);
        $filters = $this->listFilters($request);

        $paginator = $this->tickets->listMine($hub, $user, $filters);

        return response()->json([
            'data' => collect($paginator->items())->map(
                fn (SupportTicket $row) => $this->tickets->toApiArray($row, $hub, $user)
            )->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'filters' => $filters,
            'statuses' => SupportTicket::STATUSES,
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $hub = $this->ticketHub($user);
        $filters = $this->listFilters($request);

        $paginator = $this->tickets->listAll($hub, $user, $filters);
        $items = $paginator->items();
        $lastLogins = $this->tickets->lastLoginMapForTickets($items);

        return response()->json([
            'data' => collect($items)->map(
                fn (SupportTicket $row) => $this->tickets->toApiArray(
                    $row,
                    $hub,
                    $user,
                    false,
                    $lastLogins
                )
            )->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'filters' => $filters,
            'statuses' => SupportTicket::STATUSES,
        ]);
    }

    public function show(Request $request, SupportTicket $supportTicket): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $hub = $this->ticketHub($user);
        $ticket = $this->tickets->show($hub, $user, $supportTicket);

        return response()->json([
            'data' => $this->tickets->toApiArray($ticket, $hub, $user, includeDetails: true),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $hub = $this->ticketHub($user);

        $validated = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'module_area' => ['required', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:50'],
            'priority' => ['nullable', 'string', 'max:20'],
            'description' => ['required', 'string', 'max:20000'],
            'page_url' => ['nullable', 'string', 'max:500'],
            'browser_info' => ['nullable', 'string', 'max:500'],
            'screenshots' => ['nullable', 'array', 'max:'.SupportTicketService::MAX_ATTACHMENTS],
            'screenshots.*' => [
                'file',
                'mimes:'.SupportTicketService::ATTACHMENT_MIMES,
                'max:'.SupportTicketService::MAX_ATTACHMENT_KB,
            ],
        ]);

        $validated = InputSanitizer::plainKeys($validated, [
            'subject', 'module_area', 'category', 'priority', 'page_url', 'browser_info',
        ]);
        $validated = InputSanitizer::richHtmlKeys($validated, ['description']);

        $ticket = $this->tickets->submit($hub, $user, [
            'subject' => $validated['subject'],
            'module_area' => $validated['module_area'],
            'category' => $validated['category'] ?? SupportTicket::CATEGORY_BUG,
            'priority' => $validated['priority'] ?? SupportTicket::PRIORITY_MEDIUM,
            'description' => $validated['description'],
            'page_url' => $validated['page_url'] ?? null,
            'browser_info' => $validated['browser_info'] ?? null,
            'screenshots' => $this->screenshotFiles($request),
        ], $request);

        return response()->json([
            'message' => 'Support ticket submitted.',
            'data' => $this->tickets->toApiArray($ticket, $hub, $user, includeDetails: true),
        ], 201);
    }

    public function reply(Request $request, SupportTicket $supportTicket): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $hub = $this->ticketHub($user);

        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in(SupportTicket::STATUSES)],
            'remarks' => ['required', 'string', 'max:10000'],
            'estimated_resolve_at' => ['nullable', 'date'],
            'estimated_hours' => ['nullable', 'numeric', 'min:0.25', 'max:'.(24 * 90)],
            'clear_estimated_resolve_at' => ['sometimes', 'boolean'],
            'attachments' => ['nullable', 'array', 'max:'.SupportTicketService::MAX_ATTACHMENTS],
            'attachments.*' => [
                'file',
                'mimes:'.SupportTicketService::REPLY_ATTACHMENT_MIMES,
                'max:'.SupportTicketService::MAX_VIDEO_ATTACHMENT_KB,
            ],
            // Legacy alias — same as attachments.
            'screenshots' => ['nullable', 'array', 'max:'.SupportTicketService::MAX_ATTACHMENTS],
            'screenshots.*' => [
                'file',
                'mimes:'.SupportTicketService::REPLY_ATTACHMENT_MIMES,
                'max:'.SupportTicketService::MAX_VIDEO_ATTACHMENT_KB,
            ],
        ]);

        $validated = InputSanitizer::richHtmlKeys($validated, ['remarks']);

        $ticket = $this->tickets->reply($hub, $user, $supportTicket, [
            'status' => $validated['status'],
            'remarks' => $validated['remarks'],
            'estimated_resolve_at' => $validated['estimated_resolve_at'] ?? null,
            'estimated_hours' => $validated['estimated_hours'] ?? null,
            'clear_estimated_resolve_at' => (bool) ($validated['clear_estimated_resolve_at'] ?? false),
            'attachments' => $this->attachmentFiles($request, 'attachments'),
            'screenshots' => $this->attachmentFiles($request, 'screenshots'),
        ], $request);

        return response()->json([
            'message' => 'Reply posted.',
            'data' => $this->tickets->toApiArray($ticket, $hub, $user, includeDetails: true),
        ]);
    }

    public function changeStatus(Request $request, SupportTicket $supportTicket): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $hub = $this->ticketHub($user);

        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in(SupportTicket::STATUSES)],
            'comment' => ['nullable', 'string', 'max:10000'],
            'screenshots' => ['nullable', 'array', 'max:'.SupportTicketService::MAX_ATTACHMENTS],
            'screenshots.*' => [
                'file',
                'mimes:'.SupportTicketService::ATTACHMENT_MIMES,
                'max:'.SupportTicketService::MAX_ATTACHMENT_KB,
            ],
        ]);

        $validated = InputSanitizer::richHtmlKeys($validated, ['comment']);

        $ticket = $this->tickets->changeStatus($hub, $user, $supportTicket, [
            'status' => $validated['status'],
            'comment' => $validated['comment'] ?? null,
            'screenshots' => $this->screenshotFiles($request),
        ], $request);

        return response()->json([
            'message' => 'Ticket status updated.',
            'data' => $this->tickets->toApiArray($ticket, $hub, $user, includeDetails: true),
        ]);
    }

    public function comment(Request $request, SupportTicket $supportTicket): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $hub = $this->ticketHub($user);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:10000'],
            'screenshots' => ['nullable', 'array', 'max:'.SupportTicketService::MAX_ATTACHMENTS],
            'screenshots.*' => [
                'file',
                'mimes:'.SupportTicketService::ATTACHMENT_MIMES,
                'max:'.SupportTicketService::MAX_ATTACHMENT_KB,
            ],
        ]);

        $validated = InputSanitizer::richHtmlKeys($validated, ['body']);

        $ticket = $this->tickets->addComment($hub, $user, $supportTicket, [
            'body' => $validated['body'],
            'screenshots' => $this->screenshotFiles($request),
        ], $request);

        return response()->json([
            'message' => 'Comment added.',
            'data' => $this->tickets->toApiArray($ticket, $hub, $user, includeDetails: true),
        ], 201);
    }

    public function report(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $hub = $this->ticketHub($user);
        $filters = $this->reportFilters($request);

        $report = $this->tickets->report($hub, $user, $filters);

        $this->activityLogs->log([
            'action' => 'st.reports.view',
            'description' => 'Viewed support tickets report',
            'user' => $user,
            'hub' => $hub,
            'request' => $request,
            'status_code' => 200,
            'properties' => [
                'filters' => $filters,
                'total' => $report['summary']['total'] ?? 0,
            ],
        ]);

        return response()->json([
            'hub' => $report['hub'],
            'report' => $report,
            'statuses' => SupportTicket::STATUSES,
            'priorities' => SupportTicket::PRIORITIES,
            'categories' => SupportTicket::CATEGORIES,
            'modules' => SupportTicket::MODULE_AREAS,
            'filter_options' => [
                'period' => ['week', 'month', 'all'],
                'firm_kind' => ['firm', 'network'],
            ],
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        /** @var User $user */
        $user = $request->user();
        $hub = $this->ticketHub($user);
        $filters = $this->reportFilters($request);
        $report = $this->tickets->report($hub, $user, $filters);

        $this->activityLogs->log([
            'action' => 'st.reports.export',
            'description' => 'Exported support tickets report',
            'user' => $user,
            'hub' => $hub,
            'request' => $request,
            'status_code' => 200,
            'properties' => [
                'filters' => $filters,
                'total' => $report['summary']['total'] ?? 0,
            ],
        ]);

        $filename = 'support-tickets-report-'.now()->format('Y-m-d').'.csv';
        $headers = [
            'ID', 'Subject', 'Module', 'Category', 'Priority', 'Status',
            'Submitted By', 'Submitter Email', 'Submitter Role',
            'Firm / Network', 'Firm Kind',
            'Status Note', 'Status Changed By', 'Status Changed At',
            'Estimated Resolve At', 'Resolved At', 'Closed At',
            'Created At', 'Updated At', 'Page URL',
        ];

        return response()->streamDownload(function () use ($report, $headers) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $headers);
            foreach ($report['rows'] as $row) {
                fputcsv($out, [
                    $row['id'],
                    $row['subject'],
                    $row['module_area_label'] ?? $row['module_area'],
                    $row['category_label'] ?? $row['category'],
                    $row['priority_label'] ?? $row['priority'],
                    $row['status'],
                    $row['submitted_by'],
                    $row['submitter_email'],
                    $row['submitter_role'] ?? '',
                    $row['firm_name'] ?? '',
                    $row['firm_kind'] ?? '',
                    $row['status_note'] ?? '',
                    $row['status_changed_by'] ?? '',
                    DateFormat::dateTime($row['status_changed_at'] ?? null),
                    DateFormat::dateTime($row['estimated_resolve_at'] ?? null),
                    DateFormat::dateTime($row['resolved_at'] ?? null),
                    DateFormat::dateTime($row['closed_at'] ?? null),
                    DateFormat::dateTime($row['created_at'] ?? null),
                    DateFormat::dateTime($row['updated_at'] ?? null),
                    $row['page_url'] ?? '',
                ]);
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    public function firmComparison(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $hub = $this->ticketHub($user);
        $filters = $this->reportFilters($request);
        $chart = $this->tickets->firmComparisonChart($hub, $user, $filters);

        $this->activityLogs->log([
            'action' => 'st.charts.firm_comparison',
            'description' => 'Viewed support tickets firm comparison chart',
            'user' => $user,
            'hub' => $hub,
            'request' => $request,
            'status_code' => 200,
            'properties' => ['filters' => $filters],
        ]);

        return response()->json([
            'title' => $chart['title'] ?? 'Firm / network comparison',
            'chart' => $chart,
        ]);
    }

    public function raisedOverTime(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $hub = $this->ticketHub($user);
        $filters = $this->reportFilters($request);
        $chart = $this->tickets->raisedOverTime($hub, $user, $filters);

        $this->activityLogs->log([
            'action' => 'st.charts.raised_over_time',
            'description' => 'Viewed support tickets raised-over-time chart',
            'user' => $user,
            'hub' => $hub,
            'request' => $request,
            'status_code' => 200,
            'properties' => ['filters' => $filters],
        ]);

        return response()->json([
            'title' => $chart['title'] ?? 'Tickets raised over time',
            'chart' => $chart,
        ]);
    }

    /**
     * @return list<UploadedFile>
     */
    private function screenshotFiles(Request $request): array
    {
        return $this->attachmentFiles($request, 'screenshots');
    }

    /**
     * @return list<UploadedFile>
     */
    private function attachmentFiles(Request $request, string $key): array
    {
        $files = $request->file($key, []);
        if ($files instanceof UploadedFile) {
            return [$files];
        }

        if (! is_array($files)) {
            return [];
        }

        return array_values(array_filter(
            $files,
            fn ($file) => $file instanceof UploadedFile
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function listFilters(Request $request): array
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'nullable', 'string', 'max:80'],
            'module_area' => ['sometimes', 'nullable', 'string', 'max:100'],
            'category' => ['sometimes', 'nullable', 'string', 'max:50'],
            'priority' => ['sometimes', 'nullable', 'string', 'max:20'],
            'q' => ['sometimes', 'nullable', 'string', 'max:255'],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return [
            'status' => $validated['status'] ?? null,
            'module_area' => $validated['module_area'] ?? null,
            'category' => $validated['category'] ?? null,
            'priority' => $validated['priority'] ?? null,
            'q' => $validated['q'] ?? null,
            'per_page' => $validated['per_page'] ?? 20,
        ];
    }

    /**
     * Filters aligned with other module reports (from/to/status/q) plus
     * Support Tickets dimensions (module, category, priority, firm).
     *
     * @return array<string, mixed>
     */
    private function reportFilters(Request $request): array
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'nullable', 'string', 'max:80'],
            'module_area' => ['sometimes', 'nullable', 'string', 'max:100'],
            'category' => ['sometimes', 'nullable', 'string', 'max:50'],
            'priority' => ['sometimes', 'nullable', 'string', 'max:20'],
            'user_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'firm_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'firm_kind' => ['sometimes', 'nullable', 'string', 'in:firm,network'],
            'period' => ['sometimes', 'nullable', 'string', 'in:week,month,all'],
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date'],
            'q' => ['sometimes', 'nullable', 'string', 'max:255'],
            'limit' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:500'],
        ]);

        return [
            'status' => $validated['status'] ?? null,
            'module_area' => $validated['module_area'] ?? null,
            'category' => $validated['category'] ?? null,
            'priority' => $validated['priority'] ?? null,
            'user_id' => $validated['user_id'] ?? null,
            'firm_id' => $validated['firm_id'] ?? null,
            'firm_kind' => $validated['firm_kind'] ?? null,
            'period' => $validated['period'] ?? null,
            'from' => $validated['from'] ?? null,
            'to' => $validated['to'] ?? null,
            'q' => $validated['q'] ?? null,
            'limit' => $validated['limit'] ?? 500,
        ];
    }
}
