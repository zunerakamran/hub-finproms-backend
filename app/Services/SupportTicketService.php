<?php

namespace App\Services;

use App\Models\Hub;
use App\Models\SupportTicket;
use App\Models\SupportTicketAttachment;
use App\Models\SupportTicketComment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class SupportTicketService
{
    public const ATTACHMENT_MIMES = 'jpg,jpeg,png,gif,webp,pdf';

    /** Image/PDF + short guidance clips for staff replies. */
    public const REPLY_ATTACHMENT_MIMES = 'jpg,jpeg,png,gif,webp,pdf,mp4,webm,mov,m4v';

    public const MAX_ATTACHMENTS = 8;

    public const MAX_ATTACHMENT_KB = 5120;

    /** Max size for a single video clip on a staff reply (25 MB). */
    public const MAX_VIDEO_ATTACHMENT_KB = 25600;

    public function __construct(
        private readonly CapabilitiesMatrixService $matrix,
        private readonly ActivityLogService $activityLogs,
        private readonly ActingAdvisorService $actingAdvisors,
        private readonly WhiteLabelUserService $whiteLabelUsers
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
     *   statuses: list<string>,
     *   eta_presets: list<array{key: string, label: string, hours: int}>,
     *   reply_attachment_mimes: string,
     *   max_attachments: int,
     *   max_attachment_kb: int,
     *   max_video_attachment_kb: int
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
            'eta_presets' => [
                ['key' => '1h', 'label' => 'About 1 hour', 'hours' => 1],
                ['key' => '4h', 'label' => 'About 4 hours', 'hours' => 4],
                ['key' => '1d', 'label' => 'About 1 day', 'hours' => 24],
                ['key' => '2d', 'label' => 'About 2 days', 'hours' => 48],
                ['key' => '1w', 'label' => 'About 1 week', 'hours' => 168],
            ],
            'reply_attachment_mimes' => self::REPLY_ATTACHMENT_MIMES,
            'max_attachments' => self::MAX_ATTACHMENTS,
            'max_attachment_kb' => self::MAX_ATTACHMENT_KB,
            'max_video_attachment_kb' => self::MAX_VIDEO_ATTACHMENT_KB,
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

        if (! $this->matrix->userCan($hub, $user, 'st_submit_ticket')) {
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

        $ticket = $this->onTicketConnection(function () use ($subjectUser, $user, $subject, $description, $moduleArea, $category, $priority, $data, $screenshots) {
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

            return $ticket->fresh([
                'attachments',
                'user.firm:id,name,is_central',
                'user.roleAssignments',
                'comments.user',
            ]);
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

        $canOwn = $this->matrix->userCan($hub, $user, 'st_view_own_tickets')
            || $this->matrix->userCan($hub, $user, 'st_submit_ticket');
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

        if (! $this->viewerCanAccessQueue($hub, $user)) {
            throw ValidationException::withMessages([
                'capability' => 'You do not have permission to view all support tickets.',
            ]);
        }

        $query = SupportTicket::query()
            ->with([
                'attachments',
                'user.firm:id,name,is_central',
                'user.roleAssignments',
                'statusChangedByUser:id,name,email,role',
            ])
            ->orderByDesc('id');

        $this->applyFilters($query, $filters);

        return $query->paginate(max(1, min(100, (int) ($filters['per_page'] ?? 20))));
    }

    public function show(Hub $hub, User $user, SupportTicket $ticket): SupportTicket
    {
        $this->assertModuleEnabled($hub);
        $this->assertCanView($hub, $user, $ticket);

        if ($this->viewerCanSeeSubmitterProfile($hub, $user)) {
            $with = [
                'attachments',
                'comments.attachments',
                'user.firm:id,name,is_central',
                'user.roleAssignments',
                'statusChangedByUser:id,name,email,role',
            ];
        } else {
            $with = [
                'attachments',
                'comments.attachments',
                'user:id,name,email,role',
            ];
        }

        return $ticket->fresh($with);
    }

    /**
     * Queue / admin detail: view-all (read-only) or reply staff.
     * Uses userCan (role UNION + overrides) so multi-role matches middleware / FE.
     */
    public function viewerCanAccessQueue(Hub $hub, User $viewer): bool
    {
        return $this->matrix->userCan($hub, $viewer, 'st_view_all_tickets')
            || $this->matrix->userCan($hub, $viewer, 'st_reply_to_tickets');
    }

    /**
     * Whether this viewer may receive the enriched submitter profile on tickets.
     */
    public function viewerCanSeeSubmitterProfile(Hub $hub, User $viewer): bool
    {
        return $this->viewerCanAccessQueue($hub, $viewer);
    }

    /**
     * Staff reply (status + ETA + remarks + media) — st_reply_to_tickets only.
     */
    public function viewerCanReply(Hub $hub, User $viewer): bool
    {
        return $this->matrix->userCan($hub, $viewer, 'st_reply_to_tickets');
    }

    /**
     * Serialize a ticket for API responses, attaching a rich submitter profile when allowed.
     *
     * @param  array<int, string|null>|null  $lastLoginByUserId  Pre-batched last-login map for list endpoints.
     * @return array<string, mixed>
     */
    public function toApiArray(
        SupportTicket $ticket,
        Hub $hub,
        User $viewer,
        bool $includeDetails = false,
        ?array $lastLoginByUserId = null
    ): array {
        $submitterOverride = null;

        if ($this->viewerCanSeeSubmitterProfile($hub, $viewer) && $ticket->user) {
            $lastLogin = null;
            if (is_array($lastLoginByUserId) && array_key_exists((int) $ticket->user_id, $lastLoginByUserId)) {
                $lastLogin = $lastLoginByUserId[(int) $ticket->user_id];
            } elseif ($lastLoginByUserId === null) {
                $map = $this->lastLoginAtByUserIds([(int) $ticket->user_id], $ticket->user);
                $lastLogin = $map[(int) $ticket->user_id] ?? null;
            }

            $submitterOverride = $this->detailedSubmitter($ticket->user, $hub, $lastLogin);
        }

        return $ticket->toApiArray($includeDetails, $submitterOverride);
    }

    /**
     * Batch last-login timestamps for a page of tickets (avoids N+1 on queue).
     *
     * @param  iterable<int, SupportTicket>  $tickets
     * @return array<int, string|null>
     */
    public function lastLoginMapForTickets(iterable $tickets): array
    {
        $userIds = [];
        $sampleUser = null;
        foreach ($tickets as $ticket) {
            if ($ticket->user_id) {
                $userIds[] = (int) $ticket->user_id;
            }
            if (! $sampleUser && $ticket->relationLoaded('user') && $ticket->user) {
                $sampleUser = $ticket->user;
            }
        }

        return $this->lastLoginAtByUserIds(array_values(array_unique($userIds)), $sampleUser);
    }

    /**
     * @return array<string, mixed>
     */
    private function detailedSubmitter(User $user, Hub $hub, ?string $lastLoginAt = null): array
    {
        $base = $this->whiteLabelUsers->serialize($user, $hub);
        $firm = $base['firm'] ?? null;
        $firmName = is_array($firm) ? (string) ($firm['name'] ?? '') : '';
        $firmKind = is_array($firm) ? (string) ($firm['kind'] ?? 'firm') : null;

        return array_merge($base, [
            'profile_detailed' => true,
            'firm_or_network_name' => $firmName !== '' ? $firmName : null,
            'firm_or_network_kind' => $firmKind,
            'last_login_at' => $lastLoginAt,
            'email_verified_at' => $user->email_verified_at
                ? optional($user->email_verified_at)->toIso8601String()
                : null,
        ]);
    }

    /**
     * @param  list<int>  $userIds
     * @return array<int, string|null>
     */
    private function lastLoginAtByUserIds(array $userIds, ?User $connectionHint = null): array
    {
        $userIds = array_values(array_unique(array_filter($userIds)));
        $result = array_fill_keys($userIds, null);
        if ($userIds === []) {
            return $result;
        }

        $connection = $connectionHint?->getConnectionName()
            ?: (new User)->getConnectionName()
            ?: (string) config('database.default');

        try {
            if (! Schema::connection($connection)->hasTable('personal_access_tokens')) {
                return $result;
            }
        } catch (\Throwable) {
            return $result;
        }

        $rows = DB::connection($connection)->table('personal_access_tokens')
            ->select('tokenable_id')
            ->selectRaw('MAX(COALESCE(last_used_at, created_at)) as last_login_at')
            ->where('tokenable_type', User::class)
            ->whereIn('tokenable_id', $userIds)
            ->groupBy('tokenable_id')
            ->get();

        foreach ($rows as $row) {
            $id = (int) $row->tokenable_id;
            $raw = $row->last_login_at ?? null;
            if ($raw === null || $raw === '') {
                continue;
            }
            try {
                $result[$id] = Carbon::parse($raw)->toIso8601String();
            } catch (\Throwable) {
                $result[$id] = (string) $raw;
            }
        }

        return $result;
    }

    /**
     * Staff reply: set status, optional ETA, remarks, and guidance media (images/video).
     *
     * @param  array{
     *   status: string,
     *   remarks?: string|null,
     *   estimated_resolve_at?: string|null,
     *   estimated_hours?: int|float|string|null,
     *   clear_estimated_resolve_at?: bool,
     *   attachments?: list<UploadedFile>,
     *   screenshots?: list<UploadedFile>
     * }  $data
     */
    public function reply(Hub $hub, User $user, SupportTicket $ticket, array $data, $request = null): SupportTicket
    {
        $this->assertModuleEnabled($hub);
        $this->assertCanView($hub, $user, $ticket);

        if (! $this->viewerCanReply($hub, $user)) {
            throw ValidationException::withMessages([
                'capability' => 'You do not have permission to reply to support tickets.',
            ]);
        }

        $status = (string) ($data['status'] ?? '');
        if (! in_array($status, SupportTicket::STATUSES, true)) {
            throw ValidationException::withMessages(['status' => 'Select a valid status.']);
        }

        $remarks = trim((string) ($data['remarks'] ?? $data['comment'] ?? ''));
        if ($remarks === '') {
            throw ValidationException::withMessages([
                'remarks' => 'Please add remarks for the end user.',
            ]);
        }

        $attachments = $this->filesFromData($data, 'attachments');
        if ($attachments === []) {
            $attachments = $this->filesFromData($data, 'screenshots');
        }
        $this->assertReplyAttachmentLimits($attachments);

        $eta = $this->resolveEstimatedAt($data, $ticket);
        $from = $ticket->status ?: SupportTicket::STATUS_OPEN;

        $ticket = $this->onTicketConnection(function () use (
            $ticket,
            $user,
            $status,
            $remarks,
            $from,
            $attachments,
            $eta
        ) {
            $ticket->status = $status;
            $ticket->status_note = $remarks;
            $ticket->status_changed_by = $user->id;
            $ticket->status_changed_at = now();
            $ticket->estimated_resolve_at = $eta;

            $this->applyStatusTimestamps($ticket, $status);
            $ticket->save();

            $comment = SupportTicketComment::query()->create([
                'ticket_id' => $ticket->id,
                'user_id' => $user->id,
                'author_name' => $user->name,
                'body' => $remarks,
                'from_status' => $from,
                'to_status' => $status,
                'estimated_resolve_at' => $eta,
            ]);

            if ($attachments !== []) {
                $this->storeAttachments($ticket, $attachments, $user, (int) $comment->id);
            }

            return $ticket->fresh([
                'attachments',
                'comments.attachments',
                'user.firm:id,name,is_central',
                'user.roleAssignments',
                'statusChangedByUser:id,name,email,role',
            ]);
        });

        $this->activityLogs->log([
            'action' => 'st.reply',
            'description' => 'Replied to support ticket #'.$ticket->id,
            'user' => $user,
            'hub' => $hub,
            'subject' => $ticket,
            'request' => $request,
            'status_code' => 200,
            'properties' => [
                'from_status' => $from,
                'to_status' => $status,
                'estimated_resolve_at' => optional($eta)?->toIso8601String(),
                'attachment_count' => count($attachments),
            ],
        ]);

        return $ticket;
    }

    /**
     * @param  array{status: string, comment?: string|null, screenshots?: list<UploadedFile>}  $data
     */
    public function changeStatus(Hub $hub, User $user, SupportTicket $ticket, array $data, $request = null): SupportTicket
    {
        $this->assertModuleEnabled($hub);

        // Legacy endpoint — same gate as reply (status change alone was retired).
        if (! $this->viewerCanReply($hub, $user)) {
            throw ValidationException::withMessages([
                'capability' => 'You do not have permission to reply to support tickets.',
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

        $ticket = $this->onTicketConnection(function () use ($ticket, $user, $status, $comment, $from, $screenshots) {
            $ticket->status = $status;
            $ticket->status_note = $comment !== '' ? $comment : $ticket->status_note;
            $ticket->status_changed_by = $user->id;
            $ticket->status_changed_at = now();

            $this->applyStatusTimestamps($ticket, $status);
            $ticket->save();

            $body = $comment !== ''
                ? $comment
                : 'Status changed from '.$from.' to '.$status.'.';

            $row = SupportTicketComment::query()->create([
                'ticket_id' => $ticket->id,
                'user_id' => $user->id,
                'author_name' => $user->name,
                'body' => $body,
                'from_status' => $from,
                'to_status' => $status,
            ]);

            if ($screenshots !== []) {
                $this->storeAttachments($ticket, $screenshots, $user, (int) $row->id);
            }

            return $ticket->fresh([
                'attachments',
                'comments.attachments',
                'user.firm:id,name,is_central',
                'user.roleAssignments',
                'statusChangedByUser:id,name,email,role',
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

        // Staff should use Reply. Members may comment on their own tickets.
        if ($this->viewerCanReply($hub, $user)) {
            throw ValidationException::withMessages([
                'capability' => 'Use Reply to post status, ETA, remarks, and media on this ticket.',
            ]);
        }

        if (! $this->viewerIsTicketOwner($hub, $user, $ticket)) {
            throw ValidationException::withMessages([
                'capability' => 'You can only comment on your own support tickets.',
            ]);
        }

        $body = trim((string) ($data['body'] ?? ''));
        if ($body === '') {
            throw ValidationException::withMessages(['body' => 'Comment cannot be empty.']);
        }

        $screenshots = $this->filesFromData($data, 'screenshots');
        $this->assertAttachmentLimits($screenshots);

        $this->onTicketConnection(function () use ($ticket, $user, $body, $screenshots) {
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
            'comments.attachments',
            'user.firm:id,name,is_central',
            'user.roleAssignments',
            'statusChangedByUser:id,name,email,role',
        ]);
    }

    public function assertCanView(Hub $hub, User $user, SupportTicket $ticket): void
    {
        $isOwner = $this->viewerIsTicketOwner($hub, $user, $ticket);

        $canOwn = $this->matrix->userCan($hub, $user, 'st_view_own_tickets')
            || $this->matrix->userCan($hub, $user, 'st_submit_ticket');

        if (($isOwner && $canOwn) || $this->viewerCanAccessQueue($hub, $user)) {
            return;
        }

        abort(response()->json([
            'message' => 'You do not have permission to view this support ticket.',
        ], 403));
    }

    public function viewerIsTicketOwner(Hub $hub, User $user, SupportTicket $ticket): bool
    {
        $subject = $this->actingAdvisors->subjectOrNull($user);

        return (int) $ticket->user_id === (int) $user->id
            || ($subject && (int) $ticket->user_id === (int) $subject->id);
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
    private function assertReplyAttachmentLimits(array $files): void
    {
        if (count($files) > self::MAX_ATTACHMENTS) {
            throw ValidationException::withMessages([
                'attachments' => 'You may attach at most '.self::MAX_ATTACHMENTS.' files.',
            ]);
        }

        foreach ($files as $index => $file) {
            $ext = strtolower((string) $file->getClientOriginalExtension());
            $mime = strtolower((string) ($file->getMimeType() ?: ''));
            $isVideo = str_starts_with($mime, 'video/')
                || in_array($ext, ['mp4', 'webm', 'mov', 'm4v'], true);
            $maxKb = $isVideo ? self::MAX_VIDEO_ATTACHMENT_KB : self::MAX_ATTACHMENT_KB;
            $sizeKb = (int) ceil(((int) $file->getSize()) / 1024);
            if ($sizeKb > $maxKb) {
                $label = $isVideo ? 'video clips' : 'screenshots';
                throw ValidationException::withMessages([
                    "attachments.{$index}" => 'Each '.$label.' must be at most '
                        .round($maxKb / 1024, 1).' MB.',
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveEstimatedAt(array $data, SupportTicket $ticket): ?Carbon
    {
        if (! empty($data['clear_estimated_resolve_at'])) {
            return null;
        }

        if (array_key_exists('estimated_hours', $data) && $data['estimated_hours'] !== null && $data['estimated_hours'] !== '') {
            $hours = (float) $data['estimated_hours'];
            if ($hours <= 0) {
                throw ValidationException::withMessages([
                    'estimated_hours' => 'Estimated hours must be greater than zero.',
                ]);
            }
            if ($hours > 24 * 90) {
                throw ValidationException::withMessages([
                    'estimated_hours' => 'Estimated time cannot exceed 90 days.',
                ]);
            }

            return now()->addHours($hours);
        }

        $raw = $data['estimated_resolve_at'] ?? null;
        if ($raw === null || $raw === '') {
            // Keep existing ETA when the reply omits it (unless cleared above).
            return $ticket->estimated_resolve_at;
        }

        try {
            $parsed = Carbon::parse((string) $raw);
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'estimated_resolve_at' => 'Enter a valid estimated resolve time.',
            ]);
        }

        if ($parsed->lt(now()->subMinute())) {
            throw ValidationException::withMessages([
                'estimated_resolve_at' => 'Estimated resolve time must be in the future.',
            ]);
        }

        return $parsed;
    }

    private function applyStatusTimestamps(SupportTicket $ticket, string $status): void
    {
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
    }

    /**
     * @param  list<UploadedFile>  $files
     */
    private function storeScreenshots(SupportTicket $ticket, array $files, User $user): void
    {
        $this->storeAttachments($ticket, $files, $user, null);
    }

    /**
     * @param  list<UploadedFile>  $files
     */
    private function storeAttachments(
        SupportTicket $ticket,
        array $files,
        User $user,
        ?int $commentId = null
    ): void {
        $start = (int) $ticket->attachments()->max('sort_order');
        foreach (array_values($files) as $index => $file) {
            $path = $file->store('support-tickets/'.$ticket->id, 'public');
            SupportTicketAttachment::query()->create([
                'ticket_id' => $ticket->id,
                'comment_id' => $commentId,
                'original_name' => $file->getClientOriginalName() ?: 'attachment',
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

    /**
     * Run a write unit on the same connection SupportTicket models are remounted onto
     * (acting Shared / WL DB), not the default Central connection.
     *
     * @template T
     * @param  callable(): T  $callback
     * @return T
     */
    private function onTicketConnection(callable $callback): mixed
    {
        $connection = (new SupportTicket)->getConnectionName()
            ?: (string) config('database.default');

        return DB::connection($connection)->transaction($callback);
    }
}
