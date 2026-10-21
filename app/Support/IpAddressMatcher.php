<?php

namespace App\Support;

/**
 * Match a client IP against a single entry: bare IP, CIDR, or hyphenated range.
 *
 * Examples: 203.0.113.10 | 198.51.100.0/24 | 203.0.113.10-203.0.113.50
 *           2001:db8::1 | 2001:db8::/32 | 2001:db8::1-2001:db8::ff
 */
class IpAddressMatcher
{
    public static function isValidEntry(string $value): bool
    {
        $value = trim($value);
        if ($value === '') {
            return false;
        }

        if (str_contains($value, '/')) {
            return self::isValidCidr($value);
        }

        if (self::parseRange($value) !== null) {
            return true;
        }

        return filter_var($value, FILTER_VALIDATE_IP) !== false;
    }

    public static function matches(string $ip, string $entry): bool
    {
        $ip = trim($ip);
        $entry = trim($entry);
        if ($ip === '' || $entry === '') {
            return false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        if (str_contains($entry, '/')) {
            return self::matchesCidr($ip, $entry);
        }

        $range = self::parseRange($entry);
        if ($range !== null) {
            return self::matchesRange($ip, $range[0], $range[1]);
        }

        return strcasecmp($ip, $entry) === 0;
    }

    /**
     * @return array{0: string, 1: string}|null  [start, end]
     */
    public static function parseRange(string $value): ?array
    {
        $value = trim($value);
        // Neither IPv4 nor IPv6 literals contain '-', so a single hyphen separates start/end.
        if (! str_contains($value, '-') || str_contains($value, '/')) {
            return null;
        }

        $parts = explode('-', $value, 2);
        if (count($parts) !== 2) {
            return null;
        }

        $start = trim($parts[0]);
        $end = trim($parts[1]);
        if ($start === '' || $end === '') {
            return null;
        }

        if (filter_var($start, FILTER_VALIDATE_IP) === false
            || filter_var($end, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $startBytes = @inet_pton($start);
        $endBytes = @inet_pton($end);
        if ($startBytes === false || $endBytes === false
            || strlen($startBytes) !== strlen($endBytes)) {
            return null;
        }

        // Require start <= end (network byte order strcmp).
        if (strcmp($startBytes, $endBytes) > 0) {
            return null;
        }

        return [$start, $end];
    }

    private static function isValidCidr(string $value): bool
    {
        [$subnet, $mask] = array_pad(explode('/', $value, 2), 2, null);
        if ($subnet === null || $mask === null || ! is_numeric($mask)) {
            return false;
        }

        if (filter_var($subnet, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        $mask = (int) $mask;
        $isV6 = filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        $max = $isV6 ? 128 : 32;

        return $mask >= 0 && $mask <= $max;
    }

    private static function matchesCidr(string $ip, string $entry): bool
    {
        [$subnet, $mask] = array_pad(explode('/', $entry, 2), 2, null);
        if ($subnet === null || $mask === null || ! is_numeric($mask)) {
            return false;
        }

        $mask = (int) $mask;
        $ipBytes = @inet_pton($ip);
        $subnetBytes = @inet_pton($subnet);
        if ($ipBytes === false || $subnetBytes === false || strlen($ipBytes) !== strlen($subnetBytes)) {
            return false;
        }

        $len = strlen($ipBytes);
        $bits = $len * 8;
        if ($mask < 0 || $mask > $bits) {
            return false;
        }

        $fullBytes = intdiv($mask, 8);
        $remain = $mask % 8;

        if ($fullBytes > 0 && substr($ipBytes, 0, $fullBytes) !== substr($subnetBytes, 0, $fullBytes)) {
            return false;
        }

        if ($remain === 0) {
            return true;
        }

        $maskByte = (~(0xFF >> $remain)) & 0xFF;

        return (ord($ipBytes[$fullBytes]) & $maskByte) === (ord($subnetBytes[$fullBytes]) & $maskByte);
    }

    private static function matchesRange(string $ip, string $start, string $end): bool
    {
        $ipBytes = @inet_pton($ip);
        $startBytes = @inet_pton($start);
        $endBytes = @inet_pton($end);
        if ($ipBytes === false || $startBytes === false || $endBytes === false) {
            return false;
        }
        if (strlen($ipBytes) !== strlen($startBytes) || strlen($ipBytes) !== strlen($endBytes)) {
            return false;
        }

        return strcmp($ipBytes, $startBytes) >= 0 && strcmp($ipBytes, $endBytes) <= 0;
    }

    /**
     * True when two list entries overlap (exact IP, overlapping CIDR, or overlapping range).
     */
    public static function entriesOverlap(string $a, string $b): bool
    {
        $a = trim($a);
        $b = trim($b);
        if ($a === '' || $b === '') {
            return false;
        }

        if (strcasecmp($a, $b) === 0) {
            return true;
        }

        $rangeA = self::entryBounds($a);
        $rangeB = self::entryBounds($b);
        if ($rangeA === null || $rangeB === null) {
            return false;
        }
        if (strlen($rangeA[0]) !== strlen($rangeB[0])) {
            return false;
        }

        // Overlap if A.start <= B.end && B.start <= A.end
        return strcmp($rangeA[0], $rangeB[1]) <= 0 && strcmp($rangeB[0], $rangeA[1]) <= 0;
    }

    /**
     * @return array{0: string, 1: string}|null  binary [start, end] via inet_pton
     */
    private static function entryBounds(string $entry): ?array
    {
        $entry = trim($entry);

        if (str_contains($entry, '/')) {
            [$subnet, $mask] = array_pad(explode('/', $entry, 2), 2, null);
            if ($subnet === null || $mask === null || ! is_numeric($mask)) {
                return null;
            }
            $mask = (int) $mask;
            $bytes = @inet_pton($subnet);
            if ($bytes === false) {
                return null;
            }
            $bits = strlen($bytes) * 8;
            if ($mask < 0 || $mask > $bits) {
                return null;
            }

            $start = $bytes;
            $end = $bytes;
            for ($i = 0; $i < strlen($bytes); $i++) {
                $bitStart = $i * 8;
                $bitEnd = $bitStart + 7;
                if ($mask <= $bitStart) {
                    $start[$i] = "\x00";
                    $end[$i] = "\xFF";
                } elseif ($mask <= $bitEnd) {
                    $remain = $mask - $bitStart;
                    $hostMask = (0xFF >> $remain) & 0xFF;
                    $networkMask = (~$hostMask) & 0xFF;
                    $start[$i] = chr(ord($bytes[$i]) & $networkMask);
                    $end[$i] = chr(ord($bytes[$i]) | $hostMask);
                }
            }

            return [$start, $end];
        }

        $range = self::parseRange($entry);
        if ($range !== null) {
            $start = @inet_pton($range[0]);
            $end = @inet_pton($range[1]);
            if ($start === false || $end === false) {
                return null;
            }

            return [$start, $end];
        }

        $ip = @inet_pton($entry);
        if ($ip === false) {
            return null;
        }

        return [$ip, $ip];
    }
}
