<?php

namespace Tests\Unit;

use App\Support\SafeFrontendRedirect;
use Tests\TestCase;

class SafeFrontendRedirectTest extends TestCase
{
    public function test_path_only_targets_are_accepted(): void
    {
        config([
            'app.frontend_url' => 'https://myhub.fin-proms.com',
            'app.url' => 'https://myhub.fin-proms.com',
        ]);

        $this->assertSame(
            '/my-dashboard/posts',
            SafeFrontendRedirect::sanitize('/my-dashboard/posts')
        );
        $this->assertSame(
            '/login?next=1',
            SafeFrontendRedirect::sanitize('/login?next=1')
        );
    }

    public function test_off_origin_absolute_urls_fall_back(): void
    {
        config([
            'app.frontend_url' => 'https://myhub.fin-proms.com',
            'app.url' => 'https://myhub.fin-proms.com',
        ]);

        $this->assertSame(
            '/',
            SafeFrontendRedirect::sanitize('https://evil.example/phish')
        );
        $this->assertSame(
            '/my-dashboard',
            SafeFrontendRedirect::sanitize('//evil.example/phish', null, '/my-dashboard')
        );
    }

    public function test_same_origin_absolute_urls_reduce_to_path(): void
    {
        config([
            'app.frontend_url' => 'https://myhub.fin-proms.com',
            'app.url' => 'https://api.fin-proms.com',
        ]);

        $this->assertSame(
            '/verify-email',
            SafeFrontendRedirect::sanitize('https://myhub.fin-proms.com/verify-email')
        );
    }

    public function test_allowlisted_frontend_paths(): void
    {
        config([
            'security_navigation.allowlist_exact' => ['/login', '/verify-email'],
            'security_navigation.allowlist_prefixes' => ['/my-dashboard/payment-card/success'],
        ]);

        $this->assertTrue(SafeFrontendRedirect::isAllowlistedFrontendPath('/login'));
        $this->assertTrue(SafeFrontendRedirect::isAllowlistedFrontendPath('/my-dashboard/payment-card/success'));
        $this->assertFalse(SafeFrontendRedirect::isAllowlistedFrontendPath('/my-dashboard/posts'));
    }
}
