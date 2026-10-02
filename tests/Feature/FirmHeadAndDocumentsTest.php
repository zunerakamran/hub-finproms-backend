<?php

namespace Tests\Feature;

use App\Models\Firm;
use App\Models\FirmDocument;
use App\Models\Hub;
use App\Models\User;
use App\Services\CapabilitiesMatrixService;
use App\Services\HubService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FirmHeadAndDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private function createSharedHub(): Hub
    {
        $hub = Hub::query()->create([
            'name' => 'Shared Hub',
            'slug' => 'shared-firm-docs-'.uniqid(),
            'type' => Hub::TYPE_SHARED,
            'is_active' => true,
            'checklist' => Hub::defaultChecklist(Hub::TYPE_SHARED),
        ]);
        app(HubService::class)->forgetCurrentCache();

        return $hub;
    }

    private function enableCaps(Hub $hub, string $role, array $keys): void
    {
        $matrix = app(CapabilitiesMatrixService::class);
        $caps = $matrix->resolvedRoleCapabilities($hub);
        foreach ($keys as $key) {
            $caps[$role][$key] = true;
        }
        $hub->role_capabilities = $caps;
        $hub->save();
        app(HubService::class)->forgetCurrentCache();
    }

    public function test_assign_replace_and_clear_firm_head_with_activity_logs(): void
    {
        $hub = $this->createSharedHub();
        $this->enableCaps($hub, User::ROLE_POWER_ADMIN, [
            'dashboard_manage_firms',
            'dashboard_assign_firm_head',
        ]);

        $admin = User::factory()->powerAdmin()->create();
        Sanctum::actingAs($admin);

        $firm = Firm::query()->create(['name' => 'Acme Docs']);
        $memberA = User::factory()->create(['firm_id' => $firm->id, 'role' => User::ROLE_USER]);
        $memberB = User::factory()->create(['firm_id' => $firm->id, 'role' => User::ROLE_USER]);
        User::factory()->create(['role' => User::ROLE_USER]); // outside firm

        $this->putJson('/api/power-admin/firms/'.$firm->id.'/head', [
            'head_user_id' => $memberA->id,
        ])->assertOk()
            ->assertJsonPath('firm.head_user_id', $memberA->id);

        $this->assertDatabaseHas('activity_logs', ['action' => 'firm.head.assign']);

        $this->putJson('/api/power-admin/firms/'.$firm->id.'/head', [
            'head_user_id' => $memberB->id,
        ])->assertOk()
            ->assertJsonPath('firm.head_user_id', $memberB->id);

        $this->assertDatabaseHas('activity_logs', ['action' => 'firm.head.replace']);

        $this->putJson('/api/power-admin/firms/'.$firm->id.'/head', [
            'head_user_id' => null,
        ])->assertOk()
            ->assertJsonPath('firm.head_user_id', null);

        $this->assertDatabaseHas('activity_logs', ['action' => 'firm.head.clear']);
    }

    public function test_head_can_upload_grant_rights_and_member_can_view(): void
    {
        Storage::fake('public');
        $hub = $this->createSharedHub();
        $checklist = $hub->resolvedChecklist();
        $checklist['firm_documents'] = true;
        $hub->checklist = $checklist;
        $hub->save();
        app(HubService::class)->forgetCurrentCache();

        $this->enableCaps($hub, User::ROLE_POWER_ADMIN, [
            'dashboard_assign_firm_head',
        ]);

        $admin = User::factory()->powerAdmin()->create();
        $firm = Firm::query()->create(['name' => 'Docs Firm']);
        $head = User::factory()->create(['firm_id' => $firm->id, 'role' => User::ROLE_USER]);
        $member = User::factory()->create(['firm_id' => $firm->id, 'role' => User::ROLE_USER]);

        Sanctum::actingAs($admin);
        $this->putJson('/api/power-admin/firms/'.$firm->id.'/head', [
            'head_user_id' => $head->id,
        ])->assertOk();

        Sanctum::actingAs($head);
        $file = UploadedFile::fake()->create('policy.pdf', 100, 'application/pdf');
        $this->post('/api/firm-documents', [
            'firm_id' => $firm->id,
            'title' => 'Policy pack',
            'attachments' => [$file],
        ], ['Accept' => 'application/json'])->assertCreated()
            ->assertJsonPath('document.title', 'Policy pack');

        $this->assertDatabaseHas('activity_logs', ['action' => 'firm.documents.add']);
        $this->assertSame(1, FirmDocument::query()->count());

        // Member cannot view yet.
        Sanctum::actingAs($member);
        $this->getJson('/api/firm-documents?firm_id='.$firm->id)
            ->assertStatus(403);

        // Head grants view.
        Sanctum::actingAs($head);
        $this->putJson('/api/firm-documents/member-rights', [
            'firm_id' => $firm->id,
            'user_id' => $member->id,
            'can_view' => true,
            'can_add' => false,
            'can_delete' => false,
            'can_archive' => false,
        ])->assertOk();

        $this->assertDatabaseHas('activity_logs', ['action' => 'firm.documents.member_rights.grant']);
        $this->assertDatabaseHas('firm_document_member_rights', [
            'firm_id' => $firm->id,
            'user_id' => $member->id,
            'can_view' => 1,
        ]);

        Sanctum::actingAs($member);
        $this->getJson('/api/firm-documents?firm_id='.$firm->id)
            ->assertOk()
            ->assertJsonPath('documents.0.title', 'Policy pack');
    }

    public function test_matrix_view_cap_allows_any_firm_when_functionality_on(): void
    {
        Storage::fake('public');
        $hub = $this->createSharedHub();
        $checklist = $hub->resolvedChecklist();
        $checklist['firm_documents'] = true;
        $hub->checklist = $checklist;
        $hub->save();
        $this->enableCaps($hub, User::ROLE_POWER_ADMIN, [
            'firm_documents_view',
            'firm_documents_add',
        ]);
        app(HubService::class)->forgetCurrentCache();

        $admin = User::factory()->powerAdmin()->create(); // no firm membership
        $firm = Firm::query()->create(['name' => 'Any Firm']);
        $head = User::factory()->create(['firm_id' => $firm->id, 'role' => User::ROLE_USER]);
        $firm->update(['head_user_id' => $head->id]);

        Sanctum::actingAs($head);
        $file = UploadedFile::fake()->create('a.pdf', 20, 'application/pdf');
        $this->post('/api/firm-documents', [
            'firm_id' => $firm->id,
            'title' => 'Hub wide',
            'attachments' => [$file],
        ], ['Accept' => 'application/json'])->assertCreated();

        Sanctum::actingAs($admin);
        $this->getJson('/api/firm-documents?firm_id='.$firm->id)
            ->assertOk()
            ->assertJsonPath('documents.0.title', 'Hub wide');
    }

    public function test_head_can_archive_and_delete_documents(): void
    {
        Storage::fake('public');
        $hub = $this->createSharedHub();
        $checklist = $hub->resolvedChecklist();
        $checklist['firm_documents'] = true;
        $hub->checklist = $checklist;
        $hub->save();
        app(HubService::class)->forgetCurrentCache();

        $firm = Firm::query()->create(['name' => 'Archive Firm']);
        $head = User::factory()->create(['firm_id' => $firm->id, 'role' => User::ROLE_USER]);
        $firm->update(['head_user_id' => $head->id]);

        Sanctum::actingAs($head);
        $file = UploadedFile::fake()->create('notes.docx', 50, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        $created = $this->post('/api/firm-documents', [
            'firm_id' => $firm->id,
            'title' => 'Notes',
            'attachments' => [$file],
        ], ['Accept' => 'application/json'])->assertCreated();

        $docId = (int) $created->json('document.id');

        $this->postJson('/api/firm-documents/'.$docId.'/archive')->assertOk()
            ->assertJsonPath('document.is_archived', true);
        $this->assertDatabaseHas('activity_logs', ['action' => 'firm.documents.archive']);

        $this->deleteJson('/api/firm-documents/'.$docId)->assertOk();
        $this->assertDatabaseMissing('firm_documents', ['id' => $docId]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'firm.documents.delete']);
    }

    public function test_manager_head_gets_document_rights_without_matrix_tick(): void
    {
        $hub = $this->createSharedHub();
        $checklist = $hub->resolvedChecklist();
        $checklist['firm_documents'] = true;
        $hub->checklist = $checklist;
        $hub->save();
        app(HubService::class)->forgetCurrentCache();

        $firm = Firm::query()->create(['name' => 'Manager Head Firm']);
        $head = User::factory()->create([
            'firm_id' => $firm->id,
            'role' => User::ROLE_MANAGER,
            'email' => 'manager@gmail.com',
        ]);
        $firm->update(['head_user_id' => $head->id]);

        Sanctum::actingAs($head);

        $this->getJson('/api/firm-documents/my-rights')
            ->assertOk()
            ->assertJsonPath('rights.is_firm_head', true)
            ->assertJsonPath('rights.can_view', true)
            ->assertJsonPath('rights.can_add', true)
            ->assertJsonPath('rights.functionality_enabled', true)
            ->assertJsonPath('rights.firm_id', $firm->id);

        $this->getJson('/api/hub')
            ->assertOk()
            ->assertJsonPath('hub.firm_document_rights.is_firm_head', true)
            ->assertJsonPath('hub.effective_capabilities.firm_documents_view', true)
            ->assertJsonPath('hub.effective_capabilities.firm_documents_add', true);
    }
}