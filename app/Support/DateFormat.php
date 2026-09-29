<?php

namespace App\Support;

use Carbon\CarbonInterface;
use DateTimeInterface;

/**
 * Shared display date formats — always DD/MM/YYYY.
 */
final class DateFormat
{
    public const DATE = 'd/m/Y';

    public const DATETIME = 'd/m/Y H:i';

    public static function date(null|DateTimeInterface|CarbonInterface|string $value, string $fallback = ''): string
    {
        $carbon = self::parse($value);
        return $carbon ? $carbon->format(self::DATE) : $fallback;
    }

    public static function dateTime(null|DateTimeInterface|CarbonInterface|string $value, string $fallback = ''): string
    {
        $carbon = self::parse($value);
        return $carbon ? $carbon->format(self::DATETIME) : $fallback;
    }

    private static function parse(null|DateTimeInterface|CarbonInterface|string $value): ?CarbonInterface
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof CarbonInterface) {
            return $value;
        }

        try {
            return \Illuminate\Support\Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
