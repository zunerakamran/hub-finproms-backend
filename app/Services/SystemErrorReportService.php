<?php

namespace App\Services;

use App\Models\Hub;
use App\Models\SystemErrorReport;
use App\Models\User;
use App\Support\WebsiteCompliance\WcDatabaseContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Throwable;

class SystemErrorReportService
{
    public function __construct(
        private readonly ActivityLogService $activityLogs,
        private readonly HubService $hubs
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
     * Ensure table exists on the active (possibly remote) hub connection.
     */
    public function ensureSchema(): void
    {
        $connection = WcDatabaseContext::active()
            ? WcDatabaseContext::connection()
            : (string) config('database.default');

        $schema = Schema::connection($connection);

        if ($schema->hasTable('system_error_reports')) {
            return;
        }

        $schema->create('system_error_reports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('hub_id')->nullable()->index();
            $table->string('fingerprint', 64)->index();
            $table->string('source', 20);
            $table->string('level', 20)->default('error');
            $table->string('message', 1000);
            $table->string('exception_class', 255)->nullable();
            $table->text('stack_trace')->nullable();
            $table->string('page_url', 500)->nullable();
            $table->string('route', 255)->nullable();
            $table->string('http_method', 20)->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('user_name')->nullable();
            $table->string('user_email')->nullable();
            $table->string('browser_info', 500)->nullable();
            $table->string('environment', 40)->nullable();
            $table->unsignedInteger('occurrence_count')->default(1);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->string('status', 40)->default('open');
            $table->unsignedBigInteger('resolved_by_user_id')->nullable();
            $table->string('resolved_by_name')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->text('staff_note')->nullable();
            $table->json('properties')->nullable();
            $table->timestamps();

            $table->index(['fingerprint', 'status'], 'syserr_fp_status_idx');
            $table->index(['status', 'last_seen_at'], 'syserr_status_seen_idx');
            $table->index(['source', 'last_seen_at'], 'syserr_source_seen_idx');
        });
    }

    /**
     * Ingest a client-side (or API) error payload. Never throws to the caller for
     * duplicate races — returns the stored / updated row.
     *
     * @param  array<string, mixed>  $payload
     */
    public function ingest(Hub $hub, ?User $user, array $payload, ?Request $request = null): ?SystemErrorReport
    {
        if (! $hub->hasSupportTicketsFunctionality()) {
            return null;
        }

        $this->ensureSchema();

        $source = ($payload['source'] ?? SystemErrorReport::SOURCE_FRONTEND) === SystemErrorReport::SOURCE_BACKEND
            ? SystemErrorReport::SOURCE_BACKEND
            : SystemErrorReport::SOURCE_FRONTEND;

        $message = $this->truncate((string) ($payload['message'] ?? 'Unknown error'), 1000);
        if ($message === '') {
            $message = 'Unknown error';
        }

        $exceptionClass = $this->truncate(
            (string) ($payload['exception_class'] ?? $payload['name'] ?? ''),
            255
        ) ?: null;

        $stack = $this->truncate((string) ($payload['stack_trace'] ?? $payload['stack'] ?? ''), 8000) ?: null;
        $pageUrl = $this->truncate((string) ($payload['page_url'] ?? $payload['url'] ?? ''), 500) ?: null;
        $route = $this->truncate((string) ($payload['route'] ?? ''), 255) ?: null;
        $httpMethod = $this->truncate((string) ($payload['http_method'] ?? ''), 20) ?: null;
        $level = $this->truncate((string) ($payload['level'] ?? 'error'), 20) ?: 'error';
        $browserInfo = $this->truncate(
            (string) ($payload['browser_info'] ?? $request?->userAgent() ?? ''),
            500
        ) ?: null;

        $fingerprint = $this->fingerprint($source, $message, $exceptionClass, $stack, $pageUrl, $route);

        if (! $this->allowIngest($hub, $user, $fingerprint)) {
            return null;
        }

        $now = Carbon::now();
        $existing = SystemErrorReport::query()
            ->where('fingerprint', $fingerprint)
            ->whereIn('status', SystemErrorReport::ACTIVE_STATUSES)
            ->orderByDesc('id')
            ->first();

        if ($existing) {
            $existing->forceFill([
                'occurrence_count' => (int) $existing->occurrence_count + 1,
                'last_seen_at' => $now,
                'message' => $message,
                'stack_trace' => $stack ?: $existing->stack_trace,
                'page_url' => $pageUrl ?: $existing->page_url,
                'route' => $route ?: $existing->route,
                'http_method' => $httpMethod ?: $existing->http_method,
                'browser_info' => $browserInfo ?: $existing->browser_info,
                'user_id' => $user?->id ?? $existing->user_id,
                'user_name' => $user?->name ?? $existing->user_name,
                'user_email' => $user?->email ?? $existing->user_email,
            ])->save();

            return $existing->fresh();
        }

        $properties = is_array($payload['properties'] ?? null) ? $payload['properties'] : [];
        if ($request) {
            $properties['path'] = '/'.ltrim($request->path(), '/');
            $properties['ip'] = $request->ip();
        }

        return SystemErrorReport::query()->create([
            'hub_id' => $hub->id,
            'fingerprint' => $fingerprint,
            'source' => $source,
            'level' => $level,
            'message' => $message,
            'exception_class' => $exceptionClass,
            'stack_trace' => $stack,
            'page_url' => $pageUrl,
            'route' => $route,
            'http_method' => $httpMethod,
            'user_id' => $user?->id,
            'user_name' => $user?->name,
            'user_email' => $user?->email,
            'browser_info' => $browserInfo,
            'environment' => (string) config('app.env'),
            'occurrence_count' => 1,
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'status' => SystemErrorReport::STATUS_OPEN,
            'properties' => $properties ?: null,
        ]);
    }

    /**
     * Record a backend throwable into the system error feed (best-effort).
     */
    public function recordThrowable(?Hub $hub, Throwable $e, ?Request $request = null): void
    {
        try {
            if (! $hub) {
                try {
                    $hub = $this->hubs->current();
                } catch (Throwable) {
                    return;
                }
            }

            if (! $hub || ! $hub->hasSupportTicketsFunctionality()) {
                return;
            }

            if ($this->shouldIgnoreThrowable($e, $request)) {
                return;
            }

            $user = $request?->user();
            $user = $user instanceof User ? $user : null;

            $this->ingest($hub, $user, [
                'source' => SystemErrorReport::SOURCE_BACKEND,
                'level' => 'error',
                'message' => $e->getMessage() !== '' ? $e->getMessage() : $e::class,
                'exception_class' => $e::class,
                'stack_trace' => $this->truncate($e->getTraceAsString(), 8000),
                'page_url' => $request ? $request->fullUrl() : null,
                'route' => $request?->route()?->getName() ?: ($request ? '/'.ltrim($request->path(), '/') : null),
                'http_method' => $request?->method(),
                'browser_info' => $request?->userAgent(),
                'properties' => [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ],
            ], $request);
        } catch (Throwable) {
            // Never break exception handling.
        }
    }

    /**
     * @param  array{status?: string, source?: string, q?: string, per_page?: int}  $filters
     */
    public function list(Hub $hub, array $filters = []): LengthAwarePaginator
    {
        $this->assertModuleEnabled($hub);
        $this->ensureSchema();

        $q = SystemErrorReport::query()->orderByDesc('last_seen_at')->orderByDesc('id');

        if ($hub->id) {
            $q->where(function ($inner) use ($hub) {
                $inner->where('hub_id', $hub->id)->orWhereNull('hub_id');
            });
        }

        $status = trim((string) ($filters['status'] ?? ''));
        if ($status === '' || $status === 'active') {
            $q->whereIn('status', SystemErrorReport::ACTIVE_STATUSES);
        } elseif ($status !== 'all' && in_array($status, SystemErrorReport::STATUSES, true)) {
            $q->where('status', $status);
        }

        $source = trim((string) ($filters['source'] ?? ''));
        if (in_array($source, [SystemErrorReport::SOURCE_FRONTEND, SystemErrorReport::SOURCE_BACKEND], true)) {
            $q->where('source', $source);
        }

        $search = trim((string) ($filters['q'] ?? ''));
        if ($search !== '') {
            $like = '%'.$search.'%';
            $q->where(function ($inner) use ($like) {
                $inner->where('message', 'like', $like)
                    ->orWhere('exception_class', 'like', $like)
                    ->orWhere('page_url', 'like', $like)
                    ->orWhere('route', 'like', $like);
            });
        }

        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 50)));

        return $q->paginate($perPage);
    }

    public function show(Hub $hub, SystemErrorReport $report): SystemErrorReport
    {
        $this->assertModuleEnabled($hub);
        $this->ensureSchema();

        return $report;
    }

    /**
     * @param  array{status: string, staff_note?: ?string}  $data
     */
    public function updateStatus(Hub $hub, User $actor, SystemErrorReport $report, array $data): SystemErrorReport
    {
        $this->assertModuleEnabled($hub);
        $this->ensureSchema();

        $status = (string) ($data['status'] ?? '');
        if (! in_array($status, SystemErrorReport::STATUSES, true)) {
            throw ValidationException::withMessages([
                'status' => 'Invalid status.',
            ]);
        }

        $note = isset($data['staff_note'])
            ? $this->truncate((string) $data['staff_note'], 2000)
            : $report->staff_note;

        $closing = in_array($status, [
            SystemErrorReport::STATUS_RESOLVED,
            SystemErrorReport::STATUS_IGNORED,
        ], true);

        $report->forceFill([
            'status' => $status,
            'staff_note' => $note !== '' ? $note : null,
            'resolved_by_user_id' => $closing ? $actor->id : null,
            'resolved_by_name' => $closing ? $actor->name : null,
            'resolved_at' => $closing ? Carbon::now() : null,
        ])->save();

        try {
            $this->activityLogs->log([
                'hub' => $hub,
                'user' => $actor,
                'action' => 'st.system_errors.update_status',
                'description' => "System error #{$report->id} → {$status}",
                'subject' => $report,
                'properties' => [
                    'status' => $status,
                ],
            ]);
        } catch (Throwable) {
            // non-fatal
        }

        return $report->fresh();
    }

    public function openCount(Hub $hub): int
    {
        if (! $hub->hasSupportTicketsFunctionality()) {
            return 0;
        }

        try {
            $this->ensureSchema();

            return (int) SystemErrorReport::query()
                ->whereIn('status', SystemErrorReport::ACTIVE_STATUSES)
                ->where(function ($inner) use ($hub) {
                    $inner->where('hub_id', $hub->id)->orWhereNull('hub_id');
                })
                ->count();
        } catch (Throwable) {
            return 0;
        }
    }

    private function shouldIgnoreThrowable(Throwable $e, ?Request $request): bool
    {
        if ($e instanceof \Illuminate\Validation\ValidationException) {
            return true;
        }
        if ($e instanceof \Illuminate\Auth\AuthenticationException) {
            return true;
        }
        if ($e instanceof \Illuminate\Auth\Access\AuthorizationException) {
            return true;
        }
        if ($e instanceof \Illuminate\Http\Exceptions\ThrottleRequestsException) {
            return true;
        }
        if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
            $code = $e->getStatusCode();
            if ($code >= 400 && $code < 500) {
                return true;
            }
        }

        $path = (string) ($request?->path() ?? '');
        if (str_contains($path, 'system-errors')) {
            return true;
        }

        return false;
    }

    private function allowIngest(Hub $hub, ?User $user, string $fingerprint): bool
    {
        $userKey = $user?->id ? 'u'.$user->id : 'anon';
        $fpKey = 'syserr:fp:'.$hub->id.':'.$fingerprint.':'.$userKey;
        if (! Cache::add($fpKey, 1, now()->addSeconds(20))) {
            // Same fingerprint from same actor within 20s — skip noise.
            return false;
        }

        $hourKey = 'syserr:hour:'.$hub->id.':'.$userKey;
        $count = (int) Cache::get($hourKey, 0);
        if ($count >= 40) {
            return false;
        }
        Cache::put($hourKey, $count + 1, now()->addHour());

        return true;
    }

    private function fingerprint(
        string $source,
        string $message,
        ?string $exceptionClass,
        ?string $stack,
        ?string $pageUrl,
        ?string $route
    ): string {
        $normalizedMessage = preg_replace('/\d+/', 'N', strtolower(trim($message))) ?? $message;
        $normalizedMessage = preg_replace('/\s+/', ' ', $normalizedMessage) ?? $normalizedMessage;

        $locus = '';
        if ($stack) {
            if (preg_match('/(?:at\s+)?([^\s(]+\.(?:jsx?|tsx?|php)):(\d+)/i', $stack, $m)) {
                $locus = $m[1].':'.$m[2];
            } elseif (preg_match('/#0\s+([^\s(]+)\((\d+)\)/', $stack, $m)) {
                $locus = $m[1].':'.$m[2];
            }
        }

        $page = $pageUrl ?: $route ?: '';
        $page = preg_replace('#https?://[^/]+#', '', $page) ?? $page;
        $page = preg_replace('#/\d+#', '/N', $page) ?? $page;

        return hash('sha256', implode('|', [
            $source,
            $exceptionClass ?: '',
            substr($normalizedMessage, 0, 240),
            $locus,
            substr($page, 0, 160),
        ]));
    }

    private function truncate(string $value, int $max): string
    {
        $value = trim($value);
        if (mb_strlen($value) <= $max) {
            return $value;
        }

        return mb_substr($value, 0, $max - 1).'…';
    }
}
