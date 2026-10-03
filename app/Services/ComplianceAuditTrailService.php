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
     * Prefer hub_id matches; also include events for the given subject ids
     * (covers backfills / remote DBs where hub_id may be null).
     *
     * @param  list<int>  $subjectIds
     * @return list<array<string, mixed>>
     */
    public function forHub(string $module, Hub $hub, array $subjectIds = []): array
    {
        $ids = array_values(array_unique(array_map('intval', $subjectIds)));

        $events = ComplianceAuditEvent::query()
            ->where('module', $module)
            ->where(function ($query) use ($hub, $ids) {
                $query->where('hub_id', $hub->id);
                if ($ids !== []) {
                    $query->orWhereIn('subject_id', $ids);
                }
            })
            ->orderBy('id')
            ->get()
            ->map(fn (ComplianceAuditEvent $event) => $event->toApiArray())
            ->all();

        return $events;
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
