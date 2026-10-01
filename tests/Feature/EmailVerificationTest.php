<?php

namespace Tests\Feature;

use App\Models\Hub;
use App\Models\User;
use App\Services\EmailVerificationService;
use App\Services\HubService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
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

    public function test_register_requires_email_verification_and_does_not_return_token(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'New Member',
            'email' => 'new.member@example.com',
            'password' => 'password12',
            'password_confirmation' => 'password12',
        ]);

        $response->assertCreated()
            ->assertJsonPath('email_verification_required', true)
            ->assertJsonMissing(['token']);

        $user = User::query()->where('email', 'new.member@example.com')->first();
        $this->assertNotNull($user);
        $this->assertNull($user->email_verified_at);
        $this->assertDatabaseHas('email_verification_tokens', [
            'email' => 'new.member@example.com',
        ]);
    }

    public function test_login_blocked_until_email_verified(): void
    {
        $user = User::factory()->unverified()->create([
            'email' => 'pending@example.com',
            'password' => 'password12',
            'role' => User::ROLE_USER,
        ]);

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password12',
        ])
            ->assertForbidden()
            ->assertJsonPath('email_verification_required', true);
    }

    public function test_verify_email_marks_verified_and_returns_token(): void
    {
        $user = User::factory()->unverified()->create([
            'email' => 'verify.me@example.com',
            'password' => 'password12',
            'role' => User::ROLE_USER,
        ]);

        $plain = app(EmailVerificationService::class)->createToken($user);

        $response = $this->postJson('/api/auth/verify-email', [
            'email' => $user->email,
            'token' => $plain,
        ]);

        $response->assertOk()
            ->assertJsonStructure(['token', 'user']);

        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertDatabaseMissing('email_verification_tokens', [
            'email' => 'verify.me@example.com',
        ]);
    }

    public function test_verify_email_rejects_invalid_token(): void
    {
        $user = User::factory()->unverified()->create([
            'email' => 'bad.token@example.com',
            'role' => User::ROLE_USER,
        ]);

        app(EmailVerificationService::class)->createToken($user);

        $this->postJson('/api/auth/verify-email', [
            'email' => $user->email,
            'token' => 'not-the-real-token',
        ])->assertStatus(422);
    }

    public function test_resend_verification_throttles_and_reissues(): void
    {
        $user = User::factory()->unverified()->create([
            'email' => 'resend@example.com',
            'role' => User::ROLE_USER,
        ]);

        $service = app(EmailVerificationService::class);
        $first = $service->createToken($user);

        $this->postJson('/api/auth/resend-verification', [
            'email' => $user->email,
        ])->assertStatus(429);

        DB::table('email_verification_tokens')
            ->where('email', $user->email)
            ->update(['created_at' => now()->subMinutes(2)]);

        $this->postJson('/api/auth/resend-verification', [
            'email' => $user->email,
        ])->assertOk();

        $row = DB::table('email_verification_tokens')->where('email', $user->email)->first();
        $this->assertNotNull($row);
        $this->assertFalse(Hash::check($first, $row->token));
    }

    public function test_verified_user_can_login(): void
    {
        $user = User::factory()->create([
            'email' => 'ready@example.com',
            'password' => 'password12',
            'role' => User::ROLE_USER,
            'email_verified_at' => now(),
        ]);

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password12',
        ])
            ->assertOk()
            ->assertJsonStructure(['token', 'user']);
    }
}
