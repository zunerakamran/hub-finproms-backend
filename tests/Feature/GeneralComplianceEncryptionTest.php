<?php

namespace Tests\Feature;

use App\Casts\SafeEncrypted;
use App\Models\Firm;
use App\Models\GeneralComplianceContentType;
use App\Models\GeneralComplianceRequest;
use App\Models\GeneralComplianceRequestVersion;
use App\Models\Hub;
use App\Models\User;
use App\Services\CapabilitiesMatrixService;
use App\Services\GeneralComplianceService;
use App\Services\HubService;
use App\Support\GeneralComplianceEncryptedFiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GeneralComplianceEncryptionTest extends TestCase
{
    use RefreshDatabase;

    private function createHub(): Hub
    {
        $hub = Hub::query()->create([
            'name' => 'Shared Hub',
            'slug' => 'shared',
            'type' => Hub::TYPE_SHARED,
            'is_active' => true,
            'checklist' => array_merge(Hub::defaultChecklist(Hub::TYPE_SHARED), [
                'module_general_compliance' => true,
            ]),
        ]);

        $matrix = app(CapabilitiesMatrixService::class);
        $caps = $matrix->resolvedRoleCapabilities($hub);
        $caps[User::ROLE_MANAGER]['gc_change_request_status'] = true;
        $caps[User::ROLE_MANAGER]['gc_view_all_requests'] = true;
        $hub->role_capabilities = $caps;
        $hub->save();
        app(HubService::class)->forgetCurrentCache();

        return $hub;
    }

    public function test_gc_sensitive_text_fields_are_encrypted_at_rest(): void
    {
        $this->createHub();
        $firm = Firm::query()->create([
            'name' => 'Firm Enc',
            'compliance_visible_to_own' => true,
        ]);
        $submitter = User::factory()->create([
            'role' => User::ROLE_USER,
            'firm_id' => $firm->id,
        ]);

        $request = GeneralComplianceRequest::query()->create([
            'user_id' => $submitter->id,
            'name' => $submitter->name,
            'submission_date' => now(),
            'current_version' => 1,
        ]);

        GeneralComplianceRequestVersion::query()->create([
            'request_id' => $request->id,
            'version_number' => 1,
            'description' => 'Sensitive GC submission body',
            'content_type' => 'Brochure',
            'submitted_by' => $submitter->id,
            'submitted_at' => now(),
            'status' => GeneralComplianceRequest::STATUS_PENDING,
            'feedback' => 'Remedial note with personal details',
            'future_feedback' => 'Follow up next quarter',
        ]);

        $raw = DB::table('general_compliance_request_versions')->where('request_id', $request->id)->first();

        $this->assertNotSame('Sensitive GC submission body', $raw->description);
        $this->assertTrue(SafeEncrypted::looksLikeLaravelCiphertext((string) $raw->description));

        $version = GeneralComplianceRequestVersion::query()->findOrFail($raw->id);
        $this->assertSame('Sensitive GC submission body', $version->description);
        $this->assertSame('Remedial note with personal details', $version->feedback);
    }

    public function test_gc_attachments_are_encrypted_at_rest_and_downloadable(): void
    {
        Storage::fake('gc_encrypted');
        Storage::fake('public');

        $hub = $this->createHub();
        $caps = is_array($hub->role_capabilities) ? $hub->role_capabilities : [];
        $caps[User::ROLE_USER]['gc_submit_request'] = true;
        $caps[User::ROLE_USER]['gc_view_own_requests'] = true;
        $hub->role_capabilities = $caps;
        $hub->save();
        app(HubService::class)->forgetCurrentCache();

        GeneralComplianceContentType::query()->create(['name' => 'Brochure']);

        $firm = Firm::query()->create([
            'name' => 'Firm Enc',
            'compliance_visible_to_own' => true,
        ]);
        $submitter = User::factory()->create([
            'role' => User::ROLE_USER,
            'firm_id' => $firm->id,
        ]);

        $compliance = app(GeneralComplianceService::class)->submit($hub, $submitter, [
            'description' => 'Has encrypted file',
            'content_type' => 'Brochure',
            'attachments' => [
                UploadedFile::fake()->createWithContent('notes.pdf', 'PLAINTEXT-BYTES'),
            ],
        ]);

        $attachment = $compliance->currentVersionRow->attachments->first();
        $this->assertNotNull($attachment);
        $this->assertTrue((bool) $attachment->is_encrypted);
        $this->assertTrue(str_starts_with((string) $attachment->file_path, 'enc/'));
        $this->assertNull($attachment->file_url);
        $this->assertStringContainsString('/api/gc/file/'.$attachment->id, (string) $attachment->resolvedFileUrl());

        Storage::disk('gc_encrypted')->assertExists($attachment->file_path);
        $onDisk = Storage::disk('gc_encrypted')->get($attachment->file_path);
        $this->assertNotSame('PLAINTEXT-BYTES', $onDisk);
        $this->assertTrue(str_starts_with($onDisk, GeneralComplianceEncryptedFiles::MAGIC));
        $this->assertSame(
            'PLAINTEXT-BYTES',
            GeneralComplianceEncryptedFiles::readPlaintext($attachment->file_path, true)
        );

        Sanctum::actingAs($submitter);
        $response = $this->get('/api/gc/file/'.$attachment->id);
        $response->assertOk();
        $this->assertSame('PLAINTEXT-BYTES', $response->getContent());
        $response->assertHeader('Content-Disposition');
    }

    public function test_gc_file_download_requires_authentication(): void
    {
        Storage::fake('gc_encrypted');

        $hub = $this->createHub();
        $caps = is_array($hub->role_capabilities) ? $hub->role_capabilities : [];
        $caps[User::ROLE_USER]['gc_submit_request'] = true;
        $caps[User::ROLE_USER]['gc_view_own_requests'] = true;
        $hub->role_capabilities = $caps;
        $hub->save();
        app(HubService::class)->forgetCurrentCache();

        GeneralComplianceContentType::query()->create(['name' => 'Brochure']);

        $firm = Firm::query()->create([
            'name' => 'Firm Enc',
            'compliance_visible_to_own' => true,
        ]);
        $submitter = User::factory()->create([
            'role' => User::ROLE_USER,
            'firm_id' => $firm->id,
        ]);

        $compliance = app(GeneralComplianceService::class)->submit($hub, $submitter, [
            'description' => 'Auth required',
            'content_type' => 'Brochure',
            'attachments' => [
                UploadedFile::fake()->createWithContent('secret.pdf', 'SECRET'),
            ],
        ]);

        $attachment = $compliance->currentVersionRow->attachments->first();
        $this->assertNotNull($attachment);

        $this->getJson('/api/gc/file/'.$attachment->id)
            ->assertUnauthorized();
    }

    public function test_legacy_plaintext_gc_fields_remain_readable(): void
    {
        $this->createHub();
        $firm = Firm::query()->create([
            'name' => 'Firm Enc',
            'compliance_visible_to_own' => true,
        ]);
        $submitter = User::factory()->create([
            'role' => User::ROLE_USER,
            'firm_id' => $firm->id,
        ]);

        $requestId = DB::table('general_compliance_requests')->insertGetId([
            'user_id' => $submitter->id,
            'name' => $submitter->name,
            'current_version' => 1,
            'submission_date' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $versionId = DB::table('general_compliance_request_versions')->insertGetId([
            'request_id' => $requestId,
            'version_number' => 1,
            'description' => 'Legacy plaintext description',
            'submitted_by' => $submitter->id,
            'submitted_at' => now(),
            'status' => GeneralComplianceRequest::STATUS_PENDING,
            'feedback' => 'Legacy feedback',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $version = GeneralComplianceRequestVersion::query()->findOrFail($versionId);
        $this->assertSame('Legacy plaintext description', $version->description);
        $this->assertSame('Legacy feedback', $version->feedback);
    }
}
