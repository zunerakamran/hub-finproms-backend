<?php

namespace App\Services;

use App\Models\Hub;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Opens a temporary Laravel DB connection to a white-label hub's own database.
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
        if ($hub->isShared()) {
            throw new InvalidArgumentException('The shared hub does not use a remote database connection.');
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
        $driver = $hub->db_driver ?: 'mysql';

        Config::set("database.connections.{$name}", [
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
        ]);

        DB::purge($name);

        return $name;
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function test(Hub $hub): array
    {
        try {
            $name = $this->connect($hub);
            DB::connection($name)->select('select 1 as ok');

            return [
                'ok' => true,
                'message' => 'Connected to '.$hub->db_database.' on '.$hub->db_host.'.',
            ];
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'message' => $e->getMessage(),
            ];
        } finally {
            $this->disconnect($hub);
        }
    }

    public function disconnect(Hub $hub): void
    {
        $name = $this->connectionName($hub);
        try {
            DB::purge($name);
        } catch (Throwable) {
            // ignore
        }
    }
}
