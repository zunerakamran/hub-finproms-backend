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

    private static ?int $hubId = null;

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function using(?string $connection, callable $callback, ?int $hubId = null): mixed
    {
        $previous = self::$connection;
        $previousHubId = self::$hubId;
        self::$connection = $connection;
        self::$hubId = $hubId;

        try {
            return $callback();
        } finally {
            self::$connection = $previous;
            self::$hubId = $previousHubId;
        }
    }

    public static function connection(): ?string
    {
        return self::$connection;
    }

    /**
     * Hub registry id when WC models are pointed at a remote content-hub DB.
     */
    public static function hubId(): ?int
    {
        return self::$hubId;
    }

    public static function active(): bool
    {
        return self::$connection !== null && self::$connection !== '';
    }
}
