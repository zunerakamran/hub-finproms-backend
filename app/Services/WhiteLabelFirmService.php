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
     * @return array{firms: list<array{id: int, name: string, users_count: int}>}
     */
    public function list(Hub $hub): array
    {
        $this->assertTarget($hub);

        return $this->remoteDb->run($hub, function (string $connection) {
            $counts = DB::connection($connection)->table('users')
                ->whereNotNull('firm_id')
                ->selectRaw('firm_id, COUNT(*) as users_count')
                ->groupBy('firm_id')
                ->pluck('users_count', 'firm_id');

            $rows = DB::connection($connection)->table('firms')->orderBy('name')->get();

            return [
                'firms' => $rows->map(fn ($row) => [
                    'id' => (int) $row->id,
                    'name' => (string) $row->name,
                    'users_count' => (int) ($counts[$row->id] ?? 0),
                ])->values()->all(),
            ];
        });
    }

    /**
     * @param  array{name: string}  $payload
     * @return array{id: int, name: string}
     */
    public function create(Hub $hub, array $payload): array
    {
        $this->assertTarget($hub);

        return $this->remoteDb->run($hub, function (string $connection) use ($payload) {
            $name = trim($payload['name']);
            if (DB::connection($connection)->table('firms')->where('name', $name)->exists()) {
                throw new InvalidArgumentException('A firm with that name already exists on this hub.');
            }

            $now = now();
            $id = (int) DB::connection($connection)->table('firms')->insertGetId([
                'name' => $name,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return ['id' => $id, 'name' => $name];
        });
    }

    /**
     * @param  array{name: string}  $payload
     * @return array{id: int, name: string}
     */
    public function update(Hub $hub, int $firmId, array $payload): array
    {
        $this->assertTarget($hub);

        return $this->remoteDb->run($hub, function (string $connection) use ($firmId, $payload) {
            $row = DB::connection($connection)->table('firms')->where('id', $firmId)->first();
            if (! $row) {
                throw new InvalidArgumentException('Firm not found on this white-label hub.');
            }

            $name = trim($payload['name']);
            $dup = DB::connection($connection)->table('firms')
                ->where('name', $name)
                ->where('id', '!=', $firmId)
                ->exists();
            if ($dup) {
                throw new InvalidArgumentException('A firm with that name already exists on this hub.');
            }

            DB::connection($connection)->table('firms')->where('id', $firmId)->update([
                'name' => $name,
                'updated_at' => now(),
            ]);

            return ['id' => $firmId, 'name' => $name];
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
            fn (array $firm) => ['id' => $firm['id'], 'name' => $firm['name']],
            $listed['firms']
        );
    }
}
