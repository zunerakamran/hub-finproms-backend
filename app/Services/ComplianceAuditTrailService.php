<?php

namespace App\Services;

use App\Models\ComplianceAuditEvent;
use App\Models\Hub;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class ComplianceAuditTrailService
{
    public function __construct(
        private readonly HubService $hubs
    ) {}

    /**
     * Record one immutable audit-trail entry for a compliance request.
     *
     * @param  array{
     *   module: string,
     *   subject: Model,
     *   event_type: string,
     *   actor?: ?User,
     *   hub?: ?Hub,
     *   description?: ?string,
     *   from_status?: ?string,
     *   to_status?: ?string,
     *   version_number?: int|null,
     *   related_user?: ?User,
     *   metadata?: array<string, mixed>|null
     * }  $data
     */
    public function record(array $data): ?ComplianceAuditEvent
    {
        try {
            $subject = $data['subject'];
            $actor = $data['actor'] ?? null;
            $related = $data['related_user'] ?? null;
            $hub = $data['hub'] ?? null;

            if (! $hub) {
                try {
                    $hub = $this->hubs->current();
                } catch (\Throwable) {
                    $hub = null;
                }
            }

            return ComplianceAuditEvent::query()->create([
                'hub_id' => $hub?->id,
                'module' => (string) $data['module'],
                'subject_type' => $subject::class,
                'subject_id' => (int) $subject->id,
                'event_type' => (string) $data['event_type'],
                'description' => $data['description'] ?? null,
                'from_status' => $data['from_status'] ?? null,
                'to_status' => $data['to_status'] ?? null,
                'version_number' => isset($data['version_number']) ? (int) $data['version_number'] : null,
                'actor_user_id' => $actor?->id,
                'actor_name' => $actor?->name,
                'actor_email' => $actor?->email,
                'actor_role' => $actor?->role,
                'related_user_id' => $related?->id,
                'related_user_name' => $related?->name,
                'related_user_email' => $related?->email,
                'related_user_role' => $related?->role,
                'metadata' => $data['metadata'] ?? null,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Chronological audit trail for one request (oldest first).
     *
     * @return list<array<string, mixed>>
     */
    public function forSubject(string $module, int $subjectId): array
    {
        return ComplianceAuditEvent::query()
            ->where('module', $module)
            ->where('subject_id', $subjectId)
            ->orderBy('id')
            ->get()
            ->map(fn (ComplianceAuditEvent $event) => $event->toApiArray())
            ->all();
    }

    /**
     * Batch-load audit trails keyed by subject id (oldest first within each).
     *
     * @param  list<int>  $subjectIds
     * @return array<int, list<array<string, mixed>>>
     */
    public function forSubjects(string $module, array $subjectIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $subjectIds)));
        if ($ids === []) {
            return [];
        }

        /** @var Collection<int, ComplianceAuditEvent> $events */
        $events = ComplianceAuditEvent::query()
            ->where('module', $module)
            ->whereIn('subject_id', $ids)
            ->orderBy('id')
            ->get();

        $grouped = [];
        foreach ($ids as $id) {
            $grouped[$id] = [];
        }

        foreach ($events as $event) {
            $grouped[(int) $event->subject_id][] = $event->toApiArray();
        }

        return $grouped;
    }

    /**
     * Hub-scoped audit events for reports (oldest first).
     *
     * Content-hub databases are single-tenant (including when Central remounts
     * them via acting-hub). Do not require hub_id === Central registry id —
     * local hubs.id on the content DB often differs from the registry row.
     *
     * @param  list<int>  $subjectIds
     * @return list<array<string, mixed>>
     */
    public function forHub(string $module, Hub $hub, array $subjectIds = []): array
    {
        $ids = array_values(array_unique(array_map('intval', $subjectIds)));

        $query = $this->hubQuery($module, $hub, $ids)->orderBy('id');

        return $query
            ->get()
            ->map(fn (ComplianceAuditEvent $event) => $event->toApiArray())
            ->all();
    }

    /**
     * Paginated hub-scoped audit events (newest first) for the unified Audit trail page.
     *
     * @param  array{page?: int, per_page?: int, q?: string|null, event_type?: string|null, from?: string|null, to?: string|null}  $filters
     * @return array{events: list<array<string, mixed>>, meta: array{current_page: int, last_page: int, per_page: int, total: int}}
     */
    public function paginateForHub(string $module, Hub $hub, array $filters = []): array
    {
        $perPage = max(1, min(200, (int) ($filters['per_page'] ?? 50)));
        $page = max(1, (int) ($filters['page'] ?? 1));

        $query = $this->hubQuery($module, $hub)->orderByDesc('id');

        if (! empty($filters['event_type'])) {
            $query->where('event_type', (string) $filters['event_type']);
        }

        if (! empty($filters['q'])) {
            $term = '%'.(string) $filters['q'].'%';
            $query->where(function ($inner) use ($term) {
                $inner->where('description', 'like', $term)
                    ->orWhere('actor_name', 'like', $term)
                    ->orWhere('actor_email', 'like', $term)
                    ->orWhere('related_user_name', 'like', $term)
                    ->orWhere('related_user_email', 'like', $term)
                    ->orWhere('event_type', 'like', $term)
                    ->orWhere('from_status', 'like', $term)
                    ->orWhere('to_status', 'like', $term);
            });
        }

        if (! empty($filters['from'])) {
            $query->whereDate('created_at', '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->whereDate('created_at', '<=', $filters['to']);
        }

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        return [
            'events' => $paginator->getCollection()
                ->map(fn (ComplianceAuditEvent $event) => $event->toApiArray())
                ->values()
                ->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    /**
     * @param  list<int>  $subjectIds
     * @return \Illuminate\Database\Eloquent\Builder<\App\Models\ComplianceAuditEvent>
     */
    private function hubQuery(string $module, Hub $hub, array $subjectIds = [])
    {
        $ids = array_values(array_unique(array_map('intval', $subjectIds)));

        $query = ComplianceAuditEvent::query()->where('module', $module);

        // Content hub (local deploy or remounted from Central): all module events
        // on this connection belong to the acting content hub.
        if ($hub->isContentHub()) {
            return $query;
        }

        // Control-plane / Central local DB: filter by registry hub id + subjects.
        return $query->where(function ($inner) use ($hub, $ids) {
            $inner->where('hub_id', $hub->id);
            if ($ids !== []) {
                $inner->orWhereIn('subject_id', $ids);
            }
        });
    }

    /**
     * Human-readable one-line summary of an audit trail (for CSV export).
     *
     * @param  list<array<string, mixed>>  $trail
     */
    public function summarizeForExport(array $trail): string
    {
        if ($trail === []) {
            return '';
        }

        $parts = [];
        foreach ($trail as $event) {
            $when = $event['created_at'] ?? '';
            $actor = $event['actor']['name'] ?? 'Unknown';
            $role = $event['actor']['role'] ?? '';
            $email = $event['actor']['email'] ?? '';
            $who = trim($actor.($role !== '' ? " ({$role})" : '').($email !== '' ? " <{$email}>" : ''));
            $desc = $event['description'] ?? ($event['event_type'] ?? 'event');
            $statusBit = '';
            if (! empty($event['from_status']) || ! empty($event['to_status'])) {
                $statusBit = ' ['.($event['from_status'] ?? '—').' → '.($event['to_status'] ?? '—').']';
            }
            $parts[] = trim("{$when}: {$who} — {$desc}{$statusBit}");
        }

        return implode(' | ', $parts);
    }
}
