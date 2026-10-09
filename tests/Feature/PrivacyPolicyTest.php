<?php

namespace Tests\Feature;

use App\Models\Hub;
use App\Models\User;
use App\Services\HubService;
use App\Support\PrivacyPolicyDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PrivacyPolicyTest extends TestCase
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
            'privacy_policy' => [
                'content' => PrivacyPolicyDefaults::shared(),
                'version' => 1,
                'updated_at' => now()->toIso8601String(),
            ],
        ]);
        app(HubService::class)->forgetCurrentCache();
    }

    public function test_hub_payload_includes_privacy(): void
    {
        $this->getJson('/api/hub')
            ->assertOk()
            ->assertJsonPath('hub.auth.privacy.required', true)
            ->assertJsonPath('hub.auth.privacy.version', 1);
    }

    public function test_user_must_accept_privacy_and_acceptance_is_stored(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_FINPROMS_ADMIN,
            'email_verified_at' => now(),
        ]);
        Sanctum::actingAs($admin);

        $me = $this->getJson('/api/auth/me')->assertOk();
        $this->assertFalse((bool) $me->json('user.privacy_accepted'));

        $this->postJson('/api/auth/accept-privacy')
            ->assertOk()
            ->assertJsonPath('user.privacy_accepted', true);

        $admin->refresh();
        $this->assertNotNull($admin->privacy_accepted_at);
        $this->assertSame(1, (int) $admin->privacy_accepted_version);
    }

    public function test_capable_role_can_update_privacy_and_bumps_version(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_FINPROMS_ADMIN,
            'email_verified_at' => now(),
            'privacy_accepted_at' => now(),
            'privacy_accepted_version' => 1,
        ]);
        Sanctum::actingAs($admin);

        $this->putJson('/api/client-admin/privacy', [
            'content' => '<p>Updated shared hub privacy policy for testing.</p>',
        ])
            ->assertOk()
            ->assertJsonPath('privacy.version', 2);

        $me = $this->getJson('/api/auth/me')->assertOk();
        $this->assertFalse((bool) $me->json('user.privacy_accepted'));
    }

    public function test_default_content_differs_by_hub_type(): void
    {
        $this->assertStringContainsString('Shared Hub', PrivacyPolicyDefaults::shared());
        $this->assertStringContainsString('White-labelled', PrivacyPolicyDefaults::whiteLabel());
        $this->assertStringContainsString('Central Hub Controller', PrivacyPolicyDefaults::central());
        $this->assertStringContainsString('UK GDPR', PrivacyPolicyDefaults::shared());
    }
}
