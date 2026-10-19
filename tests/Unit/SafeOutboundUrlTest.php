<?php

namespace Tests\Unit;

use App\Support\SafeOutboundUrl;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SafeOutboundUrlTest extends TestCase
{
    #[Test]
    public function it_blocks_loopback_and_private_ips(): void
    {
        $this->assertTrue(SafeOutboundUrl::isBlockedHost('127.0.0.1', resolveDns: false));
        $this->assertTrue(SafeOutboundUrl::isBlockedHost('10.0.0.5', resolveDns: false));
        $this->assertTrue(SafeOutboundUrl::isBlockedHost('169.254.169.254', resolveDns: false));
        $this->assertTrue(SafeOutboundUrl::isBlockedHost('localhost', resolveDns: false));
        $this->assertFalse(SafeOutboundUrl::isBlockedHost('example.com', resolveDns: false));
    }

    #[Test]
    public function it_parses_http_urls_only(): void
    {
        $this->assertNotNull(SafeOutboundUrl::parseHttpUrl('https://central.example.com/api/x'));
        $this->assertNull(SafeOutboundUrl::parseHttpUrl('file:///etc/passwd'));
        $this->assertNull(SafeOutboundUrl::parseHttpUrl('not a url'));
    }

    #[Test]
    public function it_matches_allowlisted_hosts(): void
    {
        $url = 'https://central.example.com/api/internal/hub-backups/receive';
        $this->assertTrue(SafeOutboundUrl::hostMatchesAllowlist($url, [
            'https://central.example.com/api',
        ]));
        $this->assertTrue(SafeOutboundUrl::hostMatchesAllowlist($url, [
            'central.example.com',
        ]));
        $this->assertFalse(SafeOutboundUrl::hostMatchesAllowlist($url, [
            'https://evil.example.com/api',
        ]));
    }
}
