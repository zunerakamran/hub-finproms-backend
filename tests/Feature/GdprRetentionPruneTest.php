<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Hub;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\GdprRetentionPruneService;
use App\Services\HubService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GdprRetentionPruneTest extends TestCase
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

        config([
            'gdpr.retention.activity_logs_days' => 30,
            'gdpr.retention.login_otp_hours' => 24,
            'gdpr.retention.email_verification_days' => 7,
            'gdpr.retention.password_reset_days' => 2,
            'gdpr.retention.sessions_days' => 30,
            'gdpr.retention.advisor_import_files_days' => 90,
            'gdpr.retention.closed_support_tickets_days' => 30,
        ]);
    }

    public function test_prune_removes_old_activity_logs_and_keeps_recent(): void
    {
        $user = User::factory()->create();

        ActivityLog::query()->create([
            'user_id' => $user->id,
            'user_name' => $user->name,
            'user_email' => $user->email,
            'action' => 'auth.login',
            'description' => 'old',
            'created_at' => now()->subDays(45),
        ]);
        ActivityLog::query()->create([
            'user_id' => $user->id,
            'user_name' => $user->name,
            'user_email' => $user->email,
            'action' => 'auth.login',
            'description' => 'recent',
            'created_at' => now()->subDays(5),
        ]);

        $result = app(GdprRetentionPruneService::class)->prune(false);

        $this->assertSame(1, $result['deleted']['activity_logs']);
        $this->assertSame(1, ActivityLog::query()->count());
        $this->assertSame('recent', ActivityLog::query()->value('description'));
    }

    public function test_prune_removes_old_closed_support_tickets(): void
    {
        $user = User::factory()->create();

        SupportTicket::query()->create([
            'user_id' => $user->id,
            'subject' => 'Old closed',
            'description' => 'gone',
            'status' => SupportTicket::STATUS_CLOSED,
            'closed_at' => now()->subDays(60),
            'priority' => SupportTicket::PRIORITY_MEDIUM,
            'category' => SupportTicket::CATEGORY_OTHER,
            'module_area' => 'other',
        ]);
        SupportTicket::query()->create([
            'user_id' => $user->id,
            'subject' => 'Still open',
            'description' => 'keep',
            'status' => SupportTicket::STATUS_OPEN,
            'priority' => SupportTicket::PRIORITY_MEDIUM,
            'category' => SupportTicket::CATEGORY_OTHER,
            'module_area' => 'other',
        ]);

        $result = app(GdprRetentionPruneService::class)->prune(false);

        $this->assertSame(1, $result['deleted']['closed_support_tickets']);
        $this->assertSame(1, SupportTicket::query()->count());
        $this->assertSame('Still open', SupportTicket::query()->value('subject'));
    }

    public function test_dry_run_does_not_delete(): void
    {
        ActivityLog::query()->create([
            'action' => 'auth.login',
            'description' => 'old',
            'created_at' => now()->subDays(90),
        ]);

        $result = app(GdprRetentionPruneService::class)->prune(true);

        $this->assertTrue($result['dry_run']);
        $this->assertSame(1, $result['deleted']['activity_logs']);
        $this->assertSame(1, ActivityLog::query()->count());
    }

    public function test_retention_policy_endpoint(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_FINPROMS_ADMIN,
            'email_verified_at' => now(),
        ]);
        Sanctum::actingAs($admin);

        $this->getJson('/api/client-admin/gdpr/retention')
            ->assertOk()
            ->assertJsonPath('policy.activity_logs_days', 30)
            ->assertJsonPath('schedule.command', 'gdpr:prune-retention');
    }

    public function test_prune_old_login_otp_tokens(): void
    {
        DB::table('login_otp_tokens')->insert([
            'email' => 'old@example.com',
            'challenge' => 'x',
            'code' => 'hash',
            'attempts' => 0,
            'created_at' => now()->subHours(48),
        ]);
        DB::table('login_otp_tokens')->insert([
            'email' => 'new@example.com',
            'challenge' => 'y',
            'code' => 'hash',
            'attempts' => 0,
            'created_at' => now()->subHours(1),
        ]);

        $result = app(GdprRetentionPruneService::class)->prune(false);

        $this->assertSame(1, $result['deleted']['login_otp_tokens']);
        $this->assertSame(1, DB::table('login_otp_tokens')->count());
        $this->assertSame('new@example.com', DB::table('login_otp_tokens')->value('email'));
    }
}
