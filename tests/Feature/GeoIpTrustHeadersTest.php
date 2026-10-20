<?php

namespace Tests\Feature;

use App\Models\Hub;
use App\Models\User;
use App\Services\CapabilitiesMatrixService;
use App\Services\GeoIpLookupService;
use App\Services\HubService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class GeoIpTrustHeadersTest extends TestCase
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
        }
        $hub->role_capabilities = $caps;
        $hub->save();
        app(HubService::class)->forgetCurrentCache();

        return $hub;
    }

    public function test_forged_geo_headers_are_ignored_without_trusted_proxy(): void
    {
        config([
            'security_firewall.geoip.trust_headers' => true,
            'security_firewall.geoip.require_trusted_proxy_for_headers' => true,
            'security_firewall.geoip.http_enabled' => false,
            'security_firewall.geoip.trust_generic_geo_headers' => false,
        ]);

        $request = Request::create('/', 'GET', server: ['REMOTE_ADDR' => '203.0.113.10']);
        $request->headers->set('CF-IPCountry', 'GB');
        $request->headers->set('X-App-Geo-Country', 'GB');
        $request->headers->set('X-Country-Code', 'GB');

        $geo = app(GeoIpLookupService::class)->lookup('203.0.113.10', $request);

        $this->assertNull($geo['country']);
        $this->assertNotSame('headers', $geo['source']);
    }

    public function test_geo_headers_accepted_when_force_trust_attribute_set(): void
    {
        config([
            'security_firewall.geoip.trust_headers' => true,
            'security_firewall.geoip.require_trusted_proxy_for_headers' => true,
            'security_firewall.geoip.http_enabled' => false,
        ]);

        $request = Request::create('/', 'GET', server: ['REMOTE_ADDR' => '203.0.113.11']);
        $request->headers->set('CF-IPCountry', 'PK');
        $request->attributes->set(GeoIpLookupService::ATTR_FORCE_TRUST_HEADERS, true);

        $geo = app(GeoIpLookupService::class)->lookup('203.0.113.11', $request);

        $this->assertSame('PK', $geo['country']);
        $this->assertSame('headers', $geo['source']);
    }

    public function test_generic_geo_headers_ignored_by_default(): void
    {
        config([
            'security_firewall.geoip.trust_headers' => true,
            'security_firewall.geoip.require_trusted_proxy_for_headers' => false,
            'security_firewall.geoip.trust_generic_geo_headers' => false,
            'security_firewall.geoip.http_enabled' => false,
        ]);

        $request = Request::create('/', 'GET', server: ['REMOTE_ADDR' => '203.0.113.12']);
        $request->headers->set('X-Country-Code', 'GB');
        $request->headers->set('X-City', 'London');

        $geo = app(GeoIpLookupService::class)->lookup('203.0.113.12', $request);

        $this->assertNull($geo['country']);
        $this->assertNull($geo['city']);
    }

    public function test_uk_only_cannot_be_bypassed_by_forged_cf_header_when_hardening_on(): void
    {
        $hub = $this->createHubWithSecurityMonitoring();

        app(\App\Services\SecurityMonitoringService::class)->settingsFor($hub);
        \App\Models\SecurityMonitoringSetting::query()->updateOrCreate(
            ['hub_id' => $hub->id],
            [
                'access_uk_only' => true,
                'access_uk_cities_enabled' => false,
                'access_restrict_countries_enabled' => false,
                'access_allow_countries_enabled' => false,
                'access_restrict_ips_enabled' => false,
                'access_allow_ips_enabled' => false,
                'access_restrict_vpn_enabled' => false,
                'access_allow_vpn_enabled' => false,
                'access_enforcement_mode' => 'enforce',
            ]
        );
        app(\App\Services\AccessRestrictionService::class)->forgetSettingsCache((int) $hub->id);

        config([
            'security_firewall.enabled' => true,
            'security_firewall.record_security_events' => false,
            'security_firewall.access_restriction_bypass_private_ips' => false,
            'security_firewall.access_restriction_admin_escape' => false,
            'security_firewall.geoip.http_enabled' => false,
            'security_firewall.geoip.trust_headers' => true,
            'security_firewall.geoip.require_trusted_proxy_for_headers' => true,
        ]);

        // Attacker claims GB via header while connecting from a non-UK test IP with no HTTP geo.
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.99'])
            ->withHeaders(['CF-IPCountry' => 'GB'])
            ->getJson('/api/hub')
            ->assertForbidden();
    }
}
