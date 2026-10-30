<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Hub;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\ActingHubService;
use App\Services\HubService;
use App\Services\SupportTicketService;
use App\Support\InputSanitizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

class SupportTicketController extends Controller
{
    public function __construct(
        private readonly SupportTicketService $tickets,
        private readonly HubService $hubs,
        private readonly ActingHubService $actingHubs
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
}
