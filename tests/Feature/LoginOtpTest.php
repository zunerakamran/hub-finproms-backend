<?php

namespace Tests\Feature;

use App\Models\Hub;
use App\Models\User;
use App\Services\HubService;
use App\Services\LoginOtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class LoginOtpTest extends TestCase
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

        Mail::fake();
    }

    public function test_login_requires_otp_when_user_enabled_two_factor(): void
    {
        $user = User::factory()->create([
            'email' => 'otp.user@example.com',
            'password' => 'password12',
            'role' => User::ROLE_USER,
            'email_verified_at' => now(),
            'two_factor_enabled' => true,
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password12',
        ]);

        $response->assertOk()
            ->assertJsonPath('otp_required', true)
            ->assertJsonStructure(['otp_challenge', 'email'])
            ->assertJsonMissing(['token']);

        $this->assertDatabaseHas('login_otp_tokens', [
            'email' => 'otp.user@example.com',
        ]);
    }

    public function test_verify_login_otp_issues_token(): void
    {
        $user = User::factory()->create([
            'email' => 'otp.verify@example.com',
            'password' => 'password12',
            'role' => User::ROLE_USER,
            'email_verified_at' => now(),
            'two_factor_enabled' => true,
        ]);

        $otp = app(LoginOtpService::class)->create($user);

        $response = $this->postJson('/api/auth/verify-login-otp', [
            'email' => $user->email,
            'challenge' => $otp['challenge'],
            'code' => $otp['code'],
        ]);

        $response->assertOk()->assertJsonStructure(['token', 'user']);
        $this->assertDatabaseMissing('login_otp_tokens', [
            'email' => 'otp.verify@example.com',
        ]);
    }

    public function test_verify_login_otp_rejects_bad_code(): void
    {
        $user = User::factory()->create([
            'email' => 'otp.bad@example.com',
            'role' => User::ROLE_USER,
            'email_verified_at' => now(),
            'two_factor_enabled' => true,
        ]);

        $otp = app(LoginOtpService::class)->create($user);

        $this->postJson('/api/auth/verify-login-otp', [
            'email' => $user->email,
            'challenge' => $otp['challenge'],
            'code' => '000000',
        ])->assertStatus(422);
    }

    public function test_login_skips_otp_when_user_has_not_enabled_two_factor(): void
    {
        $user = User::factory()->create([
            'email' => 'no.otp@example.com',
            'password' => 'password12',
            'role' => User::ROLE_USER,
            'email_verified_at' => now(),
            'two_factor_enabled' => false,
        ]);

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password12',
        ])
            ->assertOk()
            ->assertJsonStructure(['token', 'user'])
            ->assertJsonMissing(['otp_required']);
    }

    public function test_resend_login_otp_returns_new_challenge(): void
    {
        $user = User::factory()->create([
            'email' => 'otp.resend@example.com',
            'role' => User::ROLE_USER,
            'email_verified_at' => now(),
            'two_factor_enabled' => true,
        ]);

        $service = app(LoginOtpService::class);
        $first = $service->create($user);

        DB::table('login_otp_tokens')
            ->where('email', $user->email)
            ->update(['created_at' => now()->subMinutes(2)]);

        $response = $this->postJson('/api/auth/resend-login-otp', [
            'email' => $user->email,
            'challenge' => $first['challenge'],
        ]);

        $response->assertOk()
            ->assertJsonPath('otp_required', true)
            ->assertJsonStructure(['otp_challenge']);

        $this->assertNotSame($first['challenge'], $response->json('otp_challenge'));
        $this->assertFalse(Hash::check($first['code'], DB::table('login_otp_tokens')->where('email', $user->email)->value('code')));
    }

    public function test_public_hub_payload_exposes_per_user_login_otp_flag(): void
    {
        $this->getJson('/api/hub')
            ->assertOk()
            ->assertJsonPath('auth.login_otp_required', false)
            ->assertJsonPath('auth.login_otp_per_user', true);
    }
}
