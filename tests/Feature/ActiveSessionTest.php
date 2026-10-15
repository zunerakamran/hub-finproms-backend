<?php

namespace Tests\Feature;

use App\Models\Hub;
use App\Models\User;
use App\Services\CapabilitiesMatrixService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ActiveSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_capabilities_matrix_includes_manage_active_sessions(): void
    {
        $hub = $this->createSharedHub();
        $admin = User::factory()->powerAdmin()->create();
        Sanctum::actingAs($admin);

        $this->getJson('/api/power-admin/capabilities/matrix?hub_id='.$hub->id)
            ->assertOk()
            ->assertJsonFragment([
                'key' => 'dashboard_manage_active_sessions',
                'label' => 'Manage active sessions',
            ]);
    }

    public function test_authorized_role_can_list_and_force_logout(): void
    {
        $this->createSharedHub();
        $admin = User::factory()->powerAdmin()->create();
        $member = User::factory()->create([
            'role' => User::ROLE_USER,
            'email' => 'member@example.com',
        ]);

        $memberToken = $member->createToken('auth_token');
        $this->assertDatabaseHas('personal_access_tokens', [
            'id' => $memberToken->accessToken->id,
            'tokenable_id' => $member->id,
        ]);

        Sanctum::actingAs($admin);

        $list = $this->getJson('/api/client-admin/active-sessions');
        $list->assertOk()
            ->assertJsonPath('meta.total_users', 1)
            ->assertJsonPath('data.0.user.email', 'member@example.com')
            ->assertJsonPath('data.0.session_count', 1);

        $logout = $this->postJson('/api/client-admin/active-sessions/'.$member->id.'/force-logout');
        $logout->assertOk()
            ->assertJsonPath('tokens_revoked', 1)
            ->assertJsonPath('logged_out_self', false);

        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $member->id,
            'tokenable_type' => User::class,
        ]);
    }

    public function test_role_without_capability_cannot_manage_active_sessions(): void
    {
        $hub = $this->createSharedHub();
        $matrix = app(CapabilitiesMatrixService::class);
        $roleCaps = $matrix->resolvedRoleCapabilities($hub);
        $roleCaps[User::ROLE_USER]['dashboard_manage_active_sessions'] = false;
        $hub->role_capabilities = $roleCaps;
        $hub->save();

        $user = User::factory()->create(['role' => User::ROLE_USER]);
        Sanctum::actingAs($user);

        $this->getJson('/api/client-admin/active-sessions')
            ->assertForbidden()
            ->assertJsonPath('capability', 'dashboard_manage_active_sessions');
    }

    public function test_authorized_role_can_get_and_update_session_timeout(): void
    {
        $hub = $this->createSharedHub();
        $admin = User::factory()->powerAdmin()->create();
        $member = User::factory()->create([
            'role' => User::ROLE_USER,
            'email' => 'timeout-member@example.com',
        ]);
        $memberToken = $member->createToken('auth_token');
        $adminToken = $admin->createToken('auth_token');

        Sanctum::actingAs($admin);

        $this->getJson('/api/client-admin/session-settings')
            ->assertOk()
            ->assertJsonPath('data.session_lifetime_minutes', (int) config('session.lifetime', 10080))
            ->assertJsonPath('data.is_custom', false)
            ->assertJsonPath('hub.slug', $hub->slug);

        $this->putJson('/api/client-admin/session-settings', [
            'session_lifetime_minutes' => 60,
        ])
            ->assertOk()
            ->assertJsonPath('data.session_lifetime_minutes', 60)
            ->assertJsonPath('data.is_custom', true)
            ->assertJsonPath('logged_out_self', true)
            ->assertJsonPath('tokens_revoked', 2);

        $this->assertDatabaseHas('hubs', [
            'id' => $hub->id,
            'session_lifetime_minutes' => 60,
        ]);

        $this->assertSame(60, $hub->fresh()->resolvedSessionLifetimeMinutes());
        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $memberToken->accessToken->id,
        ]);
        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $adminToken->accessToken->id,
        ]);
    }

    public function test_session_timeout_rejects_out_of_range_values(): void
    {
        $this->createSharedHub();
        $admin = User::factory()->powerAdmin()->create();
        Sanctum::actingAs($admin);

        $this->putJson('/api/client-admin/session-settings', [
            'session_lifetime_minutes' => 5,
        ])->assertStatus(422);

        $this->putJson('/api/client-admin/session-settings', [
            'session_lifetime_minutes' => 999999,
        ])->assertStatus(422);
    }

    public function test_role_without_capability_cannot_update_session_timeout(): void
    {
        $hub = $this->createSharedHub();
        $matrix = app(CapabilitiesMatrixService::class);
        $roleCaps = $matrix->resolvedRoleCapabilities($hub);
        $roleCaps[User::ROLE_USER]['dashboard_manage_active_sessions'] = false;
        $hub->role_capabilities = $roleCaps;
        $hub->save();

        $user = User::factory()->create(['role' => User::ROLE_USER]);
        Sanctum::actingAs($user);

        $this->putJson('/api/client-admin/session-settings', [
            'session_lifetime_minutes' => 120,
        ])->assertForbidden();
    }

    private function createSharedHub(array $extra = []): Hub
    {
        $checklist = Hub::defaultChecklist(Hub::TYPE_SHARED);
        if (isset($extra['checklist']) && is_array($extra['checklist'])) {
            $checklist = array_merge($checklist, $extra['checklist']);
            unset($extra['checklist']);
        }

        return Hub::query()->create(array_merge([
            'name' => 'Shared Hub',
            'slug' => 'shared',
            'type' => Hub::TYPE_SHARED,
            'is_active' => true,
            'checklist' => $checklist,
        ], $extra));
    }
}
