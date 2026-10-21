<?php

namespace Tests\Unit;

use App\Support\IpAddressMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class IpAddressMatcherTest extends TestCase
{
    #[DataProvider('validEntries')]
    public function test_accepts_valid_entries(string $entry): void
    {
        $this->assertTrue(IpAddressMatcher::isValidEntry($entry));
    }

    #[DataProvider('invalidEntries')]
    public function test_rejects_invalid_entries(string $entry): void
    {
        $this->assertFalse(IpAddressMatcher::isValidEntry($entry));
    }

    #[DataProvider('matchCases')]
    public function test_matches(string $ip, string $entry, bool $expected): void
    {
        $this->assertSame($expected, IpAddressMatcher::matches($ip, $entry));
    }

    public static function validEntries(): array
    {
        return [
            'ipv4' => ['203.0.113.10'],
            'cidr' => ['198.51.100.0/24'],
            'range' => ['203.0.113.10-203.0.113.20'],
            'range spaced' => ['203.0.113.10 - 203.0.113.20'],
            'ipv6' => ['2001:db8::1'],
            'ipv6 cidr' => ['2001:db8::/32'],
            'ipv6 range' => ['2001:db8::1-2001:db8::ff'],
        ];
    }

    public static function invalidEntries(): array
    {
        return [
            'empty' => [''],
            'garbage' => ['not-an-ip'],
            'bad cidr mask' => ['203.0.113.0/99'],
            'inverted range' => ['203.0.113.50-203.0.113.10'],
            'mixed family' => ['203.0.113.10-2001:db8::1'],
            'half range' => ['203.0.113.10-'],
        ];
    }

    public static function matchCases(): array
    {
        return [
            'exact' => ['203.0.113.10', '203.0.113.10', true],
            'exact miss' => ['203.0.113.11', '203.0.113.10', false],
            'cidr hit' => ['198.51.100.9', '198.51.100.0/24', true],
            'cidr miss' => ['198.51.101.9', '198.51.100.0/24', false],
            'range start' => ['203.0.113.10', '203.0.113.10-203.0.113.20', true],
            'range mid' => ['203.0.113.15', '203.0.113.10-203.0.113.20', true],
            'range end' => ['203.0.113.20', '203.0.113.10-203.0.113.20', true],
            'range before' => ['203.0.113.9', '203.0.113.10-203.0.113.20', false],
            'range after' => ['203.0.113.21', '203.0.113.10-203.0.113.20', false],
            'range spaced' => ['203.0.113.12', '203.0.113.10 - 203.0.113.20', true],
            'ipv6 range' => ['2001:db8::5', '2001:db8::1-2001:db8::10', true],
        ];
    }

    public function test_entries_overlap_detects_restrict_allow_conflicts(): void
    {
        $this->assertTrue(IpAddressMatcher::entriesOverlap('203.0.113.15', '203.0.113.10-203.0.113.20'));
        $this->assertTrue(IpAddressMatcher::entriesOverlap('198.51.100.0/24', '198.51.100.9'));
        $this->assertFalse(IpAddressMatcher::entriesOverlap('203.0.113.10-203.0.113.20', '203.0.113.21'));
        $this->assertFalse(IpAddressMatcher::entriesOverlap('203.0.113.10', '2001:db8::1'));
    }
}
