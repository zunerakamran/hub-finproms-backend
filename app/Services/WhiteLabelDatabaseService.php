<?php

namespace App\Services;

use App\Models\Hub;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Opens a temporary Laravel DB connection to a white-labelled hub's own database.
 *
 * Connections are leased per request: nested run()/connect() calls reuse the same
 * PDO instead of purge+reconnect on every call (important under Central remote control).
 */
class WhiteLabelDatabaseService
{
    /** @var array<string, int> */
    private array $leaseCounts = [];

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
        $config = $this->connectionConfig($hub);
        $existing = Config::get("database.connections.{$name}");
        $leased = ($this->leaseCounts[$name] ?? 0) > 0;

        // Already wired + leased in this request — keep the live PDO.
        if ($leased && $existing === $config) {
            return $name;
        }

        Config::set("database.connections.{$name}", $config);

        // Only purge when establishing a fresh lease (or credentials changed).
        if (! $leased || $existing !== $config) {
            DB::purge($name);
        }

        return $name;
    }

    /**
     * Open the remote connection, run a callback, then disconnect when the
     * outermost lease for that hub ends.
     *
     * @template T
     *
     * @param  callable(string): T  $callback
     * @return T
     */
    public function run(Hub $hub, callable $callback): mixed
    {
        $name = $this->connect($hub);
        $this->leaseCounts[$name] = ($this->leaseCounts[$name] ?? 0) + 1;

        try {
            return $callback($name);
        } finally {
            $this->leaseCounts[$name] = max(0, ($this->leaseCounts[$name] ?? 1) - 1);
            if (($this->leaseCounts[$name] ?? 0) === 0) {
                unset($this->leaseCounts[$name]);
                $this->disconnect($hub);
            }
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
        $name = $this->connectionName($hub);
        if (($this->leaseCounts[$name] ?? 0) > 0) {
            // Nested caller still needs the connection.
            return;
        }

        try {
            DB::purge($name);
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
