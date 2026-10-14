<?php

namespace Tests\Unit;

use App\Support\SafeHtml;
use PHPUnit\Framework\TestCase;

class SafeHtmlTest extends TestCase
{
    public function test_strips_script_tags_and_event_handlers(): void
    {
        $dirty = '<p>Hello</p><script>alert(1)</script><img src=x onerror="alert(1)">';
        $clean = SafeHtml::sanitize($dirty);

        $this->assertStringContainsString('<p>Hello</p>', $clean);
        $this->assertStringNotContainsString('<script', $clean);
        $this->assertStringNotContainsString('onerror', $clean);
    }

    public function test_blocks_javascript_urls(): void
    {
        $dirty = '<a href="javascript:alert(1)">click</a>';
        $clean = SafeHtml::sanitize($dirty);

        $this->assertStringNotContainsString('javascript:', strtolower($clean));
    }

    public function test_keeps_safe_markup(): void
    {
        $ok = '<p><strong>Bold</strong> and <a href="https://example.com">link</a></p>';
        $this->assertSame($ok, SafeHtml::sanitize($ok));
    }
}
