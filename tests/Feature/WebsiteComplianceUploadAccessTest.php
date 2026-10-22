<?php

namespace Tests\Feature;

use App\Models\Hub;
use App\Models\User;
use App\Services\HubService;
use App\Support\TermsAndConditionsDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WebsiteComplianceUploadAccessTest extends TestCase
{
    use RefreshDatabase;

    private string $uploadsDir;

    protected function setUp(): void
    {
        parent::setUp();

        $hub = Hub::query()->create([
            'name' => 'Shared Hub',
            'slug' => 'shared',
            'type' => Hub::TYPE_SHARED,
            'is_active' => true,
            'checklist' => array_merge(Hub::defaultChecklist(Hub::TYPE_SHARED), [
                'module_website_compliance' => true,
                'module_website_template_library' => true,
            ]),
            'terms_and_conditions' => [
                'content' => TermsAndConditionsDefaults::shared(),
                'version' => 1,
                'updated_at' => now()->toIso8601String(),
            ],
        ]);
        $roleCaps = is_array($hub->role_capabilities) ? $hub->role_capabilities : [];
        $roleCaps[User::ROLE_CLIENT_ADMIN]['wc_edit_sections'] = true;
        $hub->role_capabilities = $roleCaps;
        $hub->save();
        app(HubService::class)->forgetCurrentCache();

        $this->uploadsDir = storage_path('app/uploads');
        if (! is_dir($this->uploadsDir)) {
            mkdir($this->uploadsDir, 0755, true);
        }
    }

    public function test_images_remain_publicly_readable(): void
    {
        $filename = 'test_img_'.uniqid().'.png';
        file_put_contents($this->uploadsDir.DIRECTORY_SEPARATOR.$filename, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        ));

        $this->get('/api/website-compliance/uploaded-images/'.$filename)
            ->assertOk();
    }

    public function test_documents_on_images_path_require_auth(): void
    {
        $filename = 'test_doc_'.uniqid().'.pdf';
        file_put_contents($this->uploadsDir.DIRECTORY_SEPARATOR.$filename, '%PDF-1.4 test');

        $this->get('/api/website-compliance/uploaded-images/'.$filename)
            ->assertUnauthorized();
    }

    public function test_documents_route_allows_signed_url_without_session(): void
    {
        $filename = 'test_signed_'.uniqid().'.pdf';
        file_put_contents($this->uploadsDir.DIRECTORY_SEPARATOR.$filename, '%PDF-1.4 signed');

        $this->get('/api/website-compliance/uploaded-documents/'.$filename)
            ->assertUnauthorized();

        $url = URL::temporarySignedRoute(
            'wc.uploaded-document',
            now()->addMinutes(10),
            ['filename' => $filename]
        );

        $signedResponse = $this->get($url);
        $signedResponse->assertOk();
        $this->assertStringContainsString(
            'no-store',
            (string) $signedResponse->headers->get('Cache-Control')
        );
    }

    public function test_authenticated_user_can_download_document(): void
    {
        $filename = 'test_auth_'.uniqid().'.pdf';
        file_put_contents($this->uploadsDir.DIRECTORY_SEPARATOR.$filename, '%PDF-1.4 auth');

        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_CLIENT_ADMIN]));

        $response = $this->get('/api/website-compliance/uploaded-documents/'.$filename);
        $response->assertOk();
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_upload_document_returns_protected_path_and_signed_url(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_CLIENT_ADMIN]));

        $file = UploadedFile::fake()->create('policy.pdf', 20, 'application/pdf');

        $response = $this->post('/api/website-compliance/upload-document', [
            'document' => $file,
        ], [
            'Accept' => 'application/json',
        ]);

        $response->assertCreated()
            ->assertJsonStructure(['url', 'relative_url', 'signed_url', 'filename']);

        $relative = (string) $response->json('relative_url');
        $this->assertStringContainsString('/website-compliance/uploaded-documents/', $relative);
        $this->assertStringNotContainsString('/uploaded-images/', $relative);

        $path = str_starts_with($relative, '/api/') ? $relative : '/api'.$relative;
        $this->get($path)->assertOk();
    }
}
