<?php

namespace Tests\Feature;

use App\Models\Hub;
use App\Services\HubService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HubAccessModeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, bool>  $overrides
     */
    private function bootHub(string $type, array $overrides = []): Hub
    {
        $slug = $type === Hub::TYPE_WHITE_LABEL ? 'acme' : 'shared';
        config([
            'hub.current_slug' => $slug,
            'hub.type' => $type,
        ]);

        $hub = Hub::query()->create([
            'name' => $type === Hub::TYPE_WHITE_LABEL ? 'Acme' : 'Shared Hub',
            'slug' => $slug,
            'type' => $type,
            'is_active' => true,
            'checklist' => array_merge(Hub::defaultChecklist($type), $overrides),
        ]);

        app(HubService::class)->forgetCurrentCache();

        return $hub;
    }

    public function test_shared_defaults_to_public_access_and_shows_home_before_login(): void
    {
        $this->bootHub(Hub::TYPE_SHARED);

        $this->getJson('/api/hub')
            ->assertOk()
            ->assertJsonPath('hub.auth.public_access', true)
            ->assertJsonPath('hub.auth.private_access', false)
            ->assertJsonPath('hub.auth.home_before_login', true)
            ->assertJsonPath('hub.auth.registration_enabled', true)
            ->assertJsonPath('hub.auth.invite_only', false)
            ->assertJsonPath('hub.checklist.public_subscribe', true)
            ->assertJsonPath('hub.checklist.private_invite_only', false);
    }

    public function test_white_label_defaults_to_private_and_blocks_registration(): void
    {
        $this->bootHub(Hub::TYPE_WHITE_LABEL);

        $this->getJson('/api/hub')
            ->assertOk()
            ->assertJsonPath('hub.auth.public_access', false)
            ->assertJsonPath('hub.auth.private_access', true)
            ->assertJsonPath('hub.auth.home_before_login', false)
            ->assertJsonPath('hub.auth.registration_enabled', false)
            ->assertJsonPath('hub.auth.invite_only', true);

        $this->postJson('/api/auth/register', [
            'name' => 'Guest',
            'email' => 'guest@example.com',
            'password' => 'password12',
            'password_confirmation' => 'password12',
        ])->assertForbidden()
            ->assertJsonPath('registration_enabled', false);
    }

    public function test_shared_hub_can_be_made_private(): void
    {
        $hub = $this->bootHub(Hub::TYPE_SHARED);
        $merged = app(HubService::class)->mergeChecklist($hub, [
            'public_subscribe' => false,
        ]);

        $this->assertFalse($merged['public_subscribe']);
        $this->assertTrue($merged['private_invite_only']);

        $hub->checklist = $merged;
        $hub->save();
        app(HubService::class)->forgetCurrentCache();

        $this->getJson('/api/hub')
            ->assertOk()
            ->assertJsonPath('hub.auth.home_before_login', false)
            ->assertJsonPath('hub.auth.registration_enabled', false)
            ->assertJsonPath('hub.auth.private_access', true)
            ->assertJsonPath('hub.auth.public_access', false);

        $this->postJson('/api/auth/register', [
            'name' => 'Guest',
            'email' => 'guest@example.com',
            'password' => 'password12',
            'password_confirmation' => 'password12',
        ])->assertForbidden();
    }

    public function test_turning_private_off_on_white_label_enables_public_access(): void
    {
        $hub = $this->bootHub(Hub::TYPE_WHITE_LABEL);
        $merged = app(HubService::class)->mergeChecklist($hub, [
            'private_invite_only' => false,
        ]);

        $this->assertTrue($merged['public_subscribe']);
        $this->assertFalse($merged['private_invite_only']);

        $hub->checklist = $merged;
        $hub->save();
        app(HubService::class)->forgetCurrentCache();

        $this->getJson('/api/hub')
            ->assertOk()
            ->assertJsonPath('hub.auth.home_before_login', true)
            ->assertJsonPath('hub.auth.registration_enabled', true)
            ->assertJsonPath('hub.auth.public_access', true);
    }

    public function test_access_checkboxes_are_named_public_and_private(): void
    {
        $this->assertSame('Public access', Hub::CHECKLIST_DEFINITIONS['public_subscribe']['label']);
        $this->assertSame('Private access', Hub::CHECKLIST_DEFINITIONS['private_invite_only']['label']);
        $this->assertTrue(Hub::CHECKLIST_DEFINITIONS['public_subscribe']['default_shared']);
        $this->assertFalse(Hub::CHECKLIST_DEFINITIONS['public_subscribe']['default_white_label']);
        $this->assertTrue(Hub::CHECKLIST_DEFINITIONS['private_invite_only']['default_white_label']);
        $this->assertFalse(Hub::CHECKLIST_DEFINITIONS['private_invite_only']['default_shared']);
    }
}
