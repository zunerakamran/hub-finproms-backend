<?php

namespace App\Services;

use App\Models\Hub;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * CRUD firms on a white-labelled hub's own database while the shared
 * dashboard hub switcher is acting on that hub.
 */
class WhiteLabelFirmService
{
    public function __construct(
        private readonly WhiteLabelDatabaseService $remoteDb
    ) {}

    public function assertTarget(Hub $hub): void
    {
        if ($hub->isControlPlane() || ! $hub->isContentHub()) {
            throw new InvalidArgumentException('Select a Shared or White-labelled hub, not Central Hub.');
        }
        if (! $hub->is_active) {
            throw new InvalidArgumentException('That hub is inactive.');
        }
        $this->remoteDb->assertConfigured($hub);
    }

    /**
     * @return array{firms: list<array<string, mixed>>, central_firm_id: ?int}
     */
    public function list(Hub $hub): array
    {
        $this->assertTarget($hub);

        return $this->remoteDb->run($hub, function (string $connection) {
            $this->ensureCentral($connection);

            $counts = DB::connection($connection)->table('users')
                ->whereNotNull('firm_id')
                ->selectRaw('firm_id, COUNT(*) as users_count')
                ->groupBy('firm_id')
                ->pluck('users_count', 'firm_id');

            $rows = DB::connection($connection)->table('firms')
                ->orderByDesc('is_central')
                ->orderBy('name')
                ->get();

            $byId = $rows->keyBy('id');

            return [
                'firms' => $rows->map(function ($row) use ($counts, $byId, $connection) {
                    return $this->mapListRow($row, $counts, $byId, $connection);
                })->values()->all(),
                'central_firm_id' => DB::connection($connection)->table('firms')
                    ->where('is_central', true)
                    ->value('id'),
            ];
        });
    }

    /**
     * @return array{
     *   firms: list<array<string, mixed>>,
     *   firm_options: list<array{id: int, name: string, is_central: bool}>,
     *   central_firm_id: ?int,
     *   meta: array<string, int>
     * }
     */
    public function paginate(Hub $hub, ?string $search, int $perPage, int $page = 1): array
    {
        $this->assertTarget($hub);

        return $this->remoteDb->run($hub, function (string $connection) use ($search, $perPage, $page) {
            $this->ensureCentral($connection);

            $counts = DB::connection($connection)->table('users')
                ->whereNotNull('firm_id')
                ->selectRaw('firm_id, COUNT(*) as users_count')
                ->groupBy('firm_id')
                ->pluck('users_count', 'firm_id');

            $allRows = DB::connection($connection)->table('firms')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->get();
            $byId = $allRows->keyBy('id');

            $filtered = $allRows;
            if ($search !== null && trim($search) !== '') {
                $term = mb_strtolower(trim($search));
                $filtered = $allRows->filter(
                    fn ($row) => str_contains(mb_strtolower((string) $row->name), $term)
                )->values();
            }

            $total = $filtered->count();
            $perPage = max(1, min(100, $perPage));
            $lastPage = max(1, (int) ceil($total / $perPage));
            $page = max(1, min($page, $lastPage));
            $pageRows = $filtered->slice(($page - 1) * $perPage, $perPage)->values();

            return [
                'firms' => $pageRows->map(function ($row) use ($counts, $byId, $connection) {
                    return $this->mapListRow($row, $counts, $byId, $connection);
                })->all(),
                'firm_options' => $allRows->map(fn ($row) => [
                    'id' => (int) $row->id,
                    'name' => (string) $row->name,
                    'is_central' => (bool) $row->is_central,
                ])->values()->all(),
                'central_firm_id' => DB::connection($connection)->table('firms')
                    ->where('is_central', true)
                    ->value('id'),
                'meta' => [
                    'current_page' => $page,
                    'last_page' => $lastPage,
                    'per_page' => $perPage,
                    'total' => $total,
                ],
            ];
        });
    }

    /**
     * @param  \Illuminate\Support\Collection<int|string, mixed>  $counts
     * @param  \Illuminate\Support\Collection<int|string, mixed>  $byId
     * @return array<string, mixed>
     */
    private function mapListRow(object $row, $counts, $byId, ?string $connection = null): array
    {
        $other = $row->compliance_visible_to_firm_id
            ? $byId->get($row->compliance_visible_to_firm_id)
            : null;

        $headUser = null;
        $headUserId = isset($row->head_user_id) && $row->head_user_id
            ? (int) $row->head_user_id
            : null;
        if ($headUserId && $connection) {
            $head = DB::connection($connection)->table('users')
                ->where('id', $headUserId)
                ->first(['id', 'name', 'email']);
            if ($head) {
                $headUser = [
                    'id' => (int) $head->id,
                    'name' => (string) $head->name,
                    'email' => (string) $head->email,
                ];
            }
        }

        return [
            'id' => (int) $row->id,
            'name' => (string) $row->name,
            'is_central' => (bool) $row->is_central,
            'users_count' => (int) ($counts[$row->id] ?? 0),
            'head_user_id' => $headUserId,
            'head_user' => $headUser,
            'compliance_visibility' => [
                'visible_to_own' => (bool) ($row->compliance_visible_to_own ?? true),
                'visible_to_central' => (bool) ($row->compliance_visible_to_central ?? false),
                'visible_to_firm_id' => $row->compliance_visible_to_firm_id
                    ? (int) $row->compliance_visible_to_firm_id
                    : null,
                'visible_to_firm' => $other
                    ? ['id' => (int) $other->id, 'name' => (string) $other->name]
                    : null,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function create(Hub $hub, array $payload): array
    {
        $this->assertTarget($hub);

        return $this->remoteDb->run($hub, function (string $connection) use ($payload) {
            $this->ensureCentral($connection);

            $name = trim((string) $payload['name']);
            if (DB::connection($connection)->table('firms')->where('name', $name)->exists()) {
                throw new InvalidArgumentException('A firm with that name already exists on this hub.');
            }

            $otherId = $payload['compliance_visible_to_firm_id'] ?? null;
            if ($otherId !== null && $otherId !== '') {
                $otherId = (int) $otherId;
                if (! DB::connection($connection)->table('firms')->where('id', $otherId)->exists()) {
                    throw new InvalidArgumentException('The selected visibility firm is invalid on this hub.');
                }
            } else {
                $otherId = null;
            }

            $now = now();
            $id = (int) DB::connection($connection)->table('firms')->insertGetId([
                'name' => $name,
                'is_central' => false,
                'compliance_visible_to_own' => (bool) ($payload['compliance_visible_to_own'] ?? true),
                'compliance_visible_to_central' => (bool) ($payload['compliance_visible_to_central'] ?? false),
                'compliance_visible_to_firm_id' => $otherId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return $this->serializeRow($connection, $id);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function update(Hub $hub, int $firmId, array $payload): array
    {
        $this->assertTarget($hub);

        return $this->remoteDb->run($hub, function (string $connection) use ($firmId, $payload) {
            $row = DB::connection($connection)->table('firms')->where('id', $firmId)->first();
            if (! $row) {
                throw new InvalidArgumentException('Firm not found on this white-labelled hub.');
            }

            $name = trim((string) $payload['name']);
            $dup = DB::connection($connection)->table('firms')
                ->where('name', $name)
                ->where('id', '!=', $firmId)
                ->exists();
            if ($dup) {
                throw new InvalidArgumentException('A firm with that name already exists on this hub.');
            }

            $update = [
                'name' => $name,
                'updated_at' => now(),
            ];

            if (array_key_exists('compliance_visible_to_own', $payload)) {
                $update['compliance_visible_to_own'] = (bool) $payload['compliance_visible_to_own'];
            }
            if (array_key_exists('compliance_visible_to_central', $payload)) {
                $update['compliance_visible_to_central'] = (bool) $payload['compliance_visible_to_central'];
            }
            if (array_key_exists('compliance_visible_to_firm_id', $payload)) {
                $otherId = $payload['compliance_visible_to_firm_id'];
                if ($otherId === null || $otherId === '') {
                    $update['compliance_visible_to_firm_id'] = null;
                } else {
                    $otherId = (int) $otherId;
                    if ($otherId === $firmId) {
                        throw new InvalidArgumentException(
                            'A firm cannot select itself as the other visibility firm. Use “own firm” instead.'
                        );
                    }
                    if (! DB::connection($connection)->table('firms')->where('id', $otherId)->exists()) {
                        throw new InvalidArgumentException('The selected visibility firm is invalid on this hub.');
                    }
                    $update['compliance_visible_to_firm_id'] = $otherId;
                }
            }

            DB::connection($connection)->table('firms')->where('id', $firmId)->update($update);

            return $this->serializeRow($connection, $firmId);
        });
    }

    public function delete(Hub $hub, int $firmId): void
    {
        $this->assertTarget($hub);

        $this->remoteDb->run($hub, function (string $connection) use ($firmId): void {
            $row = DB::connection($connection)->table('firms')->where('id', $firmId)->first();
            if (! $row) {
                throw new InvalidArgumentException('Firm not found on this white-labelled hub.');
            }

            if ((bool) ($row->is_central ?? false)) {
                throw new InvalidArgumentException(
                    'The Central / Network firm cannot be deleted. You can rename it instead.'
                );
            }

            $inUse = DB::connection($connection)->table('users')->where('firm_id', $firmId)->exists();
            if ($inUse) {
                throw new InvalidArgumentException('Cannot delete a firm that is assigned to users.');
            }

            DB::connection($connection)->table('firms')->where('id', $firmId)->delete();
        });
    }

    /**
     * Eligible Head of Firm candidates (active members of the firm on the remote hub).
     *
     * @return array{firm: array<string, mixed>, members: list<array{id: int, name: string, email: string}>}
     */
    public function members(Hub $hub, int $firmId): array
    {
        $this->assertTarget($hub);

        return $this->remoteDb->run($hub, function (string $connection) use ($firmId) {
            $row = DB::connection($connection)->table('firms')->where('id', $firmId)->first();
            if (! $row) {
                throw new InvalidArgumentException('Firm not found on this hub.');
            }

            $query = DB::connection($connection)->table('users')
                ->where('firm_id', $firmId)
                ->orderBy('name');

            if (DB::connection($connection)->getSchemaBuilder()->hasColumn('users', 'is_discontinued')) {
                $query->where(function ($q) {
                    $q->where('is_discontinued', false)->orWhereNull('is_discontinued');
                });
            }

            $members = $query->get(['id', 'name', 'email'])
                ->map(fn ($u) => [
                    'id' => (int) $u->id,
                    'name' => (string) $u->name,
                    'email' => (string) $u->email,
                ])
                ->values()
                ->all();

            return [
                'firm' => $this->serializeRow($connection, $firmId),
                'members' => $members,
            ];
        });
    }

    /**
     * Appoint / replace / clear Head of Firm on the remote hub DB.
     *
     * @return array<string, mixed>
     */
    public function assignHead(Hub $hub, int $firmId, ?int $headUserId): array
    {
        $this->assertTarget($hub);

        return $this->remoteDb->run($hub, function (string $connection) use ($firmId, $headUserId) {
            if (! DB::connection($connection)->getSchemaBuilder()->hasColumn('firms', 'head_user_id')) {
                throw new InvalidArgumentException(
                    'This hub has not been migrated for Head of Firm yet. Run migrations on that hub’s database.'
                );
            }

            $row = DB::connection($connection)->table('firms')->where('id', $firmId)->first();
            if (! $row) {
                throw new InvalidArgumentException('Firm not found on this hub.');
            }

            if ($headUserId === null) {
                DB::connection($connection)->table('firms')->where('id', $firmId)->update([
                    'head_user_id' => null,
                    'updated_at' => now(),
                ]);

                return $this->serializeRow($connection, $firmId);
            }

            $user = DB::connection($connection)->table('users')->where('id', $headUserId)->first();
            if (! $user) {
                throw new InvalidArgumentException('The selected user was not found on this hub.');
            }

            if ((int) ($user->firm_id ?? 0) !== (int) $firmId) {
                throw new InvalidArgumentException('The Head of Firm must be a member of this firm.');
            }

            if (DB::connection($connection)->getSchemaBuilder()->hasColumn('users', 'is_discontinued')
                && (bool) ($user->is_discontinued ?? false)
            ) {
                throw new InvalidArgumentException('A discontinued user cannot be Head of Firm.');
            }

            DB::connection($connection)->table('firms')->where('id', $firmId)->update([
                'head_user_id' => $headUserId,
                'updated_at' => now(),
            ]);

            return $this->serializeRow($connection, $firmId);
        });
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    public function options(Hub $hub): array
    {
        $listed = $this->list($hub);

        return array_map(
            fn (array $firm) => [
                'id' => $firm['id'],
                'name' => $firm['name'],
                'is_central' => (bool) ($firm['is_central'] ?? false),
            ],
            $listed['firms']
        );
    }

    private function ensureCentral(string $connection): void
    {
        if (! DB::connection($connection)->getSchemaBuilder()->hasTable('firms')) {
            return;
        }

        if (DB::connection($connection)->table('firms')->where('is_central', true)->exists()) {
            return;
        }

        $now = now();
        DB::connection($connection)->table('firms')->insert([
            'name' => 'Central / Network',
            'is_central' => true,
            'compliance_visible_to_own' => true,
            'compliance_visible_to_central' => true,
            'compliance_visible_to_firm_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeRow(string $connection, int $firmId): array
    {
        $row = DB::connection($connection)->table('firms')->where('id', $firmId)->first();
        $other = $row->compliance_visible_to_firm_id
            ? DB::connection($connection)->table('firms')->where('id', $row->compliance_visible_to_firm_id)->first()
            : null;
        $usersCount = (int) DB::connection($connection)->table('users')->where('firm_id', $firmId)->count();

        $headUser = null;
        if (isset($row->head_user_id) && $row->head_user_id) {
            $head = DB::connection($connection)->table('users')
                ->where('id', $row->head_user_id)
                ->first(['id', 'name', 'email']);
            if ($head) {
                $headUser = [
                    'id' => (int) $head->id,
                    'name' => (string) $head->name,
                    'email' => (string) $head->email,
                ];
            }
        }

        return [
            'id' => (int) $row->id,
            'name' => (string) $row->name,
            'is_central' => (bool) $row->is_central,
            'users_count' => $usersCount,
            'head_user_id' => isset($row->head_user_id) && $row->head_user_id
                ? (int) $row->head_user_id
                : null,
            'head_user' => $headUser,
            'compliance_visibility' => [
                'visible_to_own' => (bool) ($row->compliance_visible_to_own ?? true),
                'visible_to_central' => (bool) ($row->compliance_visible_to_central ?? false),
                'visible_to_firm_id' => $row->compliance_visible_to_firm_id
                    ? (int) $row->compliance_visible_to_firm_id
                    : null,
                'visible_to_firm' => $other
                    ? ['id' => (int) $other->id, 'name' => (string) $other->name]
                    : null,
            ],
        ];
    }
}
