<?php

namespace Tests\Feature;

use App\Models\Hub;
use App\Models\User;
use App\Services\HubService;
use App\Services\LoginMfaChallengeService;
use App\Services\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginMfaTest extends TestCase
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
        ]);
        app(HubService::class)->forgetCurrentCache();
    }

    public function test_login_requires_mfa_when_user_enabled_authenticator(): void
    {
        $secret = app(TotpService::class)->generateSecret();
        $user = User::factory()->create([
            'email' => 'mfa.user@example.com',
            'password' => 'password12',
            'email_verified_at' => now(),
            'two_factor_enabled' => false,
            'mfa_enabled' => true,
            'mfa_secret' => $secret,
            'mfa_confirmed_at' => now(),
        ]);

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password12',
        ])
            ->assertOk()
            ->assertJsonPath('mfa_required', true)
            ->assertJsonStructure(['mfa_challenge', 'email'])
            ->assertJsonMissing(['token', 'otp_required']);

        $this->assertDatabaseHas('login_mfa_challenges', [
            'email' => 'mfa.user@example.com',
        ]);
    }

    public function test_verify_login_mfa_issues_token(): void
    {
        $totp = app(TotpService::class);
        $secret = $totp->generateSecret();
        $user = User::factory()->create([
            'email' => 'mfa.verify@example.com',
            'password' => 'password12',
            'email_verified_at' => now(),
            'mfa_enabled' => true,
            'mfa_secret' => $secret,
            'mfa_confirmed_at' => now(),
        ]);

        $challenge = app(LoginMfaChallengeService::class)->create($user);
        $code = $totp->currentCode($secret);

        $this->postJson('/api/auth/verify-login-mfa', [
            'email' => $user->email,
            'challenge' => $challenge['challenge'],
            'code' => $code,
        ])
            ->assertOk()
            ->assertJsonStructure(['token', 'user']);

        $this->assertDatabaseMissing('login_mfa_challenges', [
            'email' => 'mfa.verify@example.com',
        ]);
    }

    public function test_verify_login_mfa_rejects_bad_code(): void
    {
        $secret = app(TotpService::class)->generateSecret();
        $user = User::factory()->create([
            'email' => 'mfa.bad@example.com',
            'email_verified_at' => now(),
            'mfa_enabled' => true,
            'mfa_secret' => $secret,
            'mfa_confirmed_at' => now(),
        ]);

        $challenge = app(LoginMfaChallengeService::class)->create($user);

        $this->postJson('/api/auth/verify-login-mfa', [
            'email' => $user->email,
            'challenge' => $challenge['challenge'],
            'code' => '000000',
        ])->assertStatus(422);
    }

    public function test_mfa_takes_precedence_over_email_otp(): void
    {
        $secret = app(TotpService::class)->generateSecret();
        $user = User::factory()->create([
            'email' => 'mfa.pref@example.com',
            'password' => 'password12',
            'email_verified_at' => now(),
            'two_factor_enabled' => true,
            'mfa_enabled' => true,
            'mfa_secret' => $secret,
            'mfa_confirmed_at' => now(),
        ]);

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password12',
        ])
            ->assertOk()
            ->assertJsonPath('mfa_required', true)
            ->assertJsonMissing(['otp_required']);
    }

    public function test_public_hub_payload_exposes_login_mfa_per_user(): void
    {
        $this->getJson('/api/hub')
            ->assertOk()
            ->assertJsonPath('hub.auth.login_mfa_per_user', true);
    }
}
