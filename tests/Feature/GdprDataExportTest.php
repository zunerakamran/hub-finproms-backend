<?php

namespace Tests\Feature;

use App\Models\Hub;
use App\Models\Invoice;
use App\Models\User;
use App\Services\HubService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GdprDataExportTest extends TestCase
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
        ]);
        app(HubService::class)->forgetCurrentCache();
    }

    public function test_capable_admin_can_list_gdpr_users(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_FINPROMS_ADMIN,
            'email_verified_at' => now(),
        ]);
        User::factory()->create([
            'name' => 'Subject One',
            'email' => 'subject@example.com',
            'role' => User::ROLE_USER,
        ]);
        Sanctum::actingAs($admin);

        $this->getJson('/api/client-admin/gdpr/users?q=subject')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('users.0.email', 'subject@example.com');
    }

    public function test_capable_admin_can_export_user_json_package(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_FINPROMS_ADMIN,
            'email_verified_at' => now(),
            'name' => 'Admin Exporter',
        ]);
        $subject = User::factory()->create([
            'name' => 'Subject User',
            'email' => 'dsar@example.com',
            'role' => User::ROLE_USER,
            'email_verified_at' => now(),
        ]);

        Invoice::query()->create([
            'invoice_number' => 'INV-DSAR-1',
            'user_id' => $subject->id,
            'type' => Invoice::TYPE_POST_PURCHASE,
            'types' => Invoice::TYPES_ONE_TIME,
            'description' => 'Test purchase',
            'amount' => 10,
            'currency' => 'gbp',
            'credits' => 5,
            'status' => 'paid',
            'billing_name' => $subject->name,
            'billing_email' => $subject->email,
            'issued_at' => now(),
        ]);

        Sanctum::actingAs($admin);

        $response = $this->get('/api/client-admin/gdpr/users/'.$subject->id.'/export');
        $response->assertOk();
        $this->assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));

        $payload = json_decode($response->streamedContent(), true);
        $this->assertIsArray($payload);
        $this->assertSame('uk_gdpr_subject_access', $payload['export_meta']['type'] ?? null);
        $this->assertSame($subject->id, $payload['export_meta']['subject_user_id'] ?? null);
        $this->assertSame('dsar@example.com', $payload['profile']['email'] ?? null);
        $this->assertArrayNotHasKey('password', $payload['profile']);
        $this->assertNotEmpty($payload['invoices']);
        $this->assertSame('INV-DSAR-1', $payload['invoices'][0]['invoice_number'] ?? null);
    }

    public function test_export_forbidden_without_capability_role(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_USER,
            'email_verified_at' => now(),
        ]);
        $subject = User::factory()->create([
            'role' => User::ROLE_USER,
        ]);
        Sanctum::actingAs($user);

        $this->getJson('/api/client-admin/gdpr/users/'.$subject->id.'/export')
            ->assertForbidden();
    }
}
