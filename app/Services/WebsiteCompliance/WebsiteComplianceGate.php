<?php

namespace App\Services\WebsiteCompliance;

use App\Models\Hub;
use App\Models\User;
use App\Services\ActingHubService;
use App\Services\CapabilitiesMatrixService;
use App\Services\HubService;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class WebsiteComplianceGate
{
    public function __construct(
        private readonly CapabilitiesMatrixService $matrix,
        private readonly ActingHubService $actingHubs,
        private readonly HubService $hubs
    ) {}

    public function assertModuleEnabled(User $user): Hub
    {
        $hub = $this->actingHubs->capabilityHub($user, 'wc_edit_sections');

        if (! $hub->hasWebsiteComplianceModule()) {
            throw ValidationException::withMessages([
                'module' => 'Website Compliance is not enabled for this hub.',
            ]);
        }

        return $hub;
    }

    public function can(User $user, string $wcCapability): bool
    {
        $hub = $this->actingHubs->capabilityHub($user, $wcCapability);

        if (! $hub->hasWebsiteComplianceModule()) {
            return false;
        }

        return $this->matrix->roleCan($hub, (string) $user->role, $wcCapability);
    }

    public function assertCan(User $user, string $wcCapability): void
    {
        $hub = $this->assertModuleEnabled($user);

        if (! $this->matrix->roleCan($hub, (string) $user->role, $wcCapability)) {
            throw new HttpException(403, 'This capability is disabled for your role on this hub.');
        }
    }

    public function hubFor(User $user, string $wcCapability = 'wc_edit_sections'): Hub
    {
        return $this->actingHubs->capabilityHub($user, $wcCapability);
    }

    public function currentHub(): Hub
    {
        return $this->hubs->current();
    }
}
