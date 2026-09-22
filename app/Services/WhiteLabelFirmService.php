<?php

namespace App\Services;

use App\Models\Hub;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * CRUD firms on a white-label hub's own database while the shared
 * dashboard hub switcher is acting on that hub.
 */
class WhiteLabelFirmService
{
    public function __construct(
        private readonly WhiteLabelDatabaseService $remoteDb
    ) {}

    public function assertTarget(Hub $hub): void
    {
        if ($hub->isShared()) {
            throw new InvalidArgumentException('Select a white-label hub, not the shared hub.');
        }
        if (! $hub->is_active) {
            throw new InvalidArgumentException('That white-label hub is inactive.');
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
                'firms' => $rows->map(function ($row) use ($counts, $byId) {
                    $other = $row->compliance_visible_to_firm_id
                        ? $byId->get($row->compliance_visible_to_firm_id)
                        : null;

                    return [
                        'id' => (int) $row->id,
                        'name' => (string) $row->name,
                        'is_central' => (bool) $row->is_central,
                        'users_count' => (int) ($counts[$row->id] ?? 0),
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
                })->values()->all(),
                'central_firm_id' => DB::connection($connection)->table('firms')
                    ->where('is_central', true)
                    ->value('id'),
            ];
        });
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
                throw new InvalidArgumentException('Firm not found on this white-label hub.');
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
                throw new InvalidArgumentException('Firm not found on this white-label hub.');
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

        return [
            'id' => (int) $row->id,
            'name' => (string) $row->name,
            'is_central' => (bool) $row->is_central,
            'users_count' => $usersCount,
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
