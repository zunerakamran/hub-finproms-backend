<?php

namespace Tests\Feature;

use App\Models\GdprIncident;
use App\Models\Hub;
use App\Models\User;
use App\Services\HubService;
use App\Support\CookieNoticeDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GdprPhase5And6Test extends TestCase
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
    }

    public function test_hub_payload_includes_essential_cookie_notice(): void
    {
        $this->getJson('/api/hub')
            ->assertOk()
            ->assertJsonPath('hub.auth.cookies.essential_only', true)
            ->assertJsonPath('hub.auth.cookies.version', 1);

        $this->assertStringContainsString('essential cookies', CookieNoticeDefaults::content());
    }

    public function test_admin_can_log_and_update_incident(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_FINPROMS_ADMIN,
            'email_verified_at' => now(),
        ]);
        Sanctum::actingAs($admin);

        $create = $this->postJson('/api/client-admin/gdpr/incidents', [
            'title' => 'Suspected mailbox compromise',
            'summary' => 'Staff reported unexpected password reset emails.',
            'severity' => GdprIncident::SEVERITY_MEDIUM,
            'status' => GdprIncident::STATUS_INVESTIGATING,
            'discovered_at' => now()->toIso8601String(),
        ])->assertCreated();

        $id = (int) $create->json('incident.id');
        $this->assertGreaterThan(0, $id);

        $this->putJson('/api/client-admin/gdpr/incidents/'.$id, [
            'status' => GdprIncident::STATUS_CONTAINED,
            'ico_notified' => true,
            'actions_taken' => 'Reset credentials; reviewed access logs.',
        ])
            ->assertOk()
            ->assertJsonPath('incident.status', GdprIncident::STATUS_CONTAINED)
            ->assertJsonPath('incident.ico_notified', true);

        $this->getJson('/api/client-admin/gdpr/incidents')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonStructure(['runbook' => ['steps', 'ico_url', 'sar_owners']]);
    }
}
