<?php

namespace Tests\Feature;

use App\Models\Hub;
use App\Models\User;
use App\Services\HubService;
use App\Support\TermsAndConditionsDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InputValidationSanitizationTest extends TestCase
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
            'privacy_policy' => [
                'content' => '<p>Privacy</p>',
                'version' => 1,
                'updated_at' => now()->toIso8601String(),
            ],
        ]);
        app(HubService::class)->forgetCurrentCache();
    }

    public function test_terms_update_strips_control_chars_and_scripts(): void
    {
        config([
            'security_app.sanitize_rich_html' => true,
            'security_app.sanitize_request_input' => true,
        ]);

        $admin = User::factory()->create([
            'role' => User::ROLE_FINPROMS_ADMIN,
            'email_verified_at' => now(),
            'terms_accepted_at' => now(),
            'terms_accepted_version' => 1,
        ]);
        Sanctum::actingAs($admin);

        $this->putJson('/api/client-admin/terms', [
            'content' => "<p>Clean\0</p><script>alert(1)</script>",
        ])
            ->assertOk()
            ->assertJsonPath('terms.version', 2);

        $content = (string) data_get(
            $this->getJson('/api/client-admin/terms')->json(),
            'terms.content',
            ''
        );

        $this->assertStringContainsString('Clean', $content);
        $this->assertStringNotContainsString("\0", $content);
        $this->assertStringNotContainsString('<script', $content);
    }

    public function test_privacy_update_rejects_empty_after_sanitize(): void
    {
        config(['security_app.sanitize_rich_html' => true]);

        $admin = User::factory()->create([
            'role' => User::ROLE_FINPROMS_ADMIN,
            'email_verified_at' => now(),
            'terms_accepted_at' => now(),
            'terms_accepted_version' => 1,
        ]);
        Sanctum::actingAs($admin);

        $this->putJson('/api/client-admin/privacy', [
            'content' => '<script>alert(1)</script>',
        ])->assertStatus(422);
    }

    public function test_register_strips_html_from_name(): void
    {
        $hub = Hub::query()->first();
        $checklist = is_array($hub->checklist) ? $hub->checklist : Hub::defaultChecklist(Hub::TYPE_SHARED);
        $checklist['public_subscribe'] = true;
        $checklist['private_invite_only'] = false;
        $hub->checklist = $checklist;
        $hub->save();
        app(HubService::class)->forgetCurrentCache();

        $this->postJson('/api/auth/register', [
            'name' => '<img src=x onerror=alert(1)>Alice',
            'email' => 'alice-nfr-val@example.test',
            'password' => 'CorrectHorseBattery1!',
            'password_confirmation' => 'CorrectHorseBattery1!',
        ])->assertCreated();

        $this->assertDatabaseHas('users', [
            'email' => 'alice-nfr-val@example.test',
            'name' => 'Alice',
        ]);
    }
}
