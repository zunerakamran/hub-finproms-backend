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

    private function setAccessRules(Hub $hub, array $rules): void
    {
        $defaults = app(AccessRestrictionService::class)->emptySettings();

        SecurityMonitoringSetting::query()->updateOrCreate(
            ['hub_id' => $hub->id],
            [
                'access_uk_only' => (bool) ($rules['uk_only'] ?? $defaults['uk_only']),
                'access_uk_cities_enabled' => (bool) ($rules['uk_cities_enabled'] ?? $defaults['uk_cities_enabled']),
                'access_restriction_cities' => $rules['uk_cities'] ?? [],
                'access_restrict_countries_enabled' => (bool) ($rules['restrict_countries_enabled'] ?? false),
                'access_restricted_countries' => $rules['restricted_countries'] ?? [],
                'access_allow_countries_enabled' => (bool) ($rules['allow_countries_enabled'] ?? false),
                'access_allowed_countries' => $rules['allowed_countries'] ?? [],
                'access_restrict_ips_enabled' => (bool) ($rules['restrict_ips_enabled'] ?? false),
                'access_restricted_ips' => $rules['restricted_ips'] ?? [],
                'access_allow_ips_enabled' => (bool) ($rules['allow_ips_enabled'] ?? false),
                'access_allowed_ips' => $rules['allowed_ips'] ?? [],
                'access_restrict_vpn_enabled' => (bool) ($rules['restrict_vpn_enabled'] ?? false),
                'access_allow_vpn_enabled' => (bool) ($rules['allow_vpn_enabled'] ?? false),
            ]
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

    public function test_admin_can_get_and_update_layered_access_settings(): void
    {
        $this->createHubWithSecurityMonitoring();
        $admin = User::factory()->create(['role' => User::ROLE_FINPROMS_ADMIN]);
        Sanctum::actingAs($admin);

        $this->getJson('/api/client-admin/security-monitoring/access-settings')
            ->assertOk()
            ->assertJsonPath('data.uk_only', false)
            ->assertJsonStructure([
                'data' => [
                    'uk_only',
                    'uk_cities_enabled',
                    'uk_cities',
                    'restrict_countries_enabled',
                    'restricted_countries',
                    'allow_countries_enabled',
                    'allowed_countries',
                    'restrict_ips_enabled',
                    'restricted_ips',
                    'allow_ips_enabled',
                    'allowed_ips',
                    'restrict_vpn_enabled',
                    'allow_vpn_enabled',
                ],
                'options' => ['rules', 'suggested_uk_cities'],
            ]);

        $this->putJson('/api/client-admin/security-monitoring/access-settings', [
            'uk_only' => true,
            'restrict_ips_enabled' => true,
            'restricted_ips' => ['203.0.113.10'],
            'uk_cities_enabled' => false,
            'restrict_countries_enabled' => false,
            'allow_countries_enabled' => false,
            'allow_ips_enabled' => false,
            'restrict_vpn_enabled' => true,
            'allow_vpn_enabled' => false,
            'uk_cities' => [],
            'restricted_countries' => [],
            'allowed_countries' => [],
            'allowed_ips' => [],
        ])
            ->assertOk()
            ->assertJsonPath('data.uk_only', true)
            ->assertJsonPath('data.restrict_ips_enabled', true)
            ->assertJsonPath('data.restricted_ips', ['203.0.113.10'])
            ->assertJsonPath('data.restrict_vpn_enabled', true)
            ->assertJsonPath('data.allow_vpn_enabled', false);
    }

    public function test_uk_only_blocks_non_uk_country(): void
    {
        $hub = $this->createHubWithSecurityMonitoring();
        $this->setAccessRules($hub, ['uk_only' => true]);

        config([
            'security_firewall.enabled' => true,
            'security_firewall.record_security_events' => false,
            'security_firewall.geoip.http_enabled' => false,
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

    public function test_layered_uk_only_and_restrict_ip(): void
    {
        $hub = $this->createHubWithSecurityMonitoring();
        $this->setAccessRules($hub, [
            'uk_only' => true,
            'restrict_ips_enabled' => true,
            'restricted_ips' => ['203.0.113.70'],
        ]);

        config([
            'security_firewall.enabled' => true,
            'security_firewall.record_security_events' => false,
            'security_firewall.geoip.http_enabled' => false,
        ]);

        // UK but blocked IP
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.70'])
            ->withHeaders(['CF-IPCountry' => 'GB'])
            ->getJson('/api/hub')
            ->assertForbidden();

        // UK and allowed IP
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.71'])
            ->withHeaders(['CF-IPCountry' => 'GB'])
            ->getJson('/api/hub')
            ->assertOk();

        // Non-UK (even if IP not restricted)
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.72'])
            ->withHeaders(['CF-IPCountry' => 'DE'])
            ->getJson('/api/hub')
            ->assertForbidden();
    }

    public function test_allow_ip_overrides_restricted_country(): void
    {
        $hub = $this->createHubWithSecurityMonitoring();
        $this->setAccessRules($hub, [
            'restrict_countries_enabled' => true,
            'restricted_countries' => ['PK'],
            'allow_ips_enabled' => true,
            'allowed_ips' => ['203.0.113.200'],
        ]);

        config([
            'security_firewall.enabled' => true,
            'security_firewall.record_security_events' => false,
            'security_firewall.geoip.http_enabled' => false,
        ]);

        // Allowed IP from restricted country → override, allow
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.200'])
            ->withHeaders(['CF-IPCountry' => 'PK'])
            ->getJson('/api/hub')
            ->assertOk();

        // Other IP from restricted country → still blocked
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.201'])
            ->withHeaders(['CF-IPCountry' => 'PK'])
            ->getJson('/api/hub')
            ->assertForbidden();

        // Non-restricted country (not on allow IP list) → still allowed by geo
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.202'])
            ->withHeaders(['CF-IPCountry' => 'GB'])
            ->getJson('/api/hub')
            ->assertOk();
    }

    public function test_allow_ip_overrides_uk_only(): void
    {
        $hub = $this->createHubWithSecurityMonitoring();
        $this->setAccessRules($hub, [
            'uk_only' => true,
            'allow_ips_enabled' => true,
            'allowed_ips' => ['203.0.113.210'],
        ]);

        config([
            'security_firewall.enabled' => true,
            'security_firewall.record_security_events' => false,
            'security_firewall.geoip.http_enabled' => false,
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.210'])
            ->withHeaders(['CF-IPCountry' => 'US'])
            ->getJson('/api/hub')
            ->assertOk();

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.211'])
            ->withHeaders(['CF-IPCountry' => 'US'])
            ->getJson('/api/hub')
            ->assertForbidden();

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.212'])
            ->withHeaders(['CF-IPCountry' => 'GB'])
            ->getJson('/api/hub')
            ->assertOk();
    }

    public function test_allow_country_overrides_restricted_country(): void
    {
        $hub = $this->createHubWithSecurityMonitoring();
        $this->setAccessRules($hub, [
            'restrict_countries_enabled' => true,
            'restricted_countries' => ['PK', 'IN'],
            'allow_countries_enabled' => true,
            'allowed_countries' => ['PK', 'GB'],
        ]);

        config([
            'security_firewall.enabled' => true,
            'security_firewall.record_security_events' => false,
            'security_firewall.geoip.http_enabled' => false,
        ]);

        // PK is restricted but also explicitly allowed → allow
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.220'])
            ->withHeaders(['CF-IPCountry' => 'PK'])
            ->getJson('/api/hub')
            ->assertOk();

        // IN restricted and not allowed → deny
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.221'])
            ->withHeaders(['CF-IPCountry' => 'IN'])
            ->getJson('/api/hub')
            ->assertForbidden();

        // GB allowed
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.222'])
            ->withHeaders(['CF-IPCountry' => 'GB'])
            ->getJson('/api/hub')
            ->assertOk();
    }

    public function test_restrict_ip_carves_out_allowed_country(): void
    {
        // Allow country PK + Restrict IP → that IP blocked; rest of PK allowed.
        $hub = $this->createHubWithSecurityMonitoring();
        $this->setAccessRules($hub, [
            'allow_countries_enabled' => true,
            'allowed_countries' => ['PK'],
            'restrict_ips_enabled' => true,
            'restricted_ips' => ['203.0.113.50'],
        ]);

        config([
            'security_firewall.enabled' => true,
            'security_firewall.record_security_events' => false,
            'security_firewall.geoip.http_enabled' => false,
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.50'])
            ->withHeaders(['CF-IPCountry' => 'PK'])
            ->getJson('/api/hub')
            ->assertForbidden();

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.51'])
            ->withHeaders(['CF-IPCountry' => 'PK'])
            ->getJson('/api/hub')
            ->assertOk();

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.52'])
            ->withHeaders(['CF-IPCountry' => 'GB'])
            ->getJson('/api/hub')
            ->assertForbidden();
    }

    public function test_restrict_ip_carves_out_uk_only(): void
    {
        $hub = $this->createHubWithSecurityMonitoring();
        $this->setAccessRules($hub, [
            'uk_only' => true,
            'restrict_ips_enabled' => true,
            'restricted_ips' => ['203.0.113.60'],
        ]);

        config([
            'security_firewall.enabled' => true,
            'security_firewall.record_security_events' => false,
            'security_firewall.geoip.http_enabled' => false,
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.60'])
            ->withHeaders(['CF-IPCountry' => 'GB'])
            ->getJson('/api/hub')
            ->assertForbidden();

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.61'])
            ->withHeaders(['CF-IPCountry' => 'GB'])
            ->getJson('/api/hub')
            ->assertOk();
    }

    public function test_restrict_ip_carves_out_uk_cities(): void
    {
        $hub = $this->createHubWithSecurityMonitoring();
        $this->setAccessRules($hub, [
            'uk_cities_enabled' => true,
            'uk_cities' => ['London'],
            'restrict_ips_enabled' => true,
            'restricted_ips' => ['203.0.113.70'],
        ]);

        config([
            'security_firewall.enabled' => true,
            'security_firewall.record_security_events' => false,
            'security_firewall.geoip.http_enabled' => false,
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.70'])
            ->withHeaders(['CF-IPCountry' => 'GB', 'CF-IPCity' => 'London'])
            ->getJson('/api/hub')
            ->assertForbidden();

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.71'])
            ->withHeaders(['CF-IPCountry' => 'GB', 'CF-IPCity' => 'London'])
            ->getJson('/api/hub')
            ->assertOk();
    }

    public function test_restrict_ip_beats_allow_ip_when_same_ip(): void
    {
        $hub = $this->createHubWithSecurityMonitoring();
        $this->setAccessRules($hub, [
            'allow_ips_enabled' => true,
            'allowed_ips' => ['203.0.113.80'],
            'restrict_ips_enabled' => true,
            'restricted_ips' => ['203.0.113.80'],
        ]);

        config([
            'security_firewall.enabled' => true,
            'security_firewall.record_security_events' => false,
            'security_firewall.geoip.http_enabled' => false,
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.80'])
            ->getJson('/api/hub')
            ->assertForbidden();
    }

    public function test_allow_country_and_allow_ip_and_restrict_ip_combined(): void
    {
        // Allowed country PK; one bad IP blocked; one VIP IP from blocked country IN allowed.
        $hub = $this->createHubWithSecurityMonitoring();
        $this->setAccessRules($hub, [
            'allow_countries_enabled' => true,
            'allowed_countries' => ['PK'],
            'restrict_countries_enabled' => true,
            'restricted_countries' => ['IN'],
            'restrict_ips_enabled' => true,
            'restricted_ips' => ['203.0.113.90'],
            'allow_ips_enabled' => true,
            'allowed_ips' => ['203.0.113.91'],
        ]);

        config([
            'security_firewall.enabled' => true,
            'security_firewall.record_security_events' => false,
            'security_firewall.geoip.http_enabled' => false,
        ]);

        // Restricted IP inside allowed country → blocked
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.90'])
            ->withHeaders(['CF-IPCountry' => 'PK'])
            ->getJson('/api/hub')
            ->assertForbidden();

        // Normal IP in allowed country → allowed
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.92'])
            ->withHeaders(['CF-IPCountry' => 'PK'])
            ->getJson('/api/hub')
            ->assertOk();

        // Allowed IP from restricted country → allowed (IP override)
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.91'])
            ->withHeaders(['CF-IPCountry' => 'IN'])
            ->getJson('/api/hub')
            ->assertOk();

        // Other IP from restricted country → blocked
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.93'])
            ->withHeaders(['CF-IPCountry' => 'IN'])
            ->getJson('/api/hub')
            ->assertForbidden();
    }

    public function test_uk_cities_requires_matching_city(): void
    {
        $hub = $this->createHubWithSecurityMonitoring();
        $this->setAccessRules($hub, [
            'uk_cities_enabled' => true,
            'uk_cities' => ['London', 'Manchester'],
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

        $this->setAccessRules($hub, [
            'restrict_countries_enabled' => true,
            'restricted_countries' => ['RU', 'CN'],
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.60'])
            ->withHeaders(['CF-IPCountry' => 'RU'])
            ->getJson('/api/hub')
            ->assertForbidden();

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.61'])
            ->withHeaders(['CF-IPCountry' => 'DE'])
            ->getJson('/api/hub')
            ->assertOk();

        $this->setAccessRules($hub, [
            'allow_countries_enabled' => true,
            'allowed_countries' => ['GB'],
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

        $this->setAccessRules($hub, [
            'restrict_ips_enabled' => true,
            'restricted_ips' => ['203.0.113.70', '198.51.100.0/24'],
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

        $this->setAccessRules($hub, [
            'allow_ips_enabled' => true,
            'allowed_ips' => ['203.0.113.80'],
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

    public function test_update_requires_cities_when_uk_cities_enabled(): void
    {
        $this->createHubWithSecurityMonitoring();
        $admin = User::factory()->create(['role' => User::ROLE_FINPROMS_ADMIN]);
        Sanctum::actingAs($admin);

        $this->putJson('/api/client-admin/security-monitoring/access-settings', [
            'uk_cities_enabled' => true,
            'uk_cities' => [],
        ])->assertStatus(422);
    }

    public function test_restrict_vpn_blocks_detected_vpn(): void
    {
        $hub = $this->createHubWithSecurityMonitoring();
        $this->setAccessRules($hub, [
            'restrict_vpn_enabled' => true,
        ]);

        config([
            'security_firewall.enabled' => true,
            'security_firewall.record_security_events' => false,
            'security_firewall.geoip.http_enabled' => false,
            'security_firewall.geoip.test_overrides' => [
                '203.0.113.40' => ['country' => 'GB', 'vpn' => true],
                '203.0.113.41' => ['country' => 'GB', 'vpn' => false],
            ],
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.40'])
            ->getJson('/api/hub')
            ->assertForbidden();

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.41'])
            ->getJson('/api/hub')
            ->assertOk();
    }

    public function test_allow_ip_overrides_restrict_vpn(): void
    {
        $hub = $this->createHubWithSecurityMonitoring();
        $this->setAccessRules($hub, [
            'restrict_vpn_enabled' => true,
            'allow_ips_enabled' => true,
            'allowed_ips' => ['203.0.113.50'],
        ]);

        config([
            'security_firewall.enabled' => true,
            'security_firewall.record_security_events' => false,
            'security_firewall.geoip.http_enabled' => false,
            'security_firewall.geoip.test_overrides' => [
                '203.0.113.50' => ['country' => 'US', 'vpn' => true],
                '203.0.113.51' => ['country' => 'US', 'vpn' => true],
            ],
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.50'])
            ->getJson('/api/hub')
            ->assertOk();

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.51'])
            ->getJson('/api/hub')
            ->assertForbidden();
    }

    public function test_allow_vpn_overrides_uk_only(): void
    {
        $hub = $this->createHubWithSecurityMonitoring();
        $this->setAccessRules($hub, [
            'uk_only' => true,
            'allow_vpn_enabled' => true,
        ]);

        config([
            'security_firewall.enabled' => true,
            'security_firewall.record_security_events' => false,
            'security_firewall.geoip.http_enabled' => false,
            'security_firewall.geoip.test_overrides' => [
                '203.0.113.60' => ['country' => 'US', 'vpn' => true],
                '203.0.113.61' => ['country' => 'US', 'vpn' => false],
                '203.0.113.62' => ['country' => 'GB', 'vpn' => false],
            ],
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.60'])
            ->getJson('/api/hub')
            ->assertOk();

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.61'])
            ->getJson('/api/hub')
            ->assertForbidden();

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.62'])
            ->getJson('/api/hub')
            ->assertOk();
    }

    public function test_allow_vpn_alone_requires_vpn(): void
    {
        $hub = $this->createHubWithSecurityMonitoring();
        $this->setAccessRules($hub, [
            'allow_vpn_enabled' => true,
        ]);

        config([
            'security_firewall.enabled' => true,
            'security_firewall.record_security_events' => false,
            'security_firewall.geoip.http_enabled' => false,
            'security_firewall.geoip.test_overrides' => [
                '203.0.113.70' => ['country' => 'GB', 'vpn' => true],
                '203.0.113.71' => ['country' => 'GB', 'vpn' => false],
            ],
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.70'])
            ->getJson('/api/hub')
            ->assertOk();

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.71'])
            ->getJson('/api/hub')
            ->assertForbidden();
    }

    public function test_restrict_vpn_beats_allow_vpn(): void
    {
        $hub = $this->createHubWithSecurityMonitoring();
        $this->setAccessRules($hub, [
            'restrict_vpn_enabled' => true,
            'allow_vpn_enabled' => true,
        ]);

        config([
            'security_firewall.enabled' => true,
            'security_firewall.record_security_events' => false,
            'security_firewall.geoip.http_enabled' => false,
            'security_firewall.geoip.test_overrides' => [
                '203.0.113.80' => ['country' => 'GB', 'vpn' => true],
            ],
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.80'])
            ->getJson('/api/hub')
            ->assertForbidden();
    }
}
