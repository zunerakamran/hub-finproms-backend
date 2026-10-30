<?php

namespace Tests\Feature;

use App\Models\Hub;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\CapabilitiesMatrixService;
use App\Services\HubService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SupportTicketsTest extends TestCase
{
    use RefreshDatabase;

    private function createHubWithSupportTickets(): Hub
    {
        config([
            'hub.current_slug' => 'shared-st',
            'hub.type' => Hub::TYPE_SHARED,
            'hub.is_control_plane' => false,
        ]);

        $hub = Hub::query()->create([
            'name' => 'Shared Hub',
            'slug' => 'shared-st',
            'type' => Hub::TYPE_SHARED,
            'is_active' => true,
            'checklist' => array_merge(Hub::defaultChecklist(Hub::TYPE_SHARED), [
                'support_tickets' => true,
            ]),
        ]);

        $matrix = app(CapabilitiesMatrixService::class);
        $caps = $matrix->resolvedRoleCapabilities($hub);

        foreach (Hub::SUPPORT_TICKETS_CAPABILITY_KEYS as $key) {
            $caps[User::ROLE_USER][$key] = false;
            $caps[User::ROLE_FINPROMS_ADMIN][$key] = true;
            $caps[User::ROLE_MANAGER][$key] = true;
            $caps[User::ROLE_POWER_ADMIN][$key] = true;
        }

        $caps[User::ROLE_USER]['st_submit_ticket'] = true;
        $caps[User::ROLE_USER]['st_view_own_tickets'] = true;
        $caps[User::ROLE_FINPROMS_ADMIN]['st_reply_to_tickets'] = true;
        $caps[User::ROLE_MANAGER]['st_reply_to_tickets'] = true;

        $hub->role_capabilities = $caps;
        $hub->save();
        app(HubService::class)->forgetCurrentCache();

        return $hub;
    }

    public function test_capability_keys_are_registered(): void
    {
        $this->assertContains('st_submit_ticket', Hub::SUPPORT_TICKETS_CAPABILITY_KEYS);
        $this->assertContains('st_reply_to_tickets', Hub::SUPPORT_TICKETS_CAPABILITY_KEYS);
        $this->assertContains('st_view_all_tickets', Hub::SUPPORT_TICKETS_CAPABILITY_KEYS);
        $this->assertNotContains('st_change_ticket_status', Hub::SUPPORT_TICKETS_CAPABILITY_KEYS);
        $this->assertNotContains('st_comment_on_tickets', Hub::SUPPORT_TICKETS_CAPABILITY_KEYS);
        $this->assertNotContains('module_support_tickets', Hub::MODULE_KEYS);
        $this->assertArrayNotHasKey('st_change_ticket_status', Hub::CHECKLIST_DEFINITIONS);
        $this->assertArrayNotHasKey('st_comment_on_tickets', Hub::CHECKLIST_DEFINITIONS);
        $this->assertArrayHasKey('st_reply_to_tickets', Hub::CHECKLIST_DEFINITIONS);
        $this->assertArrayHasKey('support_tickets', Hub::CHECKLIST_DEFINITIONS);
        $this->assertSame(Hub::GROUP_BEHAVIOUR, Hub::CHECKLIST_DEFINITIONS['support_tickets']['group']);
    }

    public function test_view_all_alone_cannot_reply(): void
    {
        $hub = $this->createHubWithSupportTickets();
        $caps = $hub->role_capabilities;
        $caps[User::ROLE_MANAGER]['st_reply_to_tickets'] = false;
        $caps[User::ROLE_MANAGER]['st_view_all_tickets'] = true;
        $hub->role_capabilities = $caps;
        $hub->save();
        app(HubService::class)->forgetCurrentCache();

        $user = User::factory()->create(['role' => User::ROLE_USER]);
        $viewer = User::factory()->create(['role' => User::ROLE_MANAGER]);
        $ticket = SupportTicket::query()->create([
            'user_id' => $user->id,
            'subject' => 'View only',
            'module_area' => 'dashboard',
            'category' => 'bug',
            'priority' => 'low',
            'description' => 'Read-only viewer test.',
            'status' => SupportTicket::STATUS_OPEN,
        ]);

        Sanctum::actingAs($viewer);

        $this->getJson('/api/client-admin/support-tickets')
            ->assertOk()
            ->assertJsonFragment(['id' => $ticket->id]);

        $this->post('/api/client-admin/support-tickets/'.$ticket->id.'/reply', [
            'status' => SupportTicket::STATUS_IN_PROGRESS,
            'remarks' => '<p>Should be forbidden.</p>',
        ])->assertStatus(403);
    }

    public function test_user_can_submit_and_view_own_ticket(): void
    {
        Storage::fake('public');
        $this->createHubWithSupportTickets();

        $user = User::factory()->create(['role' => User::ROLE_USER]);
        Sanctum::actingAs($user);

        $response = $this->post('/api/support-tickets', [
            'subject' => 'Login button broken',
            'module_area' => 'authentication',
            'category' => 'bug',
            'priority' => 'high',
            'description' => '<p>The login button does nothing.</p>',
            'page_url' => 'https://example.test/login',
            'screenshots' => [
                UploadedFile::fake()->image('error.png', 100, 100),
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.subject', 'Login button broken')
            ->assertJsonPath('data.status', SupportTicket::STATUS_OPEN)
            ->assertJsonPath('data.module_area', 'authentication');

        $ticketId = $response->json('data.id');
        $this->assertNotEmpty($response->json('data.attachments'));

        $this->getJson('/api/support-tickets/mine')
            ->assertOk()
            ->assertJsonPath('data.0.id', $ticketId);

        $this->getJson('/api/support-tickets/'.$ticketId)
            ->assertOk()
            ->assertJsonPath('data.id', $ticketId);
    }

    public function test_admin_can_change_ticket_status(): void
    {
        Storage::fake('public');
        $this->createHubWithSupportTickets();

        $user = User::factory()->create(['role' => User::ROLE_USER]);
        $admin = User::factory()->create(['role' => User::ROLE_FINPROMS_ADMIN]);

        $ticket = SupportTicket::query()->create([
            'user_id' => $user->id,
            'subject' => 'Credits missing',
            'module_area' => 'purchases_credits',
            'category' => 'data',
            'priority' => 'medium',
            'description' => 'Credits did not update after purchase.',
            'status' => SupportTicket::STATUS_OPEN,
        ]);

        Sanctum::actingAs($admin);

        $this->post('/api/client-admin/support-tickets/'.$ticket->id.'/change-status', [
            'status' => SupportTicket::STATUS_IN_PROGRESS,
            'comment' => 'Looking into this now.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', SupportTicket::STATUS_IN_PROGRESS)
            ->assertJsonPath('data.status_note', 'Looking into this now.');

        $this->getJson('/api/client-admin/support-tickets')
            ->assertOk()
            ->assertJsonFragment(['id' => $ticket->id])
            ->assertJsonPath('data.0.submitter.profile_detailed', true)
            ->assertJsonPath('data.0.submitter.email', $user->email);

        $this->getJson('/api/client-admin/support-tickets/'.$ticket->id)
            ->assertOk()
            ->assertJsonPath('data.submitter.profile_detailed', true)
            ->assertJsonPath('data.submitter.name', $user->name)
            ->assertJsonStructure([
                'data' => [
                    'submitter' => [
                        'id',
                        'name',
                        'email',
                        'roles',
                        'role_labels',
                        'modules',
                        'firm',
                        'last_login_at',
                        'profile_detailed',
                    ],
                ],
            ]);
    }

    public function test_admin_can_reply_with_status_eta_and_remarks(): void
    {
        Storage::fake('public');
        $this->createHubWithSupportTickets();

        $user = User::factory()->create(['role' => User::ROLE_USER]);
        $admin = User::factory()->create(['role' => User::ROLE_FINPROMS_ADMIN]);

        $ticket = SupportTicket::query()->create([
            'user_id' => $user->id,
            'subject' => 'Broken download',
            'module_area' => 'content_catalog',
            'category' => 'bug',
            'priority' => 'high',
            'description' => 'Download button fails.',
            'status' => SupportTicket::STATUS_OPEN,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->post('/api/client-admin/support-tickets/'.$ticket->id.'/reply', [
            'status' => SupportTicket::STATUS_WAITING_ON_USER,
            'remarks' => '<p>Please clear cache and try again.</p>',
            'estimated_hours' => 4,
            'attachments' => [
                UploadedFile::fake()->create('guide.pdf', 100, 'application/pdf'),
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', SupportTicket::STATUS_WAITING_ON_USER)
            ->assertJsonPath('data.status_note', '<p>Please clear cache and try again.</p>');

        $this->assertNotNull($response->json('data.estimated_resolve_at'));
        $this->assertNotEmpty($response->json('data.comments'));
        $latest = collect($response->json('data.comments'))->last();
        $this->assertSame(SupportTicket::STATUS_WAITING_ON_USER, $latest['to_status'] ?? null);
        $this->assertNotEmpty($latest['attachments'] ?? []);
    }

    public function test_own_ticket_view_keeps_basic_submitter_without_profile(): void
    {
        $this->createHubWithSupportTickets();

        $user = User::factory()->create(['role' => User::ROLE_USER]);
        Sanctum::actingAs($user);

        $ticket = SupportTicket::query()->create([
            'user_id' => $user->id,
            'subject' => 'My own issue',
            'module_area' => 'dashboard',
            'category' => 'bug',
            'priority' => 'low',
            'description' => 'Something broke for me.',
            'status' => SupportTicket::STATUS_OPEN,
        ]);

        $this->getJson('/api/support-tickets/'.$ticket->id)
            ->assertOk()
            ->assertJsonPath('data.submitter.profile_detailed', false)
            ->assertJsonPath('data.submitter.email', $user->email)
            ->assertJsonMissingPath('data.submitter.modules');
    }

    public function test_submit_blocked_when_functionality_off(): void
    {
        config([
            'hub.current_slug' => 'shared-st-off',
            'hub.type' => Hub::TYPE_SHARED,
            'hub.is_control_plane' => false,
        ]);

        Hub::query()->create([
            'name' => 'Shared Hub Off',
            'slug' => 'shared-st-off',
            'type' => Hub::TYPE_SHARED,
            'is_active' => true,
            'checklist' => array_merge(Hub::defaultChecklist(Hub::TYPE_SHARED), [
                'support_tickets' => false,
            ]),
        ]);
        app(HubService::class)->forgetCurrentCache();

        $user = User::factory()->create(['role' => User::ROLE_USER]);
        Sanctum::actingAs($user);

        $this->postJson('/api/support-tickets', [
            'subject' => 'Should fail',
            'module_area' => 'dashboard',
            'description' => 'Nope',
        ])->assertStatus(403);
    }
}
