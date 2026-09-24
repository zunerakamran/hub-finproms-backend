<?php

namespace App\Services\WebsiteCompliance;

use App\Models\Hub;
use App\Models\User;
use App\Services\ActingHubService;
use App\Services\CapabilitiesMatrixService;
use App\Services\HubService;
use App\Support\WebsiteCompliance\WcDatabaseContext;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class WebsiteComplianceGate
{
    public function __construct(
        private readonly CapabilitiesMatrixService $matrix,
        private readonly ActingHubService $actingHubs,
        private readonly HubService $hubs
    ) {}

    public function assertModuleEnabled(User $user, ?string $wcCapability = null): Hub
    {
        $capability = $wcCapability ?: 'wc_edit_sections';
        $hub = $this->actingHubs->capabilityHub($user, $capability);

        if ($wcCapability === null) {
            if (! $hub->hasWebsiteComplianceModule() && ! $hub->hasWebsiteTemplateLibraryModule()) {
                throw ValidationException::withMessages([
                    'module' => 'Website Template Library / Content Pre Approval is not enabled for this hub.',
                ]);
            }

            return $hub;
        }

        if (! $this->moduleEnabledFor($hub, $wcCapability)) {
            $label = Hub::isWebsiteTemplateLibraryCapability($wcCapability)
                ? 'Website Template Library'
                : 'Website Content Pre Approval';

            throw ValidationException::withMessages([
                'module' => $label.' is not enabled for this hub.',
            ]);
        }

        return $hub;
    }

    public function moduleEnabledFor(Hub $hub, string $wcCapability): bool
    {
        if (Hub::isWebsiteTemplateLibraryCapability($wcCapability)) {
            return $hub->hasWebsiteTemplateLibraryModule();
        }

        return $hub->hasWebsiteComplianceModule();
    }

    public function can(User $user, string $wcCapability): bool
    {
        $hub = $this->actingHubs->capabilityHub($user, $wcCapability);

        if (! $this->moduleEnabledFor($hub, $wcCapability)) {
            return false;
        }

        // Power Admin / FinProms Admin control white-labelleds from shared — they are
        // never provisioned as users on the tenant DB, so grant full WC ops while acting.
        if ($this->isRemoteControlPlaneOperator($user)) {
            return true;
        }

        return $this->matrix->userCan($hub, $user, $wcCapability);
    }

    public function assertCan(User $user, string $wcCapability): void
    {
        $this->assertModuleEnabled($user, $wcCapability);

        if (! $this->can($user, $wcCapability)) {
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

    /**
     * Shared-hub control-plane operator currently switched onto a white-labelled hub.
     */
    public function isRemoteControlPlaneOperator(User $user): bool
    {
        if (! ActingHubService::isControlPlaneRole((string) $user->role)) {
            return false;
        }

        return $this->actingHubs->isActingOnWhiteLabel($user);
    }

    /**
     * User ids written into white-labelled wc_* FK columns.
     * Control-plane operators (PA / FinProms) do not exist on tenant DBs.
     */
    public function tenantUserIdOrNull(User $user): ?int
    {
        if (! WcDatabaseContext::active()) {
            return $user->id;
        }

        if ($this->isRemoteControlPlaneOperator($user) || ActingHubService::isControlPlaneRole((string) $user->role)) {
            return null;
        }

        return User::query()->whereKey($user->id)->exists() ? $user->id : null;
    }
}
