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

    #[Test]
    public function it_rejects_unsafe_advisor_site_urls_without_dns(): void
    {
        $meta = SafeOutboundUrl::validateAdvisorSiteUrl('http://169.254.169.254/', resolveDns: false);
        $this->assertFalse($meta['ok']);

        $loopback = SafeOutboundUrl::validateAdvisorSiteUrl('127.0.0.1', resolveDns: false);
        $this->assertFalse($loopback['ok']);

        $private = SafeOutboundUrl::validateAdvisorSiteUrl('https://10.0.0.5/site', resolveDns: false);
        $this->assertFalse($private['ok']);

        $ok = SafeOutboundUrl::validateAdvisorSiteUrl('advisor.example.com', resolveDns: false);
        $this->assertTrue($ok['ok']);
        $this->assertSame('https://advisor.example.com', $ok['url']);
    }

    #[Test]
    public function it_reports_safe_outbound_urls(): void
    {
        $this->assertFalse(SafeOutboundUrl::isSafeOutboundUrl('http://169.254.169.254/latest', resolveDns: false));
        $this->assertFalse(SafeOutboundUrl::isSafeOutboundUrl('file:///etc/passwd', resolveDns: false));
        $this->assertTrue(SafeOutboundUrl::isSafeOutboundUrl('https://advisor.example.com/', resolveDns: false));
    }
}
