<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Hub;
use App\Models\Invoice;
use App\Models\User;
use App\Services\GdprErasureService;
use App\Services\HubService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GdprErasureTest extends TestCase
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

    public function test_capable_admin_can_erase_user_and_scrub_denormalised_pii(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_FINPROMS_ADMIN,
            'email_verified_at' => now(),
        ]);
        $subject = User::factory()->create([
            'name' => 'Erase Me',
            'email' => 'erase-me@example.com',
            'role' => User::ROLE_USER,
            'email_verified_at' => now(),
        ]);

        Invoice::query()->create([
            'invoice_number' => 'INV-ERASE-1',
            'user_id' => $subject->id,
            'type' => Invoice::TYPE_POST_PURCHASE,
            'types' => Invoice::TYPES_ONE_TIME,
            'description' => 'Test',
            'amount' => 5,
            'currency' => 'gbp',
            'credits' => 1,
            'status' => 'paid',
            'billing_name' => 'Erase Me',
            'billing_email' => 'erase-me@example.com',
            'issued_at' => now(),
        ]);

        ActivityLog::query()->create([
            'user_id' => $subject->id,
            'user_name' => 'Erase Me',
            'user_email' => 'erase-me@example.com',
            'user_role' => 'user',
            'action' => 'auth.login',
            'description' => 'Logged in',
            'ip_address' => '203.0.113.10',
            'user_agent' => 'TestAgent',
            'created_at' => now(),
        ]);

        Sanctum::actingAs($admin);

        $this->postJson('/api/client-admin/gdpr/users/'.$subject->id.'/erase', [
            'confirm' => true,
        ])
            ->assertOk()
            ->assertJsonPath('result.user_id', $subject->id)
            ->assertJsonPath('result.already_erased', false);

        $subject->refresh();
        $this->assertNotNull($subject->gdpr_erased_at);
        $this->assertTrue($subject->is_suspended);
        $this->assertTrue($subject->is_discontinued);
        $this->assertStringStartsWith(GdprErasureService::ANONYMOUS_NAME_PREFIX, (string) $subject->name);
        $this->assertStringEndsWith('@erased.invalid', (string) $subject->email);
        $this->assertNull($subject->stripe_customer_id);

        $invoice = Invoice::query()->where('user_id', $subject->id)->first();
        $this->assertSame($subject->name, $invoice?->billing_name);
        $this->assertSame($subject->email, $invoice?->billing_email);

        $log = ActivityLog::query()->where('user_id', $subject->id)->first();
        $this->assertSame($subject->name, $log?->user_name);
        $this->assertSame($subject->email, $log?->user_email);
        $this->assertNull($log?->ip_address);
        $this->assertNull($log?->user_agent);
    }

    public function test_cannot_erase_own_account(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_FINPROMS_ADMIN,
            'email_verified_at' => now(),
        ]);
        Sanctum::actingAs($admin);

        $this->postJson('/api/client-admin/gdpr/users/'.$admin->id.'/erase', [
            'confirm' => true,
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'You cannot erase your own account.');
    }

    public function test_erase_requires_confirm(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_FINPROMS_ADMIN,
            'email_verified_at' => now(),
        ]);
        $subject = User::factory()->create([
            'role' => User::ROLE_USER,
        ]);
        Sanctum::actingAs($admin);

        $this->postJson('/api/client-admin/gdpr/users/'.$subject->id.'/erase', [])
            ->assertStatus(422);
    }
}
