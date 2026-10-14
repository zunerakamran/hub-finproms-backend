<?php

namespace Tests\Feature;

use App\Models\Hub;
use App\Models\User;
use App\Services\CapabilitiesMatrixService;
use App\Services\FirewallService;
use App\Services\HubService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SecurityFirewallTest extends TestCase
{
    use RefreshDatabase;

    private function createHubWithSecurityMonitoring(): Hub
    {
        $hub = Hub::query()->create([
            'name' => 'Shared Hub',
            'slug' => 'shared',
            'type' => Hub::TYPE_SHARED,
            'is_active' => true,
            'checklist' => array_merge(Hub::defaultChecklist(Hub::TYPE_SHARED), [
                'security_monitoring' => true,
            ]),
        ]);

        $matrix = app(CapabilitiesMatrixService::class);
        $caps = $matrix->resolvedRoleCapabilities($hub);

        foreach (Hub::SECURITY_MONITORING_CAPABILITY_KEYS as $key) {
            $caps[User::ROLE_FINPROMS_ADMIN][$key] = true;
            $caps[User::ROLE_POWER_ADMIN][$key] = true;
        }

        $hub->role_capabilities = $caps;
        $hub->save();
        app(HubService::class)->forgetCurrentCache();

        return $hub;
    }

    public function test_denied_ip_receives_403(): void
    {
        config([
            'security_firewall.enabled' => true,
            'security_firewall.denied_ips' => ['203.0.113.50'],
            'security_firewall.record_security_events' => false,
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.50'])
            ->getJson('/api/hub')
            ->assertForbidden()
            ->assertJsonPath('message', 'Access denied by application firewall.');
    }

    public function test_temporary_ip_block_denies_requests(): void
    {
        config([
            'security_firewall.enabled' => true,
            'security_firewall.record_security_events' => false,
        ]);

        app(FirewallService::class)->blockIpTemporarily('198.51.100.9', 30);

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.9'])
            ->getJson('/api/hub')
            ->assertForbidden();
    }

    public function test_php_upload_is_rejected(): void
    {
        $this->createHubWithSecurityMonitoring();

        config([
            'security_firewall.enabled' => true,
            'security_firewall.upload_scan_enabled' => true,
            'security_firewall.malware_scan_enabled' => false,
            'security_firewall.record_security_events' => true,
        ]);

        $user = User::factory()->create(['role' => User::ROLE_USER]);
        Sanctum::actingAs($user);

        $evil = UploadedFile::fake()->create('shell.php', 10, 'application/x-php');

        $this->post('/api/auth/profile', [
            'name' => $user->name,
            'avatar' => $evil,
        ], ['Accept' => 'application/json'])
            ->assertStatus(422);

        $this->assertDatabaseHas('security_events', [
            'event_type' => 'data.upload_rejected',
        ]);
    }

    public function test_svg_upload_is_rejected_when_block_svg_enabled(): void
    {
        $this->createHubWithSecurityMonitoring();

        config([
            'security_firewall.enabled' => true,
            'security_firewall.upload_scan_enabled' => true,
            'security_firewall.block_svg' => true,
            'security_firewall.malware_scan_enabled' => false,
            'security_firewall.record_security_events' => true,
        ]);

        $user = User::factory()->create(['role' => User::ROLE_USER]);
        Sanctum::actingAs($user);

        $svg = UploadedFile::fake()->createWithContent(
            'icon.svg',
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'
        );

        $this->post('/api/auth/profile', [
            'name' => $user->name,
            'avatar' => $svg,
        ], ['Accept' => 'application/json'])
            ->assertStatus(422);
    }

    public function test_embedded_dangerous_extension_is_rejected(): void
    {
        config([
            'security_firewall.enabled' => true,
            'security_firewall.upload_scan_enabled' => true,
            'security_firewall.malware_scan_enabled' => false,
            'security_firewall.record_security_events' => false,
        ]);

        $user = User::factory()->create(['role' => User::ROLE_USER]);
        Sanctum::actingAs($user);

        $polyglot = UploadedFile::fake()->create('invoice.php.jpg', 20, 'image/jpeg');

        $this->post('/api/auth/profile', [
            'name' => $user->name,
            'avatar' => $polyglot,
        ], ['Accept' => 'application/json'])
            ->assertStatus(422);
    }
}
