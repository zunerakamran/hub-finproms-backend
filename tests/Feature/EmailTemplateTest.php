<?php

namespace Tests\Feature;

use App\Models\Hub;
use App\Models\User;
use App\Services\CapabilitiesMatrixService;
use App\Services\EmailTemplateService;
use App\Support\EmailTemplateCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmailTemplateTest extends TestCase
{
    use RefreshDatabase;

    public function test_capabilities_matrix_includes_manage_email_templates(): void
    {
        $hub = $this->createSharedHub();
        $admin = User::factory()->powerAdmin()->create();
        Sanctum::actingAs($admin);

        $this->getJson('/api/power-admin/capabilities/matrix?hub_id='.$hub->id)
            ->assertOk()
            ->assertJsonFragment([
                'key' => 'dashboard_manage_email_templates',
                'label' => 'Manage email templates',
            ]);
    }

    public function test_authorized_role_can_list_and_update_email_templates(): void
    {
        $hub = $this->createSharedHub();
        $admin = User::factory()->powerAdmin()->create();
        Sanctum::actingAs($admin);

        $list = $this->getJson('/api/client-admin/email-templates');
        $list->assertOk()
            ->assertJsonPath('hub.slug', 'shared')
            ->assertJsonFragment(['key' => 'user_registered']);

        $keys = collect($list->json('events'))->pluck('key')->all();
        $this->assertContains('user_registered', $keys);
        $this->assertContains('order_confirmed', $keys);

        $show = $this->getJson('/api/client-admin/email-templates/user_registered');
        $show->assertOk()
            ->assertJsonPath('event.key', 'user_registered')
            ->assertJsonPath('event.templates.user.is_customized', false)
            ->assertJsonPath('event.templates.admin.is_customized', false);

        $update = $this->putJson('/api/client-admin/email-templates/user_registered/user', [
            'subject' => 'Hello {{user_name}} from {{site_name}}',
            'eyebrow' => 'Custom welcome',
            'heading' => 'Custom heading',
            'intro' => 'Custom intro for {{user_name}}',
            'closing' => 'Custom closing',
            'cta_label' => 'Open app',
        ]);

        $update->assertOk()
            ->assertJsonPath('template.is_customized', true)
            ->assertJsonPath('template.subject', 'Hello {{user_name}} from {{site_name}}')
            ->assertJsonPath('template.cta_label', 'Open app');

        $hub->refresh();
        $this->assertSame(
            'Hello {{user_name}} from {{site_name}}',
            $hub->email_templates['user_registered']['user']['subject']
        );

        $resolved = app(EmailTemplateService::class)->resolve(
            $hub,
            'user_registered',
            EmailTemplateCatalog::AUDIENCE_USER,
            ['user_name' => 'Ada', 'site_name' => 'Shared Hub', 'user_email' => 'a@b.c', 'support_email' => 'help@example.com']
        );
        $this->assertSame('Hello Ada from Shared Hub', $resolved['subject']);
        $this->assertSame('Custom intro for Ada', $resolved['intro']);

        $this->postJson('/api/client-admin/email-templates/user_registered/user/reset')
            ->assertOk()
            ->assertJsonPath('template.is_customized', false);

        $hub->refresh();
        $this->assertTrue(
            ! isset($hub->email_templates['user_registered']['user'])
            || $hub->email_templates === null
            || $hub->email_templates === []
        );
    }

    public function test_role_without_capability_cannot_manage_email_templates(): void
    {
        $hub = $this->createSharedHub();
        $matrix = app(CapabilitiesMatrixService::class);
        $roleCaps = $matrix->resolvedRoleCapabilities($hub);
        $roleCaps[User::ROLE_USER]['dashboard_manage_email_templates'] = false;
        $hub->role_capabilities = $roleCaps;
        $hub->save();

        $user = User::factory()->create(['role' => User::ROLE_USER]);
        Sanctum::actingAs($user);

        $this->getJson('/api/client-admin/email-templates')->assertForbidden();
        $this->putJson('/api/client-admin/email-templates/user_registered/user', [
            'subject' => 'Nope',
        ])->assertForbidden();
    }

    public function test_private_only_events_hidden_on_public_hub(): void
    {
        $hub = $this->createSharedHub();
        $admin = User::factory()->powerAdmin()->create();
        Sanctum::actingAs($admin);

        $keys = collect($this->getJson('/api/client-admin/email-templates')->json('events'))
            ->pluck('key')
            ->all();

        $this->assertNotContains('advisor_invite', $keys);
        $this->assertNotContains('advisor_billing_paid', $keys);
    }

    public function test_compliance_events_appear_when_module_enabled(): void
    {
        $checklist = Hub::defaultChecklist(Hub::TYPE_SHARED);
        $checklist['module_social_media_compliance'] = true;
        $hub = $this->createSharedHub(['checklist' => $checklist]);

        $admin = User::factory()->powerAdmin()->create();
        Sanctum::actingAs($admin);

        $keys = collect($this->getJson('/api/client-admin/email-templates')->json('events'))
            ->pluck('key')
            ->all();

        $this->assertContains('smc_request_submitted', $keys);
        $this->assertNotContains('gc_request_submitted', $keys);
    }

    private function createSharedHub(array $extra = []): Hub
    {
        return Hub::query()->create(array_merge([
            'name' => 'Shared Hub',
            'slug' => 'shared',
            'type' => Hub::TYPE_SHARED,
            'is_active' => true,
            'checklist' => Hub::defaultChecklist(Hub::TYPE_SHARED),
        ], $extra));
    }
}
