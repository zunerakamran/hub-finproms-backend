<?php

namespace Tests\Feature;

use App\Models\Hub;
use App\Models\User;
use App\Services\HubService;
use App\Support\TermsAndConditionsDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TermsAndConditionsTest extends TestCase
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

    public function test_hub_payload_includes_terms(): void
    {
        $this->getJson('/api/hub')
            ->assertOk()
            ->assertJsonPath('hub.auth.terms.required', true)
            ->assertJsonPath('hub.auth.terms.version', 1);
    }

    public function test_user_must_accept_terms_and_acceptance_is_stored(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_FINPROMS_ADMIN,
            'email_verified_at' => now(),
        ]);
        Sanctum::actingAs($admin);

        $me = $this->getJson('/api/auth/me')->assertOk();
        $this->assertFalse((bool) $me->json('user.terms_accepted'));

        $this->postJson('/api/auth/accept-terms')
            ->assertOk()
            ->assertJsonPath('user.terms_accepted', true);

        $admin->refresh();
        $this->assertNotNull($admin->terms_accepted_at);
        $this->assertSame(1, (int) $admin->terms_accepted_version);
    }

    public function test_capable_role_can_update_terms_and_bumps_version(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_FINPROMS_ADMIN,
            'email_verified_at' => now(),
            'terms_accepted_at' => now(),
            'terms_accepted_version' => 1,
        ]);
        Sanctum::actingAs($admin);

        $this->putJson('/api/client-admin/terms', [
            'content' => '<p>Updated shared hub terms for testing.</p>',
        ])
            ->assertOk()
            ->assertJsonPath('terms.version', 2);

        $me = $this->getJson('/api/auth/me')->assertOk();
        $this->assertFalse((bool) $me->json('user.terms_accepted'));
    }

    public function test_default_content_differs_by_hub_type(): void
    {
        $this->assertStringContainsString('Shared Hub', TermsAndConditionsDefaults::shared());
        $this->assertStringContainsString('White-labelled', TermsAndConditionsDefaults::whiteLabel());
        $this->assertStringContainsString('Central Hub Controller', TermsAndConditionsDefaults::central());
    }
}
