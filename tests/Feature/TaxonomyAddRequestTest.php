<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\ContentType;
use App\Models\FirmDocumentCategory;
use App\Models\GeneralComplianceContentType;
use App\Models\Hub;
use App\Models\Tag;
use App\Models\TaxonomyAddRequest;
use App\Models\User;
use App\Services\CapabilitiesMatrixService;
use App\Services\HubService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TaxonomyAddRequestTest extends TestCase
{
    use RefreshDatabase;

    private function createCentralHub(): Hub
    {
        config([
            'hub.current_slug' => 'central',
            'hub.type' => 'central',
            'hub.is_control_plane' => true,
        ]);

        $matrix = app(CapabilitiesMatrixService::class);
        $hub = Hub::query()->create([
            'name' => 'Central Hub Controller',
            'slug' => 'central',
            'type' => Hub::TYPE_CENTRAL,
            'is_active' => true,
            'checklist' => Hub::defaultChecklist(Hub::TYPE_CENTRAL),
            'role_capabilities' => $matrix->defaultRoleCapabilities(Hub::TYPE_CENTRAL),
        ]);

        $caps = $matrix->resolvedRoleCapabilities($hub);
        $caps[User::ROLE_USER]['taxonomy_request_add'] = true;
        $caps[User::ROLE_POWER_ADMIN]['taxonomy_request_add'] = true;
        $caps[User::ROLE_POWER_ADMIN]['dashboard_manage_types'] = true;
        $caps[User::ROLE_POWER_ADMIN]['dashboard_manage_categories'] = true;
        $caps[User::ROLE_POWER_ADMIN]['dashboard_manage_tags'] = true;
        $hub->role_capabilities = $caps;
        $hub->save();
        app(HubService::class)->forgetCurrentCache();
        $matrix->forgetResolvedCaches();

        return $hub;
    }

    private function createSharedHubWithFirmDocsAndGc(): Hub
    {
        config([
            'hub.current_slug' => 'shared',
            'hub.type' => 'shared',
            'hub.is_control_plane' => false,
        ]);

        $checklist = Hub::defaultChecklist(Hub::TYPE_SHARED);
        $checklist['firm_documents'] = true;
        $checklist['module_general_compliance'] = true;

        $matrix = app(CapabilitiesMatrixService::class);
        $hub = Hub::query()->create([
            'name' => 'Shared Hub',
            'slug' => 'shared',
            'type' => Hub::TYPE_SHARED,
            'is_active' => true,
            'checklist' => $checklist,
            'role_capabilities' => $matrix->defaultRoleCapabilities(Hub::TYPE_SHARED),
        ]);

        $caps = $matrix->resolvedRoleCapabilities($hub);
        $caps[User::ROLE_USER]['taxonomy_request_add'] = true;
        $caps[User::ROLE_FINPROMS_ADMIN]['taxonomy_request_add'] = true;
        $caps[User::ROLE_FINPROMS_ADMIN]['gc_manage_content_types'] = true;
        $caps[User::ROLE_FINPROMS_ADMIN]['firm_documents_manage_categories'] = true;
        $hub->role_capabilities = $caps;
        $hub->save();
        app(HubService::class)->forgetCurrentCache();
        $matrix->forgetResolvedCaches();

        return $hub;
    }

    public function test_capability_is_registered(): void
    {
        $this->assertArrayHasKey('taxonomy_request_add', Hub::CHECKLIST_DEFINITIONS);
        $this->assertSame(
            Hub::GROUP_DASHBOARD_CONTENT,
            Hub::CHECKLIST_DEFINITIONS['taxonomy_request_add']['group']
        );
    }

    public function test_user_can_submit_and_view_own_content_taxonomy_request_on_central(): void
    {
        $this->createCentralHub();

        $user = User::factory()->create(['role' => User::ROLE_USER]);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/taxonomy-add-requests', [
            'target' => TaxonomyAddRequest::TARGET_CATEGORY,
            'proposed_name' => 'Retirement Planning',
            'remarks' => 'Needed for Q4 campaign posts.',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.target', TaxonomyAddRequest::TARGET_CATEGORY)
            ->assertJsonPath('data.proposed_name', 'Retirement Planning')
            ->assertJsonPath('data.status', TaxonomyAddRequest::STATUS_PENDING);

        $id = $response->json('data.id');

        $this->getJson('/api/taxonomy-add-requests/mine')
            ->assertOk()
            ->assertJsonPath('data.0.id', $id);
    }

    public function test_content_taxonomy_request_blocked_on_shared_hub(): void
    {
        $this->createSharedHubWithFirmDocsAndGc();

        $user = User::factory()->create(['role' => User::ROLE_USER]);
        Sanctum::actingAs($user);

        $this->postJson('/api/taxonomy-add-requests', [
            'target' => TaxonomyAddRequest::TARGET_TAG,
            'proposed_name' => 'Isa',
            'remarks' => 'Should only work on Central.',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['target']);
    }

    public function test_admin_can_approve_and_auto_create_category_on_central(): void
    {
        $this->createCentralHub();

        $user = User::factory()->create(['role' => User::ROLE_USER]);
        $admin = User::factory()->create(['role' => User::ROLE_POWER_ADMIN]);

        $request = TaxonomyAddRequest::query()->create([
            'user_id' => $user->id,
            'target' => TaxonomyAddRequest::TARGET_CATEGORY,
            'proposed_name' => 'ESG Themes',
            'remarks' => 'Please add for library posts.',
            'status' => TaxonomyAddRequest::STATUS_PENDING,
        ]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/power-admin/taxonomy-add-requests')
            ->assertOk()
            ->assertJsonFragment(['id' => $request->id]);

        $this->postJson('/api/power-admin/taxonomy-add-requests/'.$request->id.'/approve', [
            'review_note' => 'Looks good.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', TaxonomyAddRequest::STATUS_APPROVED)
            ->assertJsonPath('data.review_note', 'Looks good.');

        $this->assertDatabaseHas('categories', ['name' => 'ESG Themes']);
        $this->assertNotNull(Category::query()->where('name', 'ESG Themes')->value('id'));
        $this->assertDatabaseHas('taxonomy_add_requests', [
            'id' => $request->id,
            'status' => TaxonomyAddRequest::STATUS_APPROVED,
            'created_entity_id' => Category::query()->where('name', 'ESG Themes')->value('id'),
        ]);
    }

    public function test_admin_can_approve_content_type_and_tag(): void
    {
        $this->createCentralHub();

        $user = User::factory()->create(['role' => User::ROLE_USER]);
        $admin = User::factory()->create(['role' => User::ROLE_POWER_ADMIN]);
        Sanctum::actingAs($admin);

        $typeRequest = TaxonomyAddRequest::query()->create([
            'user_id' => $user->id,
            'target' => TaxonomyAddRequest::TARGET_CONTENT_TYPE,
            'proposed_name' => 'Carousel',
            'remarks' => 'New format.',
            'status' => TaxonomyAddRequest::STATUS_PENDING,
        ]);

        $this->postJson('/api/power-admin/taxonomy-add-requests/'.$typeRequest->id.'/approve')
            ->assertOk();
        $this->assertDatabaseHas('content_types', ['name' => 'Carousel']);
        $this->assertNotNull(ContentType::query()->where('name', 'Carousel')->first());

        $tagRequest = TaxonomyAddRequest::query()->create([
            'user_id' => $user->id,
            'target' => TaxonomyAddRequest::TARGET_TAG,
            'proposed_name' => 'pensions',
            'remarks' => 'Common filter.',
            'status' => TaxonomyAddRequest::STATUS_PENDING,
        ]);

        $this->postJson('/api/power-admin/taxonomy-add-requests/'.$tagRequest->id.'/approve')
            ->assertOk();
        $this->assertDatabaseHas('tags', ['name' => 'pensions']);
        $this->assertNotNull(Tag::query()->where('name', 'pensions')->first());
    }

    public function test_admin_can_reject_with_note(): void
    {
        $this->createCentralHub();

        $user = User::factory()->create(['role' => User::ROLE_USER]);
        $admin = User::factory()->create(['role' => User::ROLE_POWER_ADMIN]);

        $request = TaxonomyAddRequest::query()->create([
            'user_id' => $user->id,
            'target' => TaxonomyAddRequest::TARGET_TAG,
            'proposed_name' => 'duplicate-ish',
            'remarks' => 'Maybe not needed.',
            'status' => TaxonomyAddRequest::STATUS_PENDING,
        ]);

        Sanctum::actingAs($admin);

        $this->postJson('/api/power-admin/taxonomy-add-requests/'.$request->id.'/reject', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['review_note']);

        $this->postJson('/api/power-admin/taxonomy-add-requests/'.$request->id.'/reject', [
            'review_note' => 'Already covered by existing tags.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', TaxonomyAddRequest::STATUS_REJECTED);

        $this->assertDatabaseMissing('tags', ['name' => 'duplicate-ish']);
    }

    public function test_shared_hub_gc_and_firm_document_category_flow(): void
    {
        $this->createSharedHubWithFirmDocsAndGc();

        $user = User::factory()->create(['role' => User::ROLE_USER]);
        Sanctum::actingAs($user);

        $gcResponse = $this->postJson('/api/taxonomy-add-requests', [
            'target' => TaxonomyAddRequest::TARGET_GC_CONTENT_TYPE,
            'proposed_name' => 'Brochure',
            'remarks' => 'Advisors keep submitting brochures.',
        ]);
        $gcResponse->assertCreated();
        $gcId = $gcResponse->json('data.id');

        $firmResponse = $this->postJson('/api/taxonomy-add-requests', [
            'target' => TaxonomyAddRequest::TARGET_FIRM_DOCUMENT_CATEGORY,
            'proposed_name' => 'Compliance Packs',
            'remarks' => 'For firm-wide packs.',
        ]);
        $firmResponse->assertCreated();
        $firmId = $firmResponse->json('data.id');

        $admin = User::factory()->create(['role' => User::ROLE_FINPROMS_ADMIN]);
        Sanctum::actingAs($admin);

        $this->getJson('/api/client-admin/taxonomy-add-requests')
            ->assertOk()
            ->assertJsonFragment(['id' => $gcId])
            ->assertJsonFragment(['id' => $firmId]);

        $this->postJson('/api/client-admin/taxonomy-add-requests/'.$gcId.'/approve')
            ->assertOk();
        $this->assertDatabaseHas('general_compliance_content_types', ['name' => 'Brochure']);
        $this->assertNotNull(GeneralComplianceContentType::query()->where('name', 'Brochure')->first());

        $this->postJson('/api/client-admin/taxonomy-add-requests/'.$firmId.'/approve')
            ->assertOk();
        $this->assertDatabaseHas('firm_document_categories', ['name' => 'Compliance Packs']);
        $this->assertNotNull(FirmDocumentCategory::query()->where('name', 'Compliance Packs')->first());
    }

    public function test_submit_forbidden_without_capability(): void
    {
        $this->createCentralHub();

        $matrix = app(CapabilitiesMatrixService::class);
        $hub = app(HubService::class)->current();
        $caps = $matrix->resolvedRoleCapabilities($hub);
        $caps[User::ROLE_USER]['taxonomy_request_add'] = false;
        $hub->role_capabilities = $caps;
        $hub->save();
        $matrix->forgetResolvedCaches();

        $user = User::factory()->create(['role' => User::ROLE_USER]);
        Sanctum::actingAs($user);

        $this->postJson('/api/taxonomy-add-requests', [
            'target' => TaxonomyAddRequest::TARGET_CATEGORY,
            'proposed_name' => 'Blocked',
            'remarks' => 'No capability.',
        ])->assertForbidden();
    }
}
