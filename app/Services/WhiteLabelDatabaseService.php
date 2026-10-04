<?php

namespace App\Services;

use App\Models\Hub;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PDO;
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

        $config = [
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

        return array_merge($config, $this->sslOptions($hub, $driver));
    }

    /**
     * @return array<string, mixed>
     */
    private function sslOptions(Hub $hub, string $driver): array
    {
        $mode = strtolower(trim((string) ($hub->db_ssl_mode ?: 'disabled')));
        if ($mode === '' || $mode === 'disabled' || $mode === 'false' || $mode === '0') {
            return [];
        }

        if ($driver === 'pgsql') {
            // prefer / require / verify-ca / verify-full
            $pgsqlMode = match ($mode) {
                'preferred', 'prefer' => 'prefer',
                'verify_ca', 'verify-ca' => 'verify-ca',
                'verify_identity', 'verify-full', 'verify_full' => 'verify-full',
                default => 'require',
            };

            $out = ['sslmode' => $pgsqlMode];
            if (filled($hub->db_ssl_ca)) {
                $out['sslrootcert'] = (string) $hub->db_ssl_ca;
            }

            return $out;
        }

        // MySQL / MariaDB via PDO
        $options = [];
        if (filled($hub->db_ssl_ca)) {
            $options[PDO::MYSQL_ATTR_SSL_CA] = (string) $hub->db_ssl_ca;
        }

        $verify = in_array($mode, ['verify_ca', 'verify-ca', 'verify_identity', 'verify-identity'], true);
        if (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
            $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = $verify;
        }

        // "required" / "preferred" without CA still requests an SSL socket when the server supports it.
        if ($options === [] && in_array($mode, ['required', 'require', 'preferred', 'prefer'], true)) {
            // Empty CA with verify off — many hosts still negotiate TLS.
            if (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
                $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
            }
        }

        return $options === [] ? [] : ['options' => $options];
    }
}
