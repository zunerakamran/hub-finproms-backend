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
        $hub = Hub::query()->create([
            'name' => 'Shared Hub',
            'slug' => 'shared-st',
            'type' => Hub::TYPE_SHARED,
            'is_active' => true,
            'checklist' => array_merge(Hub::defaultChecklist(Hub::TYPE_SHARED), [
                'module_support_tickets' => true,
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
        $caps[User::ROLE_USER]['st_comment_on_tickets'] = true;

        $hub->role_capabilities = $caps;
        $hub->save();
        app(HubService::class)->forgetCurrentCache();

        return $hub;
    }

    public function test_capability_keys_are_registered(): void
    {
        $this->assertContains('st_submit_ticket', Hub::SUPPORT_TICKETS_CAPABILITY_KEYS);
        $this->assertContains('module_support_tickets', Hub::MODULE_KEYS);
        $this->assertArrayHasKey('st_change_ticket_status', Hub::CHECKLIST_DEFINITIONS);
        $this->assertArrayHasKey('module_support_tickets', Hub::CHECKLIST_DEFINITIONS);
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
            ->assertJsonFragment(['id' => $ticket->id]);
    }

    public function test_submit_blocked_when_module_off(): void
    {
        Hub::query()->create([
            'name' => 'Shared Hub Off',
            'slug' => 'shared-st-off',
            'type' => Hub::TYPE_SHARED,
            'is_active' => true,
            'checklist' => array_merge(Hub::defaultChecklist(Hub::TYPE_SHARED), [
                'module_support_tickets' => false,
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
