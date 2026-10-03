<?php

namespace App\Services;

use App\Models\Hub;
use App\Models\SupportTicket;
use App\Models\SupportTicketAttachment;
use App\Models\SupportTicketComment;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class SupportTicketService
{
    public const ATTACHMENT_MIMES = 'jpg,jpeg,png,gif,webp,pdf';

    public const MAX_ATTACHMENTS = 8;

    public const MAX_ATTACHMENT_KB = 5120;

    public function __construct(
        private readonly CapabilitiesMatrixService $matrix,
        private readonly ActivityLogService $activityLogs,
        private readonly ActingAdvisorService $actingAdvisors
    ) {}

    public function assertModuleEnabled(Hub $hub): void
    {
        if (! $hub->hasSupportTicketsFunctionality()) {
            throw ValidationException::withMessages([
                'support_tickets' => 'Support Tickets is not enabled for this hub.',
            ]);
        }
    }

    /**
     * @return array{
     *   modules: list<array{key: string, label: string}>,
     *   categories: list<array{key: string, label: string}>,
     *   priorities: list<array{key: string, label: string}>,
     *   statuses: list<string>
     * }
     */
    public function options(): array
    {
        return [
            'modules' => collect(SupportTicket::MODULE_AREAS)
                ->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label])
                ->values()
                ->all(),
            'categories' => collect(SupportTicket::CATEGORY_LABELS)
                ->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label])
                ->values()
                ->all(),
            'priorities' => collect(SupportTicket::PRIORITY_LABELS)
                ->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label])
                ->values()
                ->all(),
            'statuses' => SupportTicket::STATUSES,
        ];
    }

    /**
     * @param  array{
     *   subject: string,
     *   module_area: string,
     *   category?: string,
     *   priority?: string,
     *   description: string,
     *   page_url?: string|null,
     *   browser_info?: string|null,
     *   screenshots?: list<UploadedFile>
     * }  $data
     */
    public function submit(Hub $hub, User $user, array $data, $request = null): SupportTicket
    {
        $this->assertModuleEnabled($hub);

        $role = $this->matrix->effectiveRoleFor($user);
        if (! $this->matrix->roleCan($hub, $role, 'st_submit_ticket')) {
            throw ValidationException::withMessages([
                'capability' => 'You do not have permission to submit support tickets.',
            ]);
        }

        $subjectUser = $this->actingAdvisors->requireSubject($user);

        $subject = trim((string) ($data['subject'] ?? ''));
        $description = trim((string) ($data['description'] ?? ''));
        $moduleArea = (string) ($data['module_area'] ?? '');
        $category = (string) ($data['category'] ?? SupportTicket::CATEGORY_BUG);
        $priority = (string) ($data['priority'] ?? SupportTicket::PRIORITY_MEDIUM);

        if ($subject === '') {
            throw ValidationException::withMessages(['subject' => 'A subject is required.']);
        }
        if ($description === '') {
            throw ValidationException::withMessages(['description' => 'Please describe the issue.']);
        }
        if (! array_key_exists($moduleArea, SupportTicket::MODULE_AREAS)) {
            throw ValidationException::withMessages(['module_area' => 'Select a valid module.']);
        }
        if (! in_array($category, SupportTicket::CATEGORIES, true)) {
            throw ValidationException::withMessages(['category' => 'Select a valid category.']);
        }
        if (! in_array($priority, SupportTicket::PRIORITIES, true)) {
            throw ValidationException::withMessages(['priority' => 'Select a valid priority.']);
        }

        $screenshots = $this->filesFromData($data, 'screenshots');
        $this->assertAttachmentLimits($screenshots);

        $ticket = DB::transaction(function () use ($subjectUser, $user, $subject, $description, $moduleArea, $category, $priority, $data, $screenshots) {
            $ticket = SupportTicket::query()->create([
                'user_id' => $subjectUser->id,
                'subject' => $subject,
                'module_area' => $moduleArea,
                'category' => $category,
                'priority' => $priority,
                'description' => $description,
                'status' => SupportTicket::STATUS_OPEN,
                'page_url' => $this->nullableString($data['page_url'] ?? null, 500),
                'browser_info' => $this->nullableString($data['browser_info'] ?? null, 500),
            ]);

            if ($screenshots !== []) {
                $this->storeScreenshots($ticket, $screenshots, $user);
            }

            SupportTicketComment::query()->create([
                'ticket_id' => $ticket->id,
                'user_id' => $user->id,
                'author_name' => $user->name,
                'body' => 'Ticket submitted.',
                'from_status' => null,
                'to_status' => SupportTicket::STATUS_OPEN,
            ]);

            return $ticket->fresh(['attachments', 'user', 'comments.user']);
        });

        $this->activityLogs->log([
            'action' => 'st.submit',
            'description' => 'Submitted support ticket #'.$ticket->id,
            'user' => $user,
            'hub' => $hub,
            'subject' => $ticket,
            'request' => $request,
            'status_code' => 201,
            'properties' => [
                'module_area' => $moduleArea,
                'category' => $category,
                'priority' => $priority,
                'screenshot_count' => count($screenshots),
            ],
        ]);

        return $ticket;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function listMine(Hub $hub, User $user, array $filters = []): LengthAwarePaginator
    {
        $this->assertModuleEnabled($hub);

        $role = $this->matrix->effectiveRoleFor($user);
        $canOwn = $this->matrix->roleCan($hub, $role, 'st_view_own_tickets')
            || $this->matrix->roleCan($hub, $role, 'st_submit_ticket');
        if (! $canOwn) {
            throw ValidationException::withMessages([
                'capability' => 'You do not have permission to view your support tickets.',
            ]);
        }

        $subject = $this->actingAdvisors->subjectOrNull($user);
        $query = SupportTicket::query()
            ->with(['attachments', 'user:id,name,email,role', 'statusChangedByUser:id,name,email,role'])
            ->orderByDesc('id');

        if (! $subject) {
            $query->whereRaw('1 = 0');
        } else {
            $query->where('user_id', $subject->id);
        }

        $this->applyFilters($query, $filters);

        return $query->paginate(max(1, min(100, (int) ($filters['per_page'] ?? 20))));
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function listAll(Hub $hub, User $user, array $filters = []): LengthAwarePaginator
    {
        $this->assertModuleEnabled($hub);

        $role = $this->matrix->effectiveRoleFor($user);
        $canAll = $this->matrix->roleCan($hub, $role, 'st_view_all_tickets')
            || $this->matrix->roleCan($hub, $role, 'st_change_ticket_status');
        if (! $canAll) {
            throw ValidationException::withMessages([
                'capability' => 'You do not have permission to view all support tickets.',
            ]);
        }

        $query = SupportTicket::query()
            ->with(['attachments', 'user:id,name,email,role', 'statusChangedByUser:id,name,email,role'])
            ->orderByDesc('id');

        $this->applyFilters($query, $filters);

        return $query->paginate(max(1, min(100, (int) ($filters['per_page'] ?? 20))));
    }

    public function show(Hub $hub, User $user, SupportTicket $ticket): SupportTicket
    {
        $this->assertModuleEnabled($hub);
        $this->assertCanView($hub, $user, $ticket);

        return $ticket->fresh([
            'attachments',
            'comments',
            'user:id,name,email,role',
        ]);
    }

    /**
     * @param  array{status: string, comment?: string|null, screenshots?: list<UploadedFile>}  $data
     */
    public function changeStatus(Hub $hub, User $user, SupportTicket $ticket, array $data, $request = null): SupportTicket
    {
        $this->assertModuleEnabled($hub);

        $role = $this->matrix->effectiveRoleFor($user);
        if (! $this->matrix->roleCan($hub, $role, 'st_change_ticket_status')) {
            throw ValidationException::withMessages([
                'capability' => 'You do not have permission to change support ticket status.',
            ]);
        }

        $status = (string) ($data['status'] ?? '');
        if (! in_array($status, SupportTicket::STATUSES, true)) {
            throw ValidationException::withMessages(['status' => 'Select a valid status.']);
        }

        $comment = trim((string) ($data['comment'] ?? ''));
        $screenshots = $this->filesFromData($data, 'screenshots');
        $this->assertAttachmentLimits($screenshots);

        $from = $ticket->status ?: SupportTicket::STATUS_OPEN;

        $ticket = DB::transaction(function () use ($ticket, $user, $status, $comment, $from, $screenshots) {
            $ticket->status = $status;
            $ticket->status_note = $comment !== '' ? $comment : $ticket->status_note;
            $ticket->status_changed_by = $user->id;
            $ticket->status_changed_at = now();

            if ($status === SupportTicket::STATUS_RESOLVED) {
                $ticket->resolved_at = now();
                $ticket->closed_at = null;
            } elseif ($status === SupportTicket::STATUS_CLOSED) {
                $ticket->closed_at = now();
                if (! $ticket->resolved_at) {
                    $ticket->resolved_at = now();
                }
            } else {
                $ticket->resolved_at = null;
                $ticket->closed_at = null;
            }

            $ticket->save();

            if ($screenshots !== []) {
                $this->storeScreenshots($ticket, $screenshots, $user);
            }

            $body = $comment !== ''
                ? $comment
                : 'Status changed from '.$from.' to '.$status.'.';

            SupportTicketComment::query()->create([
                'ticket_id' => $ticket->id,
                'user_id' => $user->id,
                'author_name' => $user->name,
                'body' => $body,
                'from_status' => $from,
                'to_status' => $status,
            ]);

            return $ticket->fresh([
                'attachments',
                'comments',
                'user:id,name,email,role',
            ]);
        });

        $this->activityLogs->log([
            'action' => 'st.change_status',
            'description' => 'Changed support ticket #'.$ticket->id.' status to '.$status,
            'user' => $user,
            'hub' => $hub,
            'subject' => $ticket,
            'request' => $request,
            'status_code' => 200,
            'properties' => [
                'from_status' => $from,
                'to_status' => $status,
            ],
        ]);

        return $ticket;
    }

    /**
     * @param  array{body: string, screenshots?: list<UploadedFile>}  $data
     */
    public function addComment(Hub $hub, User $user, SupportTicket $ticket, array $data, $request = null): SupportTicket
    {
        $this->assertModuleEnabled($hub);
        $this->assertCanView($hub, $user, $ticket);

        $role = $this->matrix->effectiveRoleFor($user);
        if (! $this->matrix->roleCan($hub, $role, 'st_comment_on_tickets')) {
            throw ValidationException::withMessages([
                'capability' => 'You do not have permission to comment on support tickets.',
            ]);
        }

        $body = trim((string) ($data['body'] ?? ''));
        if ($body === '') {
            throw ValidationException::withMessages(['body' => 'Comment cannot be empty.']);
        }

        $screenshots = $this->filesFromData($data, 'screenshots');
        $this->assertAttachmentLimits($screenshots);

        DB::transaction(function () use ($ticket, $user, $body, $screenshots) {
            SupportTicketComment::query()->create([
                'ticket_id' => $ticket->id,
                'user_id' => $user->id,
                'author_name' => $user->name,
                'body' => $body,
                'from_status' => null,
                'to_status' => null,
            ]);

            if ($screenshots !== []) {
                $this->storeScreenshots($ticket, $screenshots, $user);
            }

            $ticket->touch();
        });

        $this->activityLogs->log([
            'action' => 'st.comment',
            'description' => 'Commented on support ticket #'.$ticket->id,
            'user' => $user,
            'hub' => $hub,
            'subject' => $ticket,
            'request' => $request,
            'status_code' => 201,
        ]);

        return $ticket->fresh([
            'attachments',
            'comments',
            'user:id,name,email,role',
        ]);
    }

    public function assertCanView(Hub $hub, User $user, SupportTicket $ticket): void
    {
        $role = $this->matrix->effectiveRoleFor($user);
        $subject = $this->actingAdvisors->subjectOrNull($user);
        $isOwner = (int) $ticket->user_id === (int) $user->id
            || ($subject && (int) $ticket->user_id === (int) $subject->id);

        $canOwn = $this->matrix->roleCan($hub, $role, 'st_view_own_tickets')
            || $this->matrix->roleCan($hub, $role, 'st_submit_ticket');
        $canAll = $this->matrix->roleCan($hub, $role, 'st_view_all_tickets')
            || $this->matrix->roleCan($hub, $role, 'st_change_ticket_status');

        if (($isOwner && $canOwn) || $canAll) {
            return;
        }

        abort(response()->json([
            'message' => 'You do not have permission to view this support ticket.',
        ], 403));
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<SupportTicket>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters($query, array $filters): void
    {
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['module_area'])) {
            $query->where('module_area', $filters['module_area']);
        }
        if (! empty($filters['category'])) {
            $query->where('category', $filters['category']);
        }
        if (! empty($filters['priority'])) {
            $query->where('priority', $filters['priority']);
        }
        if (! empty($filters['q'])) {
            $q = '%'.str_replace(['%', '_'], ['\\%', '\\_'], (string) $filters['q']).'%';
            $query->where(function ($inner) use ($q) {
                $inner->where('subject', 'like', $q)
                    ->orWhere('description', 'like', $q)
                    ->orWhere('id', 'like', ltrim($q, '%'));
            });
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<UploadedFile>
     */
    private function filesFromData(array $data, string $key): array
    {
        $files = $data[$key] ?? [];
        if (! is_array($files)) {
            return [];
        }

        return array_values(array_filter(
            $files,
            fn ($file) => $file instanceof UploadedFile && $file->isValid()
        ));
    }

    /**
     * @param  list<UploadedFile>  $files
     */
    private function assertAttachmentLimits(array $files): void
    {
        if (count($files) > self::MAX_ATTACHMENTS) {
            throw ValidationException::withMessages([
                'screenshots' => 'You may upload at most '.self::MAX_ATTACHMENTS.' screenshots.',
            ]);
        }
    }

    /**
     * @param  list<UploadedFile>  $files
     */
    private function storeScreenshots(SupportTicket $ticket, array $files, User $user): void
    {
        $start = (int) $ticket->attachments()->max('sort_order');
        foreach (array_values($files) as $index => $file) {
            $path = $file->store('support-tickets/'.$ticket->id, 'public');
            SupportTicketAttachment::query()->create([
                'ticket_id' => $ticket->id,
                'original_name' => $file->getClientOriginalName() ?: 'screenshot',
                'file_path' => $path,
                'file_url' => Storage::disk('public')->url($path),
                'mime_type' => $file->getMimeType(),
                'size_bytes' => (int) $file->getSize(),
                'sort_order' => $start + $index + 1,
                'uploaded_by_user_id' => $user->id,
                'uploaded_by_name' => $user->name,
            ]);
        }
    }

    private function nullableString(mixed $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        return mb_substr($text, 0, $max);
    }
}
