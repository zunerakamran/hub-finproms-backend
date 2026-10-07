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

        $docId = (int) FirmDocument::query()->value('id');

        // Member cannot view yet.
        Sanctum::actingAs($member);
        $this->getJson('/api/firm-documents?firm_id='.$firm->id)
            ->assertStatus(403);

        // Head grants per-document view.
        Sanctum::actingAs($head);
        $this->putJson('/api/firm-documents/'.$docId.'/member-rights', [
            'user_id' => $member->id,
            'can_view' => true,
            'can_add' => false,
            'can_delete' => false,
            'can_archive' => false,
        ])->assertOk();

        $this->assertDatabaseHas('activity_logs', ['action' => 'firm.documents.member_rights.grant']);
        $this->assertDatabaseHas('firm_document_member_rights', [
            'firm_id' => $firm->id,
            'firm_document_id' => $docId,
            'user_id' => $member->id,
            'can_view' => 1,
        ]);

        Sanctum::actingAs($member);
        $this->getJson('/api/firm-documents?firm_id='.$firm->id)
            ->assertOk()
            ->assertJsonPath('documents.0.title', 'Policy pack');
    }

    public function test_upload_with_folder_and_category_and_nested_library(): void
    {
        Storage::fake('public');
        $hub = $this->createSharedHub();
        $checklist = $hub->resolvedChecklist();
        $checklist['firm_documents'] = true;
        $hub->checklist = $checklist;
        $hub->save();
        app(HubService::class)->forgetCurrentCache();

        $this->enableCaps($hub, User::ROLE_POWER_ADMIN, [
            'firm_documents_manage_categories',
            'firm_documents_add',
            'firm_documents_view',
        ]);

        $admin = User::factory()->powerAdmin()->create();
        $firm = Firm::query()->create(['name' => 'Folder Firm']);
        $head = User::factory()->create(['firm_id' => $firm->id, 'role' => User::ROLE_USER]);
        $firm->update(['head_user_id' => $head->id]);

        Sanctum::actingAs($admin);
        $cat = $this->postJson('/api/power-admin/firm-documents/categories', [
            'name' => 'Policies',
        ])->assertCreated()->json('category');

        Sanctum::actingAs($head);
        $file = UploadedFile::fake()->create('handbook.pdf', 40, 'application/pdf');
        $created = $this->post('/api/firm-documents', [
            'firm_id' => $firm->id,
            'title' => 'Handbook',
            'folder_name' => 'HR',
            'category_id' => $cat['id'],
            'attachments' => [$file],
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->assertNotNull($created->json('document.folder_id'));
        $this->assertSame((int) $cat['id'], (int) $created->json('document.category_id'));

        $list = $this->getJson('/api/firm-documents?firm_id='.$firm->id.'&scope=all')
            ->assertOk();
        $this->assertNotEmpty($list->json('folders'));
        $this->assertSame('HR', $list->json('folders.0.name'));
        $this->assertSame('Handbook', $list->json('folders.0.documents.0.title'));
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
        // Functionality OFF — Head appointment alone must still unlock Documents.
        $checklist = $hub->resolvedChecklist();
        $checklist['firm_documents'] = false;
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
            ->assertJsonPath('rights.firm_id', $firm->id);

        $this->getJson('/api/hub')
            ->assertOk()
            ->assertJsonPath('hub.firm_document_rights.is_firm_head', true)
            ->assertJsonPath('hub.effective_capabilities.firm_documents_view', true)
            ->assertJsonPath('hub.effective_capabilities.firm_documents_add', true);
    }

    public function test_central_document_firm_access_applies_to_all_grantee_members(): void
    {
        Storage::fake('public');
        $hub = $this->createSharedHub();
        $checklist = $hub->resolvedChecklist();
        $checklist['firm_documents'] = true;
        $hub->checklist = $checklist;
        $hub->save();
        app(HubService::class)->forgetCurrentCache();

        $this->enableCaps($hub, User::ROLE_POWER_ADMIN, [
            'firm_documents_manage_firm_access',
            'firm_documents_add',
            'firm_documents_view',
        ]);

        $admin = User::factory()->powerAdmin()->create();
        $central = Firm::central();
        $firm1 = Firm::query()->create(['name' => 'Firm One']);
        $firm2 = Firm::query()->create(['name' => 'Firm Two']);
        $memberA = User::factory()->create(['firm_id' => $firm1->id, 'role' => User::ROLE_USER]);
        $memberB = User::factory()->create(['firm_id' => $firm1->id, 'role' => User::ROLE_USER]);
        $outsider = User::factory()->create(['firm_id' => $firm2->id, 'role' => User::ROLE_USER]);

        Sanctum::actingAs($admin);
        $file = UploadedFile::fake()->create('network-policy.pdf', 50, 'application/pdf');
        $created = $this->post('/api/firm-documents', [
            'firm_id' => $central->id,
            'title' => 'Network policy',
            'attachments' => [$file],
        ], ['Accept' => 'application/json'])->assertCreated();

        $docId = (int) $created->json('document.id');

        $access = $this->getJson('/api/firm-documents/'.$docId.'/member-rights')
            ->assertOk()
            ->assertJsonPath('mode', 'firms');
        $this->assertNotEmpty($access->json('firms'));

        $this->putJson('/api/firm-documents/'.$docId.'/member-rights', [
            'grantee_firm_id' => $firm1->id,
            'can_view' => true,
            'can_add' => false,
            'can_delete' => false,
            'can_archive' => false,
        ])->assertOk()
            ->assertJsonPath('mode', 'firms');

        $this->assertDatabaseHas('activity_logs', ['action' => 'firm.documents.firm_rights.grant']);
        $this->assertDatabaseHas('firm_document_firm_rights', [
            'firm_document_id' => $docId,
            'grantee_firm_id' => $firm1->id,
            'can_view' => 1,
        ]);

        // Every Firm One member can see the shared Central doc in their library.
        Sanctum::actingAs($memberA);
        $listA = $this->getJson('/api/firm-documents?firm_id='.$firm1->id)->assertOk();
        $titlesA = collect($listA->json('documents'))->pluck('title')->all();
        $this->assertContains('Network policy', $titlesA);

        Sanctum::actingAs($memberB);
        $listB = $this->getJson('/api/firm-documents?firm_id='.$firm1->id)->assertOk();
        $titlesB = collect($listB->json('documents'))->pluck('title')->all();
        $this->assertContains('Network policy', $titlesB);

        // Firm Two has no grant — member cannot open Firm One, and (as Firm Two head) sees no shared Central doc.
        $firm2->update(['head_user_id' => $outsider->id]);
        Sanctum::actingAs($outsider);
        $this->getJson('/api/firm-documents?firm_id='.$firm1->id)->assertStatus(403);
        $listOut = $this->getJson('/api/firm-documents?firm_id='.$firm2->id)->assertOk();
        $titlesOut = collect($listOut->json('documents'))->pluck('title')->all();
        $this->assertNotContains('Network policy', $titlesOut);
    }
}