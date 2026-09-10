<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Hub;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ActivityLogService
{
    /**
     * Request body keys that must never be stored in activity logs.
     *
     * @var list<string>
     */
    private const SENSITIVE_KEYS = [
        'password',
        'password_confirmation',
        'current_password',
        'token',
        'secret',
        'stripe_secret',
        'stripe_webhook_secret',
        'card_number',
        'cvc',
        'cvv',
    ];

    public function __construct(
        private readonly HubService $hubs
    ) {}

    /**
     * @param  array{
     *   action: string,
     *   description?: ?string,
     *   user?: ?User,
     *   hub?: ?Hub,
     *   subject?: ?object,
     *   subject_type?: ?string,
     *   subject_id?: int|string|null,
     *   method?: ?string,
     *   path?: ?string,
     *   route_name?: ?string,
     *   ip_address?: ?string,
     *   user_agent?: ?string,
     *   status_code?: int|null,
     *   properties?: array<string, mixed>|null,
     *   request?: ?Request
     * }  $data
     */
    public function log(array $data): ActivityLog
    {
        $request = $data['request'] ?? null;
        $user = $data['user'] ?? ($request?->user() instanceof User ? $request->user() : null);
        $hub = $data['hub'] ?? null;

        if (! $hub) {
            try {
                $hub = $this->hubs->current();
            } catch (\Throwable) {
                $hub = null;
            }
        }

        $subject = $data['subject'] ?? null;
        $subjectType = $data['subject_type'] ?? null;
        $subjectId = $data['subject_id'] ?? null;

        if ($subject !== null && is_object($subject)) {
            $subjectType = $subjectType ?: $subject::class;
            $subjectId = $subjectId ?? ($subject->id ?? null);
        }

        $properties = $data['properties'] ?? null;
        if ($request instanceof Request) {
            $requestMeta = $this->requestMeta($request);
            $properties = array_merge($requestMeta, is_array($properties) ? $properties : []);
        }

        return ActivityLog::query()->create([
            'hub_id' => $hub?->id,
            'user_id' => $user?->id,
            'user_name' => $user?->name,
            'user_email' => $user?->email,
            'user_role' => $user?->role,
            'action' => (string) $data['action'],
            'description' => $data['description'] ?? null,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId !== null ? (int) $subjectId : null,
            'method' => $data['method'] ?? ($request?->method()),
            'path' => $data['path'] ?? ($request ? '/'.ltrim($request->path(), '/') : null),
            'route_name' => $data['route_name'] ?? $request?->route()?->getName(),
            'ip_address' => $data['ip_address'] ?? $request?->ip(),
            'user_agent' => $data['user_agent'] ?? $request?->userAgent(),
            'status_code' => $data['status_code'] ?? null,
            'properties' => $properties ?: null,
            'created_at' => now(),
        ]);
    }

    /**
     * Record a successful mutating API request for the authenticated (or guest) actor.
     */
    public function logApiRequest(Request $request, int $statusCode): ?ActivityLog
    {
        $method = strtoupper($request->method());
        if (! in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return null;
        }

        // Avoid recursive noise when reading/reporting logs.
        $path = '/'.ltrim($request->path(), '/');
        if (str_contains($path, 'activity-logs')) {
            return null;
        }

        // Auth events are logged explicitly in AuthController (richer actions).
        if (str_contains($path, 'auth/')) {
            return null;
        }

        // Stripe webhooks are system events, not end-user activity.
        if (str_contains($path, 'stripe/webhook')) {
            return null;
        }

        $action = $this->actionFromRequest($request);

        return $this->log([
            'action' => $action,
            'description' => $this->descriptionFromRequest($request, $statusCode),
            'request' => $request,
            'status_code' => $statusCode,
            'properties' => [
                'http_method' => $method,
            ],
        ]);
    }

    /**
     * Paginated activity log list for a hub.
     *
     * @param  array{
     *   user_id?: int|null,
     *   action?: string|null,
     *   q?: string|null,
     *   from?: string|null,
     *   to?: string|null,
     *   per_page?: int|null
     * }  $filters
     */
    public function list(Hub $hub, array $filters = []): LengthAwarePaginator
    {
        $query = $this->filteredQuery($hub, $filters)->orderByDesc('id');

        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 25)));

        return $query->paginate($perPage);
    }

    /**
     * Aggregated activity report for a hub (who did what, how often).
     *
     * @param  array{
     *   user_id?: int|null,
     *   action?: string|null,
     *   q?: string|null,
     *   from?: string|null,
     *   to?: string|null
     * }  $filters
     * @return array{
     *   summary: array{total: int, unique_users: int, unique_actions: int},
     *   by_action: list<array{action: string, count: int}>,
     *   by_user: list<array{user_id: ?int, user_name: ?string, user_email: ?string, user_role: ?string, count: int}>,
     *   by_day: list<array{date: string, count: int}>,
     *   filters: array<string, mixed>
     * }
     */
    public function report(Hub $hub, array $filters = []): array
    {
        $base = $this->filteredQuery($hub, $filters);

        $total = (clone $base)->count();
        $uniqueUsers = (clone $base)->whereNotNull('user_id')->distinct('user_id')->count('user_id');
        $uniqueActions = (clone $base)->distinct('action')->count('action');

        $byAction = (clone $base)
            ->selectRaw('action, COUNT(*) as count')
            ->groupBy('action')
            ->orderByDesc('count')
            ->limit(50)
            ->get()
            ->map(fn ($row) => [
                'action' => (string) $row->action,
                'count' => (int) $row->count,
            ])
            ->all();

        $byUser = (clone $base)
            ->selectRaw('user_id, user_name, user_email, user_role, COUNT(*) as count')
            ->groupBy('user_id', 'user_name', 'user_email', 'user_role')
            ->orderByDesc('count')
            ->limit(50)
            ->get()
            ->map(fn ($row) => [
                'user_id' => $row->user_id !== null ? (int) $row->user_id : null,
                'user_name' => $row->user_name,
                'user_email' => $row->user_email,
                'user_role' => $row->user_role,
                'count' => (int) $row->count,
            ])
            ->all();

        $byDay = (clone $base)
            ->selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->groupBy('date')
            ->orderBy('date')
            ->limit(90)
            ->get()
            ->map(fn ($row) => [
                'date' => (string) $row->date,
                'count' => (int) $row->count,
            ])
            ->all();

        return [
            'summary' => [
                'total' => $total,
                'unique_users' => $uniqueUsers,
                'unique_actions' => $uniqueActions,
            ],
            'by_action' => $byAction,
            'by_user' => $byUser,
            'by_day' => $byDay,
            'filters' => [
                'user_id' => $filters['user_id'] ?? null,
                'action' => $filters['action'] ?? null,
                'q' => $filters['q'] ?? null,
                'from' => $filters['from'] ?? null,
                'to' => $filters['to'] ?? null,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function filteredQuery(Hub $hub, array $filters): Builder
    {
        $query = ActivityLog::query()->where('hub_id', $hub->id);

        if (! empty($filters['user_id'])) {
            $query->where('user_id', (int) $filters['user_id']);
        }

        if (! empty($filters['action'])) {
            $query->where('action', (string) $filters['action']);
        }

        if (! empty($filters['q'])) {
            $q = '%'.trim((string) $filters['q']).'%';
            $query->where(function (Builder $inner) use ($q) {
                $inner->where('description', 'like', $q)
                    ->orWhere('user_name', 'like', $q)
                    ->orWhere('user_email', 'like', $q)
                    ->orWhere('path', 'like', $q)
                    ->orWhere('action', 'like', $q);
            });
        }

        if (! empty($filters['from'])) {
            $from = Carbon::parse((string) $filters['from'])->startOfDay();
            $query->where('created_at', '>=', $from);
        }

        if (! empty($filters['to'])) {
            $to = Carbon::parse((string) $filters['to'])->endOfDay();
            $query->where('created_at', '<=', $to);
        }

        return $query;
    }

    private function actionFromRequest(Request $request): string
    {
        $path = trim($request->path(), '/');
        // Strip leading "api/" if present.
        if (str_starts_with($path, 'api/')) {
            $path = substr($path, 4);
        }

        $segments = array_values(array_filter(explode('/', $path)));
        if ($segments === []) {
            return 'api.'.strtolower($request->method());
        }

        // Normalize numeric / UUID segments to {id}
        $normalized = array_map(function (string $segment) {
            if (ctype_digit($segment) || preg_match('/^[0-9a-fA-F-]{36}$/', $segment)) {
                return '{id}';
            }

            return $segment;
        }, $segments);

        $resource = implode('.', array_slice($normalized, 0, 3));
        $verb = match (strtoupper($request->method())) {
            'POST' => 'create_or_action',
            'PUT', 'PATCH' => 'update',
            'DELETE' => 'delete',
            default => strtolower($request->method()),
        };

        return $resource.'.'.$verb;
    }

    private function descriptionFromRequest(Request $request, int $statusCode): string
    {
        $user = $request->user();
        $who = $user instanceof User
            ? ($user->email ?: $user->name ?: 'user#'.$user->id)
            : 'guest';

        return sprintf(
            '%s %s %s → %d',
            $who,
            strtoupper($request->method()),
            '/'.ltrim($request->path(), '/'),
            $statusCode
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function requestMeta(Request $request): array
    {
        $input = $request->except(self::SENSITIVE_KEYS);
        $clean = $this->redactSensitive($input);

        // Keep payloads small.
        $encoded = json_encode($clean);
        if (is_string($encoded) && strlen($encoded) > 4000) {
            $clean = ['_truncated' => true, 'keys' => array_keys($clean)];
        }

        return [
            'input_keys' => array_keys($input),
            'input' => $clean,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function redactSensitive(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            $keyStr = (string) $key;
            if (in_array(strtolower($keyStr), self::SENSITIVE_KEYS, true)
                || str_contains(strtolower($keyStr), 'password')
                || str_contains(strtolower($keyStr), 'secret')
            ) {
                $out[$keyStr] = '[redacted]';
                continue;
            }
            if (is_array($value)) {
                $out[$keyStr] = $this->redactSensitive($value);
                continue;
            }
            if (is_object($value)) {
                continue;
            }
            $out[$keyStr] = $value;
        }

        return $out;
    }
}
