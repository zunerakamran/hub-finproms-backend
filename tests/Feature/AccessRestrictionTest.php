<?php

namespace Tests\Feature;

use App\Models\Hub;
use App\Models\SecurityMonitoringSetting;
use App\Models\User;
use App\Services\AccessRestrictionService;
use App\Services\CapabilitiesMatrixService;
use App\Services\HubService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccessRestrictionTest extends TestCase
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
            $caps[User::ROLE_USER][$key] = false;
        }

        $hub->role_capabilities = $caps;
        $hub->save();
        app(HubService::class)->forgetCurrentCache();

        return $hub;
    }

    private function setAccessRules(Hub $hub, string $mode, array $extra = []): void
    {
        SecurityMonitoringSetting::query()->updateOrCreate(
            ['hub_id' => $hub->id],
            array_merge([
                'access_restriction_mode' => $mode,
                'access_restriction_countries' => [],
                'access_restriction_cities' => [],
                'access_restriction_ips' => [],
            ], $extra)
        );

        app(AccessRestrictionService::class)->forgetSettingsCache((int) $hub->id);
    }

    public function test_capability_key_is_registered(): void
    {
        $this->assertContains('secmon_manage_access', Hub::SECURITY_MONITORING_CAPABILITY_KEYS);
        $this->assertSame(
            Hub::GROUP_SECURITY_MONITORING,
            Hub::CHECKLIST_DEFINITIONS['secmon_manage_access']['group']
        );
    }

    public function test_admin_can_get_and_update_access_settings(): void
    {
        $this->createHubWithSecurityMonitoring();
        $admin = User::factory()->create(['role' => User::ROLE_FINPROMS_ADMIN]);
        Sanctum::actingAs($admin);

        $this->getJson('/api/client-admin/security-monitoring/access-settings')
            ->assertOk()
            ->assertJsonPath('data.mode', 'off')
            ->assertJsonStructure([
                'data' => ['mode', 'countries', 'cities', 'ips'],
                'options' => ['modes', 'suggested_uk_cities'],
            ]);

        $this->putJson('/api/client-admin/security-monitoring/access-settings', [
            'mode' => 'uk_only',
            'countries' => [],
            'cities' => [],
            'ips' => [],
        ])
            ->assertOk()
            ->assertJsonPath('data.mode', 'uk_only');

        $this->putJson('/api/client-admin/security-monitoring/access-settings', [
            'mode' => 'allow_countries',
            'countries' => ['GB', 'IE'],
        ])
            ->assertOk()
            ->assertJsonPath('data.mode', 'allow_countries')
            ->assertJsonPath('data.countries', ['GB', 'IE']);
    }

    public function test_uk_only_blocks_non_uk_country(): void
    {
        $hub = $this->createHubWithSecurityMonitoring();
        $this->setAccessRules($hub, AccessRestrictionService::MODE_UK_ONLY);

        config([
            'security_firewall.enabled' => true,
            'security_firewall.record_security_events' => false,
            'security_firewall.geoip.http_enabled' => false,
            'security_firewall.access_restriction_bypass_private_ips' => true,
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.40'])
            ->withHeaders(['CF-IPCountry' => 'US'])
            ->getJson('/api/hub')
            ->assertForbidden()
            ->assertJsonPath('message', 'Access denied by application access restrictions.');

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.41'])
            ->withHeaders(['CF-IPCountry' => 'GB'])
            ->getJson('/api/hub')
            ->assertOk();
    }

    public function test_uk_cities_requires_matching_city(): void
    {
        $hub = $this->createHubWithSecurityMonitoring();
        $this->setAccessRules($hub, AccessRestrictionService::MODE_UK_CITIES, [
            'access_restriction_cities' => ['London', 'Manchester'],
        ]);

        config([
            'security_firewall.enabled' => true,
            'security_firewall.record_security_events' => false,
            'security_firewall.geoip.http_enabled' => false,
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.50'])
            ->withHeaders(['CF-IPCountry' => 'GB', 'CF-IPCity' => 'Birmingham'])
            ->getJson('/api/hub')
            ->assertForbidden();

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.51'])
            ->withHeaders(['CF-IPCountry' => 'GB', 'CF-IPCity' => 'London'])
            ->getJson('/api/hub')
            ->assertOk();
    }

    public function test_restrict_and_allow_countries(): void
    {
        $hub = $this->createHubWithSecurityMonitoring();

        config([
            'security_firewall.enabled' => true,
            'security_firewall.record_security_events' => false,
            'security_firewall.geoip.http_enabled' => false,
        ]);

        $this->setAccessRules($hub, AccessRestrictionService::MODE_RESTRICT_COUNTRIES, [
            'access_restriction_countries' => ['RU', 'CN'],
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.60'])
            ->withHeaders(['CF-IPCountry' => 'RU'])
            ->getJson('/api/hub')
            ->assertForbidden();

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.61'])
            ->withHeaders(['CF-IPCountry' => 'DE'])
            ->getJson('/api/hub')
            ->assertOk();

        $this->setAccessRules($hub, AccessRestrictionService::MODE_ALLOW_COUNTRIES, [
            'access_restriction_countries' => ['GB'],
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.62'])
            ->withHeaders(['CF-IPCountry' => 'DE'])
            ->getJson('/api/hub')
            ->assertForbidden();

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.63'])
            ->withHeaders(['CF-IPCountry' => 'GB'])
            ->getJson('/api/hub')
            ->assertOk();
    }

    public function test_restrict_and_allow_ips(): void
    {
        $hub = $this->createHubWithSecurityMonitoring();

        config([
            'security_firewall.enabled' => true,
            'security_firewall.record_security_events' => false,
            'security_firewall.geoip.http_enabled' => false,
        ]);

        $this->setAccessRules($hub, AccessRestrictionService::MODE_RESTRICT_IPS, [
            'access_restriction_ips' => ['203.0.113.70', '198.51.100.0/24'],
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.70'])
            ->getJson('/api/hub')
            ->assertForbidden();

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.9'])
            ->getJson('/api/hub')
            ->assertForbidden();

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.99'])
            ->getJson('/api/hub')
            ->assertOk();

        $this->setAccessRules($hub, AccessRestrictionService::MODE_ALLOW_IPS, [
            'access_restriction_ips' => ['203.0.113.80'],
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.81'])
            ->getJson('/api/hub')
            ->assertForbidden();

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.80'])
            ->getJson('/api/hub')
            ->assertOk();
    }

    public function test_regular_user_cannot_manage_access_settings(): void
    {
        $this->createHubWithSecurityMonitoring();
        $user = User::factory()->create(['role' => User::ROLE_USER]);
        Sanctum::actingAs($user);

        $this->getJson('/api/client-admin/security-monitoring/access-settings')
            ->assertForbidden();
    }

    public function test_update_requires_cities_for_uk_cities_mode(): void
    {
        $this->createHubWithSecurityMonitoring();
        $admin = User::factory()->create(['role' => User::ROLE_FINPROMS_ADMIN]);
        Sanctum::actingAs($admin);

        $this->putJson('/api/client-admin/security-monitoring/access-settings', [
            'mode' => 'uk_cities',
            'cities' => [],
        ])->assertStatus(422);
    }
}
