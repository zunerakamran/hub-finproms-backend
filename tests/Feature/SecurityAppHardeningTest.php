<?php

namespace Tests\Feature;

use App\Models\Hub;
use App\Models\User;
use App\Services\HubService;
use App\Support\TermsAndConditionsDefaults;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SecurityAppHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Hub::query()->create([
            'name' => 'Shared Hub',
            'slug' => 'shared',
            'type' => Hub::TYPE_SHARED,
            'is_active' => true,
            'checklist' => Hub::defaultChecklist(Hub::TYPE_SHARED),
            'terms_and_conditions' => [
                'content' => TermsAndConditionsDefaults::shared(),
                'version' => 1,
                'updated_at' => now()->toIso8601String(),
            ],
        ]);
        app(HubService::class)->forgetCurrentCache();
    }

    public function test_api_responses_include_security_headers(): void
    {
        $this->getJson('/api/hub')
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Security-Policy');
    }

    public function test_auth_throttle_trips_after_limit(): void
    {
        RateLimiter::for('auth', function (Request $request) {
            $email = strtolower(trim((string) $request->input('email', '')));

            return Limit::perMinute(2)->by('auth-test:'.$request->ip().'|'.$email);
        });

        User::factory()->create([
            'email' => 'victim@example.test',
            'password' => 'CorrectHorseBattery',
        ]);

        $this->postJson('/api/auth/login', [
            'email' => 'victim@example.test',
            'password' => 'wrong-password',
        ])->assertStatus(422);

        $this->postJson('/api/auth/login', [
            'email' => 'victim@example.test',
            'password' => 'wrong-password',
        ])->assertStatus(422);

        $this->postJson('/api/auth/login', [
            'email' => 'victim@example.test',
            'password' => 'wrong-password',
        ])->assertStatus(429);
    }

    public function test_terms_update_sanitizes_script_payload(): void
    {
        config(['security_app.sanitize_rich_html' => true]);

        $admin = User::factory()->create([
            'role' => User::ROLE_FINPROMS_ADMIN,
            'email_verified_at' => now(),
            'terms_accepted_at' => now(),
            'terms_accepted_version' => 1,
        ]);
        Sanctum::actingAs($admin);

        $this->putJson('/api/client-admin/terms', [
            'content' => '<p>Safe</p><script>alert(1)</script>',
        ])
            ->assertOk()
            ->assertJsonPath('terms.version', 2);

        $content = (string) data_get(
            $this->getJson('/api/client-admin/terms')->json(),
            'terms.content',
            ''
        );
        $this->assertStringContainsString('Safe', $content);
        $this->assertStringNotContainsString('<script', $content);
    }
}
