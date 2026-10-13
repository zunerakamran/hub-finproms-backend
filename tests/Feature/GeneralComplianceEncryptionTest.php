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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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

    public function test_gc_attachments_use_public_disk_like_before(): void
    {
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
            'description' => 'Has public file',
            'content_type' => 'Brochure',
            'attachments' => [
                UploadedFile::fake()->createWithContent('notes.pdf', 'PLAINTEXT-BYTES'),
            ],
        ]);

        $attachment = $compliance->currentVersionRow->attachments->first();
        $this->assertNotNull($attachment);
        $this->assertFalse((bool) $attachment->is_encrypted);
        $this->assertTrue(str_starts_with((string) $attachment->file_path, 'general-compliance/'));
        $this->assertNotNull($attachment->file_url);
        $this->assertFalse(str_contains((string) $attachment->file_url, '/api/gc/file/'));
        Storage::disk('public')->assertExists($attachment->file_path);
        $this->assertSame('PLAINTEXT-BYTES', Storage::disk('public')->get($attachment->file_path));
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
