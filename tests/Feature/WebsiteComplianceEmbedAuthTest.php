<?php

namespace Tests\Feature;

use App\Models\Hub;
use App\Models\User;
use App\Models\WebsiteCompliance\TemplateRequest;
use App\Services\HubService;
use App\Services\WhiteLabelDatabaseService;
use App\Support\TermsAndConditionsDefaults;
use App\Support\WcEmbedTicket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WebsiteComplianceEmbedAuthTest extends TestCase
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

    public function test_anonymous_hub_scoped_embed_is_unauthorized_before_remote_db(): void
    {
        $hub = Hub::query()->create([
            'name' => 'WL Remote',
            'slug' => 'wl-remote',
            'type' => Hub::TYPE_WHITE_LABEL,
            'is_active' => true,
            'db_driver' => 'mysql',
            'db_host' => '10.0.0.9',
            'db_database' => 'remote_wc',
            'db_username' => 'u',
            'db_password' => 'p',
            'checklist' => Hub::defaultChecklist(Hub::TYPE_WHITE_LABEL),
        ]);

        $this->mock(WhiteLabelDatabaseService::class, function ($mock) {
            $mock->shouldNotReceive('run');
        });

        $this->get('/api/website-compliance/embed-site/h/'.$hub->id.'/123')
            ->assertUnauthorized();
    }

    public function test_valid_embed_ticket_allows_hub_scoped_resolve_without_session(): void
    {
        Http::fake([
            'https://example.com/*' => Http::response('<html>ok</html>', 200, [
                'Content-Type' => 'text/html',
            ]),
        ]);

        $hub = Hub::query()->create([
            'name' => 'WL Remote',
            'slug' => 'wl-remote-2',
            'type' => Hub::TYPE_WHITE_LABEL,
            'is_active' => true,
            'db_driver' => 'mysql',
            'db_host' => '10.0.0.9',
            'db_database' => 'remote_wc',
            'db_username' => 'u',
            'db_password' => 'p',
            'checklist' => Hub::defaultChecklist(Hub::TYPE_WHITE_LABEL),
        ]);

        $tr = new TemplateRequest([
            'template_name' => 'classic',
            'request_type' => 'advisor_website',
            'status' => TemplateRequest::STATUS_LIVE,
            // example.com resolves publicly so SafeOutboundUrl DNS check passes.
            'cpanel_domain' => 'https://example.com',
        ]);
        $tr->id = 55;

        $this->mock(WhiteLabelDatabaseService::class, function ($mock) use ($hub, $tr) {
            $mock->shouldReceive('run')
                ->once()
                ->withArgs(function ($argHub) use ($hub) {
                    return (int) $argHub->id === (int) $hub->id;
                })
                ->andReturn($tr);
        });

        $ticket = WcEmbedTicket::issue((int) $hub->id, 55);

        $this->get('/api/website-compliance/embed-site/h/'.$hub->id.'/55?embed_ticket='.urlencode($ticket))
            ->assertOk()
            ->assertSee('ok', false);
    }

    public function test_issue_embed_ticket_requires_auth(): void
    {
        $this->postJson('/api/website-compliance/embed-ticket', [
            'hub_id' => 1,
            'template_request_id' => 1,
        ])->assertUnauthorized();
    }

    public function test_same_hub_user_can_mint_embed_ticket(): void
    {
        $hub = Hub::query()->where('slug', 'shared')->firstOrFail();
        $checklist = $hub->resolvedChecklist();
        $checklist['module_website_compliance'] = true;
        $checklist['module_website_template_library'] = true;
        $hub->checklist = $checklist;
        $roleCaps = is_array($hub->role_capabilities) ? $hub->role_capabilities : [];
        $roleCaps[User::ROLE_CLIENT_ADMIN]['wc_view_all_deployments'] = true;
        $hub->role_capabilities = $roleCaps;
        $hub->save();
        app(HubService::class)->forgetCurrentCache();

        $user = User::factory()->create(['role' => User::ROLE_CLIENT_ADMIN]);
        Sanctum::actingAs($user);

        $this->postJson('/api/website-compliance/embed-ticket', [
            'hub_id' => $hub->id,
            'template_request_id' => 9,
        ])
            ->assertOk()
            ->assertJsonPath('expires_in', WcEmbedTicket::TTL_SECONDS)
            ->assertJsonStructure(['ticket', 'embed_path']);
    }
}
