<?php

namespace App\Support\WebsiteCompliance;

/**
 * Request-scoped DB connection for Website Compliance models.
 * When Power Admin acts on a white-labelled hub from shared, WC reads/writes
 * that hub's database without changing the shared deploy default connection
 * (Hub registry, auth, activity logs stay on shared).
 */
class WcDatabaseContext
{
    private static ?string $connection = null;

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function using(?string $connection, callable $callback): mixed
    {
        $previous = self::$connection;
        self::$connection = $connection;

        try {
            return $callback();
        } finally {
            self::$connection = $previous;
        }
    }

    public static function connection(): ?string
    {
        return self::$connection;
    }

    public static function active(): bool
    {
        return self::$connection !== null && self::$connection !== '';
    }
}
