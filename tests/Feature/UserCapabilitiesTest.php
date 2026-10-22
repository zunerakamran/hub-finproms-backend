<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Hub;
use App\Models\User;
use App\Services\CapabilitiesMatrixService;
use App\Services\HubService;
use App\Services\UserCapabilitiesService;
use App\Services\UserRolesService;
use App\Support\TermsAndConditionsDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserCapabilitiesTest extends TestCase
{
    use RefreshDatabase;

    private Hub $hub;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hub = Hub::query()->create([
            'name' => 'Shared Hub',
            'slug' => 'shared',
            'type' => Hub::TYPE_SHARED,
            'is_active' => true,
            'checklist' => Hub::defaultChecklist(Hub::TYPE_SHARED),
            'role_capabilities' => [
                User::ROLE_MANAGER => [
                    'dashboard_view_hub_users' => true,
                    'dashboard_view_activity_logs' => true,
                    'dashboard_manage_user_capabilities' => true,
                    // Only Approver grants sessions — used to test override prune on role remove.
                    'dashboard_manage_active_sessions' => false,
                ],
                User::ROLE_APPROVER => [
                    'dashboard_view_hub_users' => false,
                    'dashboard_view_activity_logs' => false,
                    'dashboard_manage_active_sessions' => true,
                ],
                User::ROLE_USER => [
                    'member_browse_catalog' => true,
                    'dashboard_view_hub_users' => false,
                    'dashboard_manage_user_capabilities' => false,
                ],
            ],
            'terms_and_conditions' => [
                'content' => TermsAndConditionsDefaults::shared(),
                'version' => 1,
                'updated_at' => now()->toIso8601String(),
            ],
        ]);
        app(HubService::class)->forgetCurrentCache();
        app(CapabilitiesMatrixService::class)->forgetResolvedCaches();
    }

    public function test_user_can_respects_per_user_denylist_within_role_union(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_MANAGER]);
        app(UserRolesService::class)->syncRoles(
            $user,
            [User::ROLE_MANAGER, User::ROLE_APPROVER],
            User::ROLE_MANAGER,
            log: false,
        );

        $matrix = app(CapabilitiesMatrixService::class);
        $this->assertTrue($matrix->userCan($this->hub, $user, 'dashboard_view_hub_users'));
        $this->assertTrue($matrix->userCan($this->hub, $user, 'dashboard_manage_active_sessions'));

        $user->forceFill([
            'capability_overrides' => [
                'dashboard_view_hub_users' => false,
            ],
        ])->save();

        $this->assertFalse($matrix->userCan($this->hub, $user->fresh(), 'dashboard_view_hub_users'));
        $this->assertTrue($matrix->userCan($this->hub, $user->fresh(), 'dashboard_manage_active_sessions'));
    }

    public function test_cannot_grant_capability_beyond_assigned_roles(): void
    {
        $actor = User::factory()->create(['role' => User::ROLE_MANAGER]);
        app(UserRolesService::class)->syncRoles(
            $actor,
            [User::ROLE_MANAGER],
            User::ROLE_MANAGER,
            log: false,
        );
        $target = User::factory()->create(['role' => User::ROLE_USER]);

        Sanctum::actingAs($actor);

        $this->putJson('/api/client-admin/user-capabilities/'.$target->id, [
            'capabilities' => [
                'dashboard_view_hub_users' => true,
            ],
        ])->assertStatus(422);
    }

    public function test_update_logs_enable_and_disable_and_matrix_only_shows_role_granted(): void
    {
        $actor = User::factory()->create(['role' => User::ROLE_MANAGER]);
        app(UserRolesService::class)->syncRoles(
            $actor,
            [User::ROLE_MANAGER],
            User::ROLE_MANAGER,
            log: false,
        );

        $target = User::factory()->create(['role' => User::ROLE_MANAGER]);
        app(UserRolesService::class)->syncRoles(
            $target,
            [User::ROLE_MANAGER, User::ROLE_APPROVER],
            User::ROLE_MANAGER,
            log: false,
        );

        Sanctum::actingAs($actor);

        $show = $this->getJson('/api/client-admin/user-capabilities/'.$target->id)
            ->assertOk()
            ->json();

        $keys = collect($show['rows'])->pluck('key')->all();
        $this->assertContains('dashboard_view_hub_users', $keys);
        $this->assertContains('dashboard_manage_active_sessions', $keys);
        $granted = app(UserCapabilitiesService::class)->roleGrantedKeys($this->hub, $target);
        $this->assertSame(collect($granted)->sort()->values()->all(), collect($keys)->sort()->values()->all());

        $sections = collect($show['role_sections'] ?? []);
        $this->assertSame(
            [User::ROLE_MANAGER, User::ROLE_APPROVER],
            $sections->pluck('role')->all()
        );
        $managerKeys = $sections->firstWhere('role', User::ROLE_MANAGER)['rows'] ?? [];
        $approverKeys = $sections->firstWhere('role', User::ROLE_APPROVER)['rows'] ?? [];
        $this->assertTrue(collect($managerKeys)->contains(fn ($row) => $row['key'] === 'dashboard_view_hub_users'));
        $this->assertTrue(collect($approverKeys)->contains(fn ($row) => $row['key'] === 'dashboard_manage_active_sessions'));
        $this->assertFalse(collect($managerKeys)->contains(fn ($row) => $row['key'] === 'dashboard_manage_active_sessions'));

        $capabilities = [];
        foreach ($show['rows'] as $row) {
            $capabilities[$row['key']] = $row['key'] !== 'dashboard_view_hub_users';
        }

        $this->putJson('/api/client-admin/user-capabilities/'.$target->id, [
            'capabilities' => $capabilities,
        ])->assertOk()
            ->assertJsonPath('disabled.0', 'dashboard_view_hub_users');

        $this->assertTrue(
            ActivityLog::query()
                ->where('action', 'user.capability_disabled')
                ->where('subject_id', $target->id)
                ->exists()
        );

        $capabilities['dashboard_view_hub_users'] = true;
        $this->putJson('/api/client-admin/user-capabilities/'.$target->id, [
            'capabilities' => $capabilities,
        ])->assertOk();

        $this->assertTrue(
            ActivityLog::query()
                ->where('action', 'user.capability_enabled')
                ->where('subject_id', $target->id)
                ->exists()
        );
    }

    public function test_role_matrix_update_writes_activity_log(): void
    {
        app(CapabilitiesMatrixService::class)->update($this->hub, [
            'matrix' => [
                User::ROLE_MANAGER => [
                    'dashboard_view_activity_logs' => false,
                ],
            ],
        ]);

        $this->assertTrue(
            ActivityLog::query()
                ->where('action', 'capabilities.role_matrix_updated')
                ->exists()
        );
    }

    public function test_page_requires_manage_user_capabilities(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_USER]);
        Sanctum::actingAs($user);

        $this->getJson('/api/client-admin/user-capabilities/1')
            ->assertForbidden();
    }

    public function test_prune_overrides_when_role_removed(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_MANAGER]);
        app(UserRolesService::class)->syncRoles(
            $user,
            [User::ROLE_MANAGER, User::ROLE_APPROVER],
            User::ROLE_MANAGER,
            log: false,
        );
        $user->forceFill([
            'capability_overrides' => [
                'dashboard_manage_active_sessions' => false,
                'dashboard_view_hub_users' => false,
            ],
        ])->save();

        app(UserRolesService::class)->syncRoles(
            $user,
            [User::ROLE_MANAGER],
            User::ROLE_MANAGER,
            log: false,
            hub: $this->hub,
        );

        $overrides = app(UserCapabilitiesService::class)->overrides($user->fresh());
        $this->assertArrayHasKey('dashboard_view_hub_users', $overrides);
        $this->assertArrayNotHasKey('dashboard_manage_active_sessions', $overrides);
    }
}
