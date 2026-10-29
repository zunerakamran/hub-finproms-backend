<?php

namespace Tests\Feature;

use App\Models\Hub;
use App\Models\User;
use App\Services\CapabilitiesMatrixService;
use App\Services\HubService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LiveChatTest extends TestCase
{
    use RefreshDatabase;

    private function createHubWithLiveChat(string $type = Hub::TYPE_SHARED): Hub
    {
        $slug = (string) config('hub.current_slug', 'shared');

        $hub = Hub::query()->updateOrCreate(
            ['slug' => $slug],
            [
                'name' => 'Shared Hub',
                'type' => $type,
                'is_active' => true,
                'checklist' => array_merge(Hub::defaultChecklist($type), [
                    'live_chat' => true,
                ]),
            ]
        );

        $matrix = app(CapabilitiesMatrixService::class);
        $caps = $matrix->resolvedRoleCapabilities($hub);
        foreach (Hub::LIVE_CHAT_CAPABILITY_KEYS as $key) {
            $caps[User::ROLE_USER][$key] = $key === 'lc_use_chat';
            $caps[User::ROLE_MANAGER][$key] = true;
            $caps[User::ROLE_POWER_ADMIN][$key] = true;
            $caps[User::ROLE_CLIENT_ADMIN][$key] = true;
        }
        $hub->checklist = array_merge($hub->resolvedChecklist(), ['live_chat' => true]);
        $hub->role_capabilities = $caps;
        $hub->save();
        app(HubService::class)->forgetCurrentCache();

        return $hub->fresh();
    }

    public function test_hub_user_can_start_conversation_with_synced_agent(): void
    {
        $this->createHubWithLiveChat();

        $hub = Hub::query()->first();
        $hub->live_chat_agents = [
            ['id' => 42, 'name' => 'Central Agent', 'email' => 'agent@central.test'],
        ];
        $hub->save();
        app(HubService::class)->forgetCurrentCache();

        $user = User::factory()->create(['role' => User::ROLE_USER]);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/live-chat/conversations', [
            'central_user_id' => 42,
            'message' => 'Hello from the hub',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.central_user_id', 42)
            ->assertJsonPath('data.messages.0.body', 'Hello from the hub');

        $this->assertDatabaseHas('live_chat_conversations', [
            'hub_user_id' => $user->id,
            'central_user_id' => 42,
        ]);

        $this->getJson('/api/live-chat/conversations')
            ->assertOk()
            ->assertJsonPath('data.0.central_user_id', 42);
    }

    public function test_live_chat_blocked_when_functionality_off(): void
    {
        $hub = $this->createHubWithLiveChat();
        $checklist = $hub->resolvedChecklist();
        $checklist['live_chat'] = false;
        $hub->checklist = $checklist;
        $hub->save();
        app(HubService::class)->forgetCurrentCache();

        $user = User::factory()->create(['role' => User::ROLE_USER]);
        Sanctum::actingAs($user);

        $this->postJson('/api/live-chat/conversations', [
            'central_user_id' => 1,
            'message' => 'Nope',
        ])->assertStatus(403);
    }

    public function test_cannot_start_with_unknown_agent(): void
    {
        $this->createHubWithLiveChat();
        $hub = Hub::query()->first();
        $hub->live_chat_agents = [];
        $hub->save();
        app(HubService::class)->forgetCurrentCache();

        $user = User::factory()->create(['role' => User::ROLE_USER]);
        Sanctum::actingAs($user);

        $this->postJson('/api/live-chat/conversations', [
            'central_user_id' => 99,
            'message' => 'Hello',
        ])->assertStatus(422);
    }

    public function test_options_include_default_support_email_on_content_hub(): void
    {
        $this->createHubWithLiveChat();
        $user = User::factory()->create(['role' => User::ROLE_USER]);
        Sanctum::actingAs($user);

        $this->getJson('/api/live-chat/options')
            ->assertOk()
            ->assertJsonPath(
                'support_email',
                \App\Services\LiveChatService::DEFAULT_SUPPORT_EMAIL
            );
    }

    public function test_central_use_live_chat_capability_is_inactive_and_agents_unlock_via_contacts(): void
    {
        config(['hub.is_control_plane' => true, 'hub.type' => Hub::TYPE_CENTRAL]);

        $matrix = app(CapabilitiesMatrixService::class);
        $central = Hub::query()->updateOrCreate(
            ['slug' => 'central'],
            [
                'name' => 'Central Hub',
                'type' => Hub::TYPE_CENTRAL,
                'is_active' => true,
                'checklist' => Hub::defaultChecklist(Hub::TYPE_CENTRAL),
                'role_capabilities' => $matrix->defaultRoleCapabilities(Hub::TYPE_CENTRAL),
            ]
        );
        app(HubService::class)->forgetCurrentCache();

        $rows = collect($matrix->matrix($central)['rows'] ?? []);
        $useChat = $rows->firstWhere('key', 'lc_use_chat');
        $this->assertNotNull($useChat);
        $this->assertTrue((bool) ($useChat['inactive'] ?? false));
        $this->assertSame('content_hubs_only', $useChat['inactive_reason'] ?? null);

        $shared = Hub::query()->updateOrCreate(
            ['slug' => 'shared-lc'],
            [
                'name' => 'Shared LC',
                'type' => Hub::TYPE_SHARED,
                'is_active' => true,
                'checklist' => array_merge(Hub::defaultChecklist(Hub::TYPE_SHARED), [
                    'live_chat' => true,
                ]),
            ]
        );

        $agent = User::factory()->create([
            'role' => User::ROLE_USER,
            'name' => 'Central Agent',
            'email' => 'agent-lc@central.test',
        ]);
        $outsider = User::factory()->create([
            'role' => User::ROLE_USER,
            'name' => 'Not An Agent',
            'email' => 'outsider-lc@central.test',
        ]);

        $shared->live_chat_agents = [
            ['id' => $agent->id, 'name' => $agent->name, 'email' => $agent->email],
        ];
        $shared->save();

        $chat = app(\App\Services\LiveChatService::class);
        $this->assertTrue($chat->centralUserMayUseChat($agent));
        $this->assertFalse($chat->centralUserMayUseChat($outsider));
        $this->assertTrue($matrix->userCan($central, $agent, 'lc_use_chat'));
        $this->assertFalse($matrix->userCan($central, $outsider, 'lc_use_chat'));
    }
}
