<?php

namespace App\Services;

use App\Models\Hub;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Opens a temporary Laravel DB connection to a white-labelled hub's own database.
 */
class WhiteLabelDatabaseService
{
    public function connectionName(Hub $hub): string
    {
        return 'hub_remote_'.$hub->id;
    }

    /**
     * @throws InvalidArgumentException
     */
    public function assertConfigured(Hub $hub): void
    {
        if ($hub->isControlPlane()) {
            throw new InvalidArgumentException('The Central Hub Controller does not use a remote database connection.');
        }

        if (! $hub->isContentHub()) {
            throw new InvalidArgumentException('Only Shared and White-labelled hubs use remote database connections.');
        }

        if (! $hub->hasRemoteDatabaseConfigured()) {
            throw new InvalidArgumentException(
                'Remote database credentials are incomplete for hub "'.$hub->name.'".'
            );
        }
    }

    /**
     * Register and return the connection name for this hub.
     *
     * @throws InvalidArgumentException
     */
    public function connect(Hub $hub): string
    {
        $this->assertConfigured($hub);

        $name = $this->connectionName($hub);
        Config::set("database.connections.{$name}", $this->connectionConfig($hub));
        DB::purge($name);

        return $name;
    }

    /**
     * Open the remote connection, run a callback, then disconnect.
     *
     * @template T
     *
     * @param  callable(string): T  $callback
     * @return T
     */
    public function run(Hub $hub, callable $callback): mixed
    {
        $name = $this->connect($hub);

        try {
            return $callback($name);
        } finally {
            $this->disconnect($hub);
        }
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function test(Hub $hub): array
    {
        try {
            return $this->run($hub, function (string $name) use ($hub) {
                DB::connection($name)->select('select 1 as ok');

                return [
                    'ok' => true,
                    'message' => 'Connected to '.$hub->db_database.' on '.$hub->db_host.'.',
                ];
            });
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    public function disconnect(Hub $hub): void
    {
        try {
            DB::purge($this->connectionName($hub));
        } catch (Throwable) {
            // ignore
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function connectionConfig(Hub $hub): array
    {
        $driver = $hub->db_driver ?: 'mysql';

        if ($driver === 'sqlite') {
            return [
                'driver' => 'sqlite',
                'database' => $hub->db_database,
                'prefix' => '',
                'foreign_key_constraints' => true,
            ];
        }

        return [
            'driver' => $driver,
            'host' => $hub->db_host,
            'port' => $hub->db_port ?: ($driver === 'pgsql' ? 5432 : 3306),
            'database' => $hub->db_database,
            'username' => $hub->db_username,
            'password' => $hub->db_password,
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
        ];
    }
}
