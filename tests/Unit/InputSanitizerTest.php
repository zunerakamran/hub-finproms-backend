<?php

namespace Tests\Unit;

use App\Support\InputSanitizer;
use Tests\TestCase;

class InputSanitizerTest extends TestCase
{
    public function test_wire_strips_null_bytes_and_control_chars(): void
    {
        $dirty = "hello\0world\x01\x02\tkeep\nme";
        $clean = InputSanitizer::wire($dirty);

        $this->assertStringNotContainsString("\0", $clean);
        $this->assertStringNotContainsString("\x01", $clean);
        $this->assertStringContainsString("\t", $clean);
        $this->assertStringContainsString("\n", $clean);
        $this->assertStringContainsString('helloworld', str_replace(["\t", "\n"], '', $clean));
    }

    public function test_plain_strips_tags(): void
    {
        $clean = InputSanitizer::plain('<b>Hi</b> <script>x</script>there');

        $this->assertSame('Hi xthere', $clean);
        $this->assertStringNotContainsString('<', $clean);
    }

    public function test_rich_html_strips_script_when_enabled(): void
    {
        config(['security_app.sanitize_rich_html' => true]);

        $clean = InputSanitizer::richHtml('<p>Safe</p><script>alert(1)</script>');

        $this->assertStringContainsString('Safe', $clean);
        $this->assertStringNotContainsString('<script', $clean);
    }

    public function test_rich_html_keys_and_plain_keys(): void
    {
        config(['security_app.sanitize_rich_html' => true]);

        $data = [
            'title' => ' <b>Hi</b> ',
            'body' => '<p>Ok</p><script>x</script>',
            'other' => 12,
        ];

        $data = InputSanitizer::plainKeys($data, ['title']);
        $data = InputSanitizer::richHtmlKeys($data, ['body']);

        $this->assertSame('Hi', $data['title']);
        $this->assertStringContainsString('Ok', $data['body']);
        $this->assertStringNotContainsString('<script', $data['body']);
        $this->assertSame(12, $data['other']);
    }
}
