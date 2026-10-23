<?php

namespace Tests\Feature;

use App\Models\Hub;
use App\Models\User;
use App\Services\HubService;
use App\Services\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MfaProfileTest extends TestCase
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

    public function test_enabling_mfa_requires_password_and_returns_setup(): void
    {
        $user = User::factory()->create([
            'password' => 'password12',
            'email_verified_at' => now(),
            'mfa_enabled' => false,
        ]);

        $this->actingAs($user)
            ->putJson('/api/auth/profile', [
                'mfa_enabled' => true,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['current_password']);

        $response = $this->actingAs($user)
            ->putJson('/api/auth/profile', [
                'mfa_enabled' => true,
                'current_password' => 'password12',
            ])
            ->assertOk()
            ->assertJsonPath('mfa_confirmation_required', true)
            ->assertJsonStructure(['mfa_setup' => ['secret', 'otpauth_uri', 'issuer', 'account']])
            ->assertJsonPath('user.mfa_enabled', false)
            ->assertJsonPath('user.mfa_pending', true);

        $this->assertNotEmpty($response->json('mfa_setup.secret'));
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'auth.mfa_setup_started',
            'user_id' => $user->id,
        ]);
    }

    public function test_confirm_mfa_enables_and_logs_choice(): void
    {
        $totp = app(TotpService::class);
        $secret = $totp->generateSecret();
        $user = User::factory()->create([
            'password' => 'password12',
            'email_verified_at' => now(),
            'two_factor_enabled' => true,
            'mfa_enabled' => false,
            'mfa_secret' => $secret,
            'mfa_confirmed_at' => null,
        ]);

        $code = $totp->currentCode($secret);

        $this->actingAs($user)
            ->postJson('/api/auth/mfa/confirm', [
                'code' => $code,
                'current_password' => 'password12',
            ])
            ->assertOk()
            ->assertJsonPath('user.mfa_enabled', true)
            ->assertJsonPath('user.mfa_pending', false)
            ->assertJsonPath('user.two_factor_enabled', false);

        $fresh = $user->fresh();
        $this->assertTrue($fresh->hasMfaEnabled());
        $this->assertFalse((bool) $fresh->two_factor_enabled);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'auth.mfa_chosen',
            'user_id' => $user->id,
        ]);
    }

    public function test_choosing_email_otp_logs_and_clears_mfa(): void
    {
        $secret = app(TotpService::class)->generateSecret();
        $user = User::factory()->create([
            'password' => 'password12',
            'email_verified_at' => now(),
            'two_factor_enabled' => false,
            'mfa_enabled' => true,
            'mfa_secret' => $secret,
            'mfa_confirmed_at' => now(),
        ]);

        $this->actingAs($user)
            ->putJson('/api/auth/profile', [
                'two_factor_enabled' => true,
                'current_password' => 'password12',
            ])
            ->assertOk()
            ->assertJsonPath('user.two_factor_enabled', true)
            ->assertJsonPath('user.mfa_enabled', false);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'auth.two_factor_otp_chosen',
            'user_id' => $user->id,
        ]);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'auth.mfa_disabled',
            'user_id' => $user->id,
        ]);

        $fresh = $user->fresh();
        $this->assertNull($fresh->mfa_secret);
        $this->assertNull($fresh->mfa_confirmed_at);
    }

    public function test_disabling_mfa_requires_password_and_logs(): void
    {
        $secret = app(TotpService::class)->generateSecret();
        $user = User::factory()->create([
            'password' => 'password12',
            'email_verified_at' => now(),
            'mfa_enabled' => true,
            'mfa_secret' => $secret,
            'mfa_confirmed_at' => now(),
        ]);

        $this->actingAs($user)
            ->putJson('/api/auth/profile', [
                'mfa_enabled' => false,
                'current_password' => 'password12',
            ])
            ->assertOk()
            ->assertJsonPath('user.mfa_enabled', false);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'auth.mfa_disabled',
            'user_id' => $user->id,
        ]);
    }

    public function test_cannot_enable_otp_and_mfa_together(): void
    {
        $user = User::factory()->create([
            'password' => 'password12',
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)
            ->putJson('/api/auth/profile', [
                'two_factor_enabled' => true,
                'mfa_enabled' => true,
                'current_password' => 'password12',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['mfa_enabled']);
    }
}
