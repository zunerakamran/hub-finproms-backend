<?php

namespace Tests\Feature;

use App\Models\Hub;
use App\Models\User;
use App\Services\HubService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UpdateProfileTest extends TestCase
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
        Storage::fake('public');
    }

    public function test_user_can_update_name(): void
    {
        $user = User::factory()->create([
            'name' => 'Old Name',
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)
            ->putJson('/api/auth/profile', ['name' => 'New Name'])
            ->assertOk()
            ->assertJsonPath('user.name', 'New Name');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'New Name',
        ]);
    }

    public function test_enabling_two_factor_requires_current_password(): void
    {
        $user = User::factory()->create([
            'password' => 'password12',
            'email_verified_at' => now(),
            'two_factor_enabled' => false,
        ]);

        $this->actingAs($user)
            ->putJson('/api/auth/profile', [
                'two_factor_enabled' => true,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['current_password']);

        $this->actingAs($user)
            ->putJson('/api/auth/profile', [
                'two_factor_enabled' => true,
                'current_password' => 'password12',
            ])
            ->assertOk()
            ->assertJsonPath('user.two_factor_enabled', true);

        $this->assertTrue((bool) $user->fresh()->two_factor_enabled);
    }

    public function test_email_change_requires_password_and_resets_verification(): void
    {
        $user = User::factory()->create([
            'email' => 'old@example.com',
            'password' => 'password12',
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)
            ->putJson('/api/auth/profile', [
                'email' => 'new@example.com',
                'current_password' => 'password12',
            ])
            ->assertOk()
            ->assertJsonPath('user.email', 'new@example.com')
            ->assertJsonPath('email_verification_required', true);

        $fresh = $user->fresh();
        $this->assertSame('new@example.com', $fresh->email);
        $this->assertNull($fresh->email_verified_at);
    }

    public function test_user_can_upload_and_remove_avatar(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $file = UploadedFile::fake()->image('avatar.jpg', 120, 120);

        $response = $this->actingAs($user)
            ->post('/api/auth/profile', [
                'avatar' => $file,
            ]);

        $response->assertOk();
        $path = $user->fresh()->avatar_path;
        $this->assertNotNull($path);
        Storage::disk('public')->assertExists($path);
        $this->assertNotNull($response->json('user.avatar_url'));

        $this->actingAs($user)
            ->putJson('/api/auth/profile', [
                'remove_avatar' => true,
            ])
            ->assertOk()
            ->assertJsonPath('user.avatar_url', null);

        $this->assertNull($user->fresh()->avatar_path);
    }
}
