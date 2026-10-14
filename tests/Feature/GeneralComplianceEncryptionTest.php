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
use App\Support\WebsiteCompliance\WcDatabaseContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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
        $caps[User::ROLE_USER]['gc_download_attachments'] = true;
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

        // Default: in-hub preview (inline) — no immediate Save As.
        $preview = $this->get('/api/gc/file/'.$attachment->id);
        $preview->assertOk();
        $this->assertSame('PLAINTEXT-BYTES', $preview->getContent());
        $preview->assertHeader('Content-Disposition', 'inline; filename="notes.pdf"');

        // Explicit download when capability is on.
        $download = $this->get('/api/gc/file/'.$attachment->id.'?download=1');
        $download->assertOk();
        $this->assertSame('PLAINTEXT-BYTES', $download->getContent());
        $download->assertHeader('Content-Disposition', 'attachment; filename="notes.pdf"');

        $payload = $attachment->toApiArray();
        $this->assertTrue($payload['can_download']);
        $this->assertStringContainsString('download=1', (string) $payload['download_url']);
    }

    public function test_gc_file_download_blocked_without_capability(): void
    {
        Storage::fake('gc_encrypted');

        $hub = $this->createHub();
        $caps = is_array($hub->role_capabilities) ? $hub->role_capabilities : [];
        $caps[User::ROLE_USER]['gc_submit_request'] = true;
        $caps[User::ROLE_USER]['gc_view_own_requests'] = true;
        $caps[User::ROLE_USER]['gc_download_attachments'] = false;
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
            'description' => 'Preview only',
            'content_type' => 'Brochure',
            'attachments' => [
                UploadedFile::fake()->createWithContent('secret.pdf', 'SECRET'),
            ],
        ]);

        $attachment = $compliance->currentVersionRow->attachments->first();
        $this->assertNotNull($attachment);

        Sanctum::actingAs($submitter);

        $this->get('/api/gc/file/'.$attachment->id)
            ->assertOk()
            ->assertHeader('Content-Disposition', 'inline; filename="secret.pdf"');

        $this->getJson('/api/gc/file/'.$attachment->id.'?download=1')
            ->assertForbidden();

        $payload = $attachment->toApiArray();
        $this->assertFalse($payload['can_download']);
        $this->assertNull($payload['download_url']);
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

    public function test_internal_gc_file_endpoint_serves_encrypted_bytes_with_hub_key(): void
    {
        Storage::fake('gc_encrypted');
        config(['services.hub_backup.secret' => 'test-hub-secret']);

        $hub = $this->createHub();
        $caps = is_array($hub->role_capabilities) ? $hub->role_capabilities : [];
        $caps[User::ROLE_USER]['gc_submit_request'] = true;
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
            'description' => 'Internal bridge',
            'content_type' => 'Brochure',
            'attachments' => [
                UploadedFile::fake()->createWithContent('bridge.pdf', 'BRIDGE-BYTES'),
            ],
        ]);
        $attachment = $compliance->currentVersionRow->attachments->first();
        $this->assertNotNull($attachment);

        $this->getJson('/api/internal/gc/file/'.$attachment->id)
            ->assertUnauthorized();

        $this->withHeaders(['X-Hub-Backup-Key' => 'test-hub-secret'])
            ->get('/api/internal/gc/file/'.$attachment->id)
            ->assertOk()
            ->assertSee('BRIDGE-BYTES', false);
    }

    public function test_central_acting_remotely_fetches_missing_gc_file_from_content_hub(): void
    {
        Storage::fake('gc_encrypted');

        $central = Hub::query()->create([
            'name' => 'Central',
            'slug' => 'central',
            'type' => Hub::TYPE_CENTRAL,
            'is_active' => true,
            'checklist' => Hub::defaultChecklist(Hub::TYPE_CENTRAL),
        ]);
        $shared = Hub::query()->create([
            'name' => 'Shared',
            'slug' => 'shared-remote',
            'type' => Hub::TYPE_SHARED,
            'is_active' => true,
            'api_url' => 'https://shared-remote.test/api',
            'backup_token' => 'shared-backup-token',
            'checklist' => array_merge(Hub::defaultChecklist(Hub::TYPE_SHARED), [
                'module_general_compliance' => true,
            ]),
        ]);
        $matrix = app(CapabilitiesMatrixService::class);
        $caps = $matrix->resolvedRoleCapabilities($shared);
        $caps[User::ROLE_MANAGER]['gc_view_all_requests'] = true;
        $shared->role_capabilities = $caps;
        $shared->save();

        // Point "current" hub at central for capability checks via HubService if needed.
        unset($central);

        Http::fake([
            'https://shared-remote.test/api/internal/gc/file/99' => Http::response('REMOTE-FILE-BYTES', 200, [
                'Content-Type' => 'application/pdf',
            ]),
        ]);

        $bytes = WcDatabaseContext::using('sqlite', function () {
            return GeneralComplianceEncryptedFiles::readPlaintextOrRemote(
                'enc/missing-on-central.enc',
                true,
                99
            );
        }, (int) $shared->id);

        $this->assertSame('REMOTE-FILE-BYTES', $bytes);
        Http::assertSent(function ($request) {
            return $request->url() === 'https://shared-remote.test/api/internal/gc/file/99'
                && $request->header('X-Hub-Backup-Key')[0] === 'shared-backup-token';
        });
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
