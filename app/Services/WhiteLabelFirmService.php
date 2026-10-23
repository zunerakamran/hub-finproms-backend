<?php

namespace App\Services;

use App\Models\Firm;
use App\Models\Hub;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * CRUD firms on a white-labelled hub's own database while the shared
 * dashboard hub switcher is acting on that hub.
 */
class WhiteLabelFirmService
{
    public function __construct(
        private readonly WhiteLabelDatabaseService $remoteDb,
        private readonly FirmHeadRoleSyncService $headRoles,
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
            'compliance_visibility' => $this->complianceVisibilityPayload(
                $connection,
                $row,
                $byId
            ),
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

            $authorityIds = $this->resolveRemoteAuthorityFirmIds($connection, $payload, null);

            $now = now();
            $id = (int) DB::connection($connection)->table('firms')->insertGetId([
                'name' => $name,
                'is_central' => false,
                'compliance_visible_to_own' => (bool) ($payload['compliance_visible_to_own'] ?? true),
                'compliance_visible_to_central' => false,
                'compliance_visible_to_firm_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->syncRemoteComplianceAuthorities($connection, $id, $authorityIds ?? []);

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

            $authorityIds = $this->resolveRemoteAuthorityFirmIds($connection, $payload, $row);
            if ($authorityIds !== null) {
                foreach ($authorityIds as $authorityId) {
                    if ((int) $authorityId === $firmId) {
                        throw new InvalidArgumentException(
                            'A firm cannot select itself under Delegated compliance access. Use “Within the Firm” instead.'
                        );
                    }
                    if (! DB::connection($connection)->table('firms')->where('id', $authorityId)->exists()) {
                        throw new InvalidArgumentException('The selected authority firm is invalid on this hub.');
                    }
                }
            }

            DB::connection($connection)->table('firms')->where('id', $firmId)->update($update);

            if ($authorityIds !== null) {
                $this->syncRemoteComplianceAuthorities($connection, $firmId, $authorityIds);
            }

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

            if (Schema::connection($connection)->hasTable('firm_compliance_authorities')) {
                DB::connection($connection)->table('firm_compliance_authorities')
                    ->where('subject_firm_id', $firmId)
                    ->orWhere('authority_firm_id', $firmId)
                    ->delete();
            }

            DB::connection($connection)->table('firms')->where('id', $firmId)->delete();
        });
    }

    /**
     * Eligible firm-admin candidates (active members on the remote hub).
     *
     * @return array{
     *   firm: array<string, mixed>,
     *   members: list<array{id: int, name: string, email: string, is_admin: bool}>,
     *   admin_user_ids: list<int>
     * }
     */
    public function members(Hub $hub, int $firmId): array
    {
        $this->assertTarget($hub);

        return $this->remoteDb->run($hub, function (string $connection) use ($firmId) {
            $firm = Firm::on($connection)->find($firmId);
            if (! $firm) {
                throw new InvalidArgumentException('Firm not found on this hub.');
            }

            $role = $this->headRoles->headRoleForFirm($firm);
            $roles = app(UserRolesService::class);

            $query = User::on($connection)
                ->where('firm_id', $firmId)
                ->orderBy('name');

            if (Schema::connection($connection)->hasColumn('users', 'is_discontinued')) {
                $query->where(function ($q) {
                    $q->where('is_discontinued', false)->orWhereNull('is_discontinued');
                });
            }

            $adminIds = [];
            $members = $query->get(['id', 'name', 'email'])
                ->map(function (User $u) use ($roles, $role, &$adminIds) {
                    $isAdmin = in_array($role, $roles->roleKeys($u), true);
                    if ($isAdmin) {
                        $adminIds[] = (int) $u->id;
                    }

                    return [
                        'id' => (int) $u->id,
                        'name' => (string) $u->name,
                        'email' => (string) $u->email,
                        'is_admin' => $isAdmin,
                    ];
                })
                ->values()
                ->all();

            return [
                'firm' => $this->serializeRow($connection, $firmId),
                'members' => $members,
                'admin_user_ids' => $adminIds,
            ];
        });
    }

    /**
     * Sync the set of firm admins on the remote hub DB (multiple allowed).
     *
     * @param  list<int>  $headUserIds
     * @return array<string, mixed>
     */
    public function syncAdmins(
        Hub $hub,
        int $firmId,
        array $headUserIds,
        ?User $actor = null,
        ?Request $request = null,
    ): array {
        $this->assertTarget($hub);

        return $this->remoteDb->run($hub, function (string $connection) use ($firmId, $headUserIds, $hub, $actor, $request) {
            if (! Schema::connection($connection)->hasColumn('firms', 'head_user_id')) {
                throw new InvalidArgumentException(
                    'This hub has not been migrated for Head of Firm yet. Run migrations on that hub’s database.'
                );
            }

            $firm = Firm::on($connection)->find($firmId);
            if (! $firm) {
                throw new InvalidArgumentException('Firm not found on this hub.');
            }

            $ids = [];
            foreach ($headUserIds as $id) {
                $id = (int) $id;
                if ($id > 0 && ! in_array($id, $ids, true)) {
                    $ids[] = $id;
                }
            }

            $role = $this->headRoles->headRoleForFirm($firm);
            $roles = app(UserRolesService::class);

            $currentAdmins = User::on($connection)
                ->where('firm_id', $firmId)
                ->get()
                ->filter(fn (User $u) => in_array($role, $roles->roleKeys($u), true))
                ->map(fn (User $u) => (int) $u->id)
                ->values()
                ->all();

            $toAdd = array_values(array_diff($ids, $currentAdmins));
            $toRemove = array_values(array_diff($currentAdmins, $ids));

            foreach ($ids as $id) {
                $head = User::on($connection)->find($id);
                if (! $head) {
                    throw new InvalidArgumentException('The selected user was not found on this hub.');
                }
                if ($head->firm_id === null || (int) $head->firm_id !== (int) $firmId) {
                    throw new InvalidArgumentException('Each firm admin must be a member of this firm.');
                }
                if ($head->isDiscontinued()) {
                    throw new InvalidArgumentException('A discontinued user cannot be Head of Firm.');
                }
            }

            foreach ($toAdd as $id) {
                $this->headRoles->grantHeadRole(
                    User::on($connection)->find($id),
                    $firm,
                    $actor,
                    $request,
                    $hub,
                );
            }

            foreach ($toRemove as $id) {
                $user = User::on($connection)->find($id);
                if ($user) {
                    $this->headRoles->revokeHeadRole($user, $firm, $actor, $request, $hub);
                }
            }

            if ($ids === []) {
                $firm->head_user_id = null;
            } elseif ($firm->head_user_id === null
                || ! in_array((int) $firm->head_user_id, $ids, true)
            ) {
                $firm->head_user_id = $ids[0];
            }
            $firm->save();

            $serialized = $this->serializeRow($connection, $firmId);
            $serialized['admin_user_ids'] = $ids;

            return $serialized;
        });
    }

    /**
     * @deprecated Prefer syncAdmins(). Maps single id / null onto a full sync.
     *
     * @return array<string, mixed>
     */
    public function assignHead(
        Hub $hub,
        int $firmId,
        ?int $headUserId,
        ?User $actor = null,
        ?Request $request = null,
    ): array {
        return $this->syncAdmins(
            $hub,
            $firmId,
            $headUserId !== null ? [$headUserId] : [],
            $actor,
            $request,
        );
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
        $usersCount = (int) DB::connection($connection)->table('users')->where('firm_id', $firmId)->count();
        $byId = DB::connection($connection)->table('firms')->get()->keyBy('id');

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
            'compliance_visibility' => $this->complianceVisibilityPayload($connection, $row, $byId),
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int|string, mixed>|null  $byId
     * @return array<string, mixed>
     */
    private function complianceVisibilityPayload(?string $connection, object $row, $byId = null): array
    {
        $authorityFirms = $this->loadRemoteAuthorityFirms($connection, (int) $row->id, $byId, $row);

        $centralAuthority = collect($authorityFirms)->first(fn ($f) => (bool) ($f['is_central'] ?? false));
        $firstPeer = collect($authorityFirms)->first(fn ($f) => ! (bool) ($f['is_central'] ?? false));

        return [
            'visible_to_own' => (bool) ($row->compliance_visible_to_own ?? true),
            'authority_firm_ids' => array_map(
                static fn ($f) => (int) $f['id'],
                $authorityFirms
            ),
            'authority_firms' => $authorityFirms,
            'visible_to_central' => $centralAuthority !== null,
            'visible_to_firm_id' => $firstPeer ? (int) $firstPeer['id'] : null,
            'visible_to_firm' => $firstPeer
                ? ['id' => (int) $firstPeer['id'], 'name' => (string) $firstPeer['name']]
                : null,
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int|string, mixed>|null  $byId
     * @return list<array{id: int, name: string, is_central: bool}>
     */
    private function loadRemoteAuthorityFirms(
        ?string $connection,
        int $subjectFirmId,
        $byId = null,
        ?object $row = null
    ): array {
        $firms = [];

        if ($connection
            && Schema::connection($connection)->hasTable('firm_compliance_authorities')
        ) {
            $authorityIds = DB::connection($connection)->table('firm_compliance_authorities')
                ->where('subject_firm_id', $subjectFirmId)
                ->pluck('authority_firm_id')
                ->map(static fn ($id) => (int) $id)
                ->all();

            foreach ($authorityIds as $authorityId) {
                $other = $byId?->get($authorityId)
                    ?? DB::connection($connection)->table('firms')->where('id', $authorityId)->first();
                if ($other) {
                    $firms[] = [
                        'id' => (int) $other->id,
                        'name' => (string) $other->name,
                        'is_central' => (bool) ($other->is_central ?? false),
                    ];
                }
            }

            return $firms;
        }

        // Legacy fallback.
        if ($row && (bool) ($row->compliance_visible_to_central ?? false) && $connection) {
            $central = $byId
                ? $byId->first(fn ($f) => (bool) ($f->is_central ?? false))
                : DB::connection($connection)->table('firms')->where('is_central', true)->first();
            if ($central && (int) $central->id !== $subjectFirmId) {
                $firms[] = [
                    'id' => (int) $central->id,
                    'name' => (string) $central->name,
                    'is_central' => true,
                ];
            }
        }
        if ($row && $row->compliance_visible_to_firm_id) {
            $peerId = (int) $row->compliance_visible_to_firm_id;
            $other = $byId?->get($peerId)
                ?? ($connection
                    ? DB::connection($connection)->table('firms')->where('id', $peerId)->first()
                    : null);
            if ($other && (int) $other->id !== $subjectFirmId) {
                $firms[] = [
                    'id' => (int) $other->id,
                    'name' => (string) $other->name,
                    'is_central' => (bool) ($other->is_central ?? false),
                ];
            }
        }

        return $firms;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<int>|null
     */
    private function resolveRemoteAuthorityFirmIds(
        string $connection,
        array $payload,
        ?object $existing
    ): ?array {
        if (array_key_exists('compliance_authority_firm_ids', $payload)) {
            return array_values(array_unique(array_map(
                static fn ($id) => (int) $id,
                $payload['compliance_authority_firm_ids'] ?? []
            )));
        }

        $hasLegacy = array_key_exists('compliance_visible_to_central', $payload)
            || array_key_exists('compliance_visible_to_firm_id', $payload);

        if (! $hasLegacy) {
            return $existing ? null : [];
        }

        $ids = [];
        $wantCentral = array_key_exists('compliance_visible_to_central', $payload)
            ? (bool) $payload['compliance_visible_to_central']
            : (bool) ($existing->compliance_visible_to_central ?? false);

        if ($wantCentral) {
            $centralId = DB::connection($connection)->table('firms')->where('is_central', true)->value('id');
            if ($centralId) {
                $ids[] = (int) $centralId;
            }
        }

        $peerId = array_key_exists('compliance_visible_to_firm_id', $payload)
            ? $payload['compliance_visible_to_firm_id']
            : ($existing->compliance_visible_to_firm_id ?? null);

        if ($peerId !== null && $peerId !== '') {
            $ids[] = (int) $peerId;
        }

        return array_values(array_unique(array_filter($ids)));
    }

    /**
     * @param  list<int>  $authorityFirmIds
     */
    private function syncRemoteComplianceAuthorities(
        string $connection,
        int $subjectFirmId,
        array $authorityFirmIds
    ): void {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn ($id) => (int) $id, $authorityFirmIds),
            static fn (int $id) => $id > 0 && $id !== $subjectFirmId
        )));

        $validIds = DB::connection($connection)->table('firms')
            ->whereIn('id', $ids)
            ->pluck('id')
            ->map(static fn ($id) => (int) $id)
            ->all();

        $centralId = DB::connection($connection)->table('firms')->where('is_central', true)->value('id');
        $centralId = $centralId ? (int) $centralId : null;
        $hasCentral = $centralId !== null && in_array($centralId, $validIds, true);
        $peerIds = array_values(array_filter(
            $validIds,
            static fn (int $id) => $centralId === null || $id !== $centralId
        ));

        DB::connection($connection)->table('firms')->where('id', $subjectFirmId)->update([
            'compliance_visible_to_central' => $hasCentral,
            'compliance_visible_to_firm_id' => $peerIds[0] ?? null,
            'updated_at' => now(),
        ]);

        if (! Schema::connection($connection)->hasTable('firm_compliance_authorities')) {
            return;
        }

        DB::connection($connection)->table('firm_compliance_authorities')
            ->where('subject_firm_id', $subjectFirmId)
            ->delete();

        $now = now();
        foreach ($validIds as $authorityId) {
            DB::connection($connection)->table('firm_compliance_authorities')->insert([
                'subject_firm_id' => $subjectFirmId,
                'authority_firm_id' => $authorityId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
