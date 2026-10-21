<?php

namespace Tests\Feature;

use App\Models\Hub;
use App\Models\WebsiteCompliance\TemplateRequest;
use App\Services\HubService;
use App\Support\TermsAndConditionsDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WebsiteComplianceEmbedSsrfTest extends TestCase
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
            'terms_and_conditions' => [
                'content' => TermsAndConditionsDefaults::shared(),
                'version' => 1,
                'updated_at' => now()->toIso8601String(),
            ],
        ]);
        app(HubService::class)->forgetCurrentCache();
    }

    public function test_embed_proxy_rejects_metadata_cpanel_domain(): void
    {
        Http::fake();

        $tr = TemplateRequest::query()->create([
            'template_name' => 'classic',
            'request_type' => 'advisor_website',
            'domain_name' => 'metadata-ssrf.example',
            'status' => TemplateRequest::STATUS_LIVE,
            'cpanel_domain' => 'http://169.254.169.254/',
        ]);

        $this->get('/api/website-compliance/embed-site/'.$tr->id)
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_embed_proxy_rejects_loopback_cpanel_domain(): void
    {
        Http::fake();

        $tr = TemplateRequest::query()->create([
            'template_name' => 'classic',
            'request_type' => 'advisor_website',
            'domain_name' => 'loopback-ssrf.example',
            'status' => TemplateRequest::STATUS_STAGING,
            'cpanel_domain' => 'http://127.0.0.1:8080',
        ]);

        $this->get('/api/website-compliance/embed-site/'.$tr->id)
            ->assertForbidden();

        Http::assertNothingSent();
    }
}
