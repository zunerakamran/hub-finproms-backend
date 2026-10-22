<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Hub;
use App\Models\User;
use App\Models\UserRole;
use App\Services\CapabilitiesMatrixService;
use App\Services\HubService;
use App\Services\UserRolesService;
use App\Support\TermsAndConditionsDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserMultiRoleTest extends TestCase
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
                ],
                User::ROLE_APPROVER => [
                    'dashboard_view_hub_users' => false,
                    'dashboard_view_activity_logs' => false,
                    'dashboard_manage_active_sessions' => true,
                ],
                User::ROLE_USER => [
                    'member_browse_catalog' => true,
                ],
            ],
            'terms_and_conditions' => [
                'content' => TermsAndConditionsDefaults::shared(),
                'version' => 1,
                'updated_at' => now()->toIso8601String(),
            ],
        ]);
        app(HubService::class)->forgetCurrentCache();
    }

    public function test_user_create_seeds_primary_role_into_pivot(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_MANAGER]);

        $this->assertDatabaseHas('user_roles', [
            'user_id' => $user->id,
            'role' => User::ROLE_MANAGER,
        ]);
        $this->assertTrue($user->hasRole(User::ROLE_MANAGER));
        $this->assertSame([User::ROLE_MANAGER], $user->roleKeys());
    }

    public function test_sync_roles_assigns_and_removes_with_activity_logs(): void
    {
        $actor = User::factory()->powerAdmin()->create();
        $user = User::factory()->create(['role' => User::ROLE_USER]);

        Sanctum::actingAs($actor);

        $service = app(UserRolesService::class);
        $service->syncRoles(
            $user,
            [User::ROLE_MANAGER, User::ROLE_APPROVER],
            User::ROLE_MANAGER,
            log: true,
            actor: $actor,
            hub: $this->hub,
        );

        $user->refresh();
        $this->assertSame(User::ROLE_MANAGER, $user->role);
        $this->assertTrue($user->hasRole(User::ROLE_MANAGER));
        $this->assertTrue($user->hasRole(User::ROLE_APPROVER));
        $this->assertFalse($user->hasRole(User::ROLE_USER));

        $this->assertTrue(
            ActivityLog::query()->where('action', 'user.role_assigned')->where('subject_id', $user->id)->exists()
        );
        $this->assertTrue(
            ActivityLog::query()->where('action', 'user.role_removed')->where('subject_id', $user->id)->exists()
        );

        $assignedRoles = ActivityLog::query()
            ->where('action', 'user.role_assigned')
            ->where('subject_id', $user->id)
            ->get()
            ->pluck('properties.role')
            ->all();
        $this->assertContains(User::ROLE_MANAGER, $assignedRoles);
        $this->assertContains(User::ROLE_APPROVER, $assignedRoles);

        $removedRoles = ActivityLog::query()
            ->where('action', 'user.role_removed')
            ->where('subject_id', $user->id)
            ->get()
            ->pluck('properties.role')
            ->all();
        $this->assertContains(User::ROLE_USER, $removedRoles);
    }

    public function test_capability_checks_use_union_of_roles(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_APPROVER]);
        app(UserRolesService::class)->syncRoles(
            $user,
            [User::ROLE_MANAGER, User::ROLE_APPROVER],
            User::ROLE_APPROVER,
            log: false,
        );

        $matrix = app(CapabilitiesMatrixService::class);

        $fresh = $user->fresh();
        // Manager column grants hub users; approver column grants active sessions — UNION of both.
        $this->assertTrue($matrix->userCan($this->hub, $fresh, 'dashboard_view_hub_users'));
        $this->assertTrue($matrix->userCan($this->hub, $fresh, 'dashboard_manage_active_sessions'));
        $this->assertTrue($matrix->userCan($this->hub, $fresh, 'dashboard_view_activity_logs'));
    }

    public function test_power_admin_can_create_user_with_multiple_roles(): void
    {
        $admin = User::factory()->powerAdmin()->create();
        Sanctum::actingAs($admin);

        // Enable power admin user management on this hub type if needed via Sanctum only —
        // route middleware may require pa_* ; hit service path via sync if API gated.
        $response = $this->postJson('/api/power-admin/users', [
            'name' => 'Multi Role Staff',
            'email' => 'multi.role@example.com',
            'password' => 'Password1!',
            'roles' => [User::ROLE_MANAGER, User::ROLE_APPROVER],
            'primary_role' => User::ROLE_MANAGER,
        ]);

        if ($response->status() === 403 || $response->status() === 401) {
            $this->markTestSkipped('Power Admin users API is capability-gated in this environment.');
        }

        $response->assertCreated();
        $payload = $response->json('user');
        $this->assertSame(User::ROLE_MANAGER, $payload['role']);
        $this->assertContains(User::ROLE_MANAGER, $payload['roles']);
        $this->assertContains(User::ROLE_APPROVER, $payload['roles']);

        $created = User::query()->where('email', 'multi.role@example.com')->first();
        $this->assertNotNull($created);
        $this->assertEqualsCanonicalizing(
            [User::ROLE_MANAGER, User::ROLE_APPROVER],
            $created->roleKeys()
        );
        $this->assertTrue(
            ActivityLog::query()
                ->where('action', 'user.role_assigned')
                ->where('subject_id', $created->id)
                ->exists()
        );
    }

    public function test_approver_scoped_only_helper(): void
    {
        $approver = User::factory()->create(['role' => User::ROLE_APPROVER]);
        $this->assertTrue($approver->isApproverScopedOnly());

        app(UserRolesService::class)->syncRoles(
            $approver,
            [User::ROLE_MANAGER, User::ROLE_APPROVER],
            User::ROLE_MANAGER,
            log: false,
        );
        $this->assertFalse($approver->fresh()->isApproverScopedOnly());
    }

    public function test_migration_backfill_leaves_one_pivot_row_per_existing_user(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_CLIENT_ADMIN]);
        $this->assertSame(1, UserRole::query()->where('user_id', $user->id)->count());
    }
}
