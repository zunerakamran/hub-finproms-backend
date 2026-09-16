<?php

namespace App\Services;

use App\Models\Hub;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Shared-hub "acting hub" context for Control white labelled hubs.
 * Selecting a white-label hub scopes dashboard users and content tools to that hub's DB.
 */
class ActingHubService
{
    public const CAPABILITY = 'dashboard_control_white_label_hubs';

    /**
     * These roles only exist as users on the shared hub. The hub switcher
     * capability (`dashboard_control_white_label_hubs`) always lives on shared.
     * All other hub dashboard / compliance matrix cells for these roles are
     * stored per hub — including each white-label — so the navbar follows the
     * selected hub's Capabilities matrix (see PROJECT_REQUIREMENTS §4.4).
     *
     * @var list<string>
     */
    public const CONTROL_PLANE_ROLES = [
        User::ROLE_POWER_ADMIN,
        User::ROLE_FINPROMS_ADMIN,
    ];

    public function __construct(
        private readonly HubService $hubs,
        private readonly CapabilitiesMatrixService $matrix,
        private readonly WhiteLabelDatabaseService $remoteDb
    ) {}

    public function canControl(User $user): bool
    {
        $current = $this->hubs->current();
        if (! $current->isShared()) {
            return false;
        }

        return $this->matrix->roleCan($current, (string) $user->role, self::CAPABILITY);
    }

    /**
     * Hub the user is currently operating (shared by default).
     */
    public function actingHub(User $user): Hub
    {
        $current = $this->hubs->current();
        if (! $this->canControl($user)) {
            return $current;
        }

        $hubId = $user->acting_hub_id;
        if (! $hubId) {
            return $current;
        }

        $hub = Hub::query()->find($hubId);
        if (! $hub || ! $hub->is_active) {
            $this->clearActingHub($user);

            return $current;
        }

        if ($hub->isShared()) {
            return $current;
        }

        return $hub;
    }

    public function isActingOnWhiteLabel(User $user): bool
    {
        if (! $this->canControl($user)) {
            return false;
        }

        $hub = $this->actingHub($user);

        return $hub->isWhiteLabel();
    }

    public static function isControlPlaneRole(string $role): bool
    {
        return in_array($role, self::CONTROL_PLANE_ROLES, true);
    }

    /**
     * Hub whose data this request should read/write.
     * Explicit hub_id wins; otherwise the switcher white-label hub; otherwise this deploy.
     */
    public function targetHub(?User $user, ?int $hubId = null): Hub
    {
        if ($hubId) {
            $hub = Hub::query()->find($hubId);
            if (! $hub) {
                throw new HttpException(404, 'Hub not found.');
            }

            return $hub;
        }

        if ($user && $this->isActingOnWhiteLabel($user)) {
            return $this->actingHub($user);
        }

        return $this->hubs->current();
    }

    /**
     * Capability checks for hub-level flags follow the acting white-label hub.
     * Only the shared-hub switcher capability stays on the shared deploy hub.
     */
    public function capabilityHub(User $user, string $capability): Hub
    {
        $current = $this->hubs->current();

        // Hub switcher itself is always a shared-hub capability.
        if ($capability === self::CAPABILITY) {
            return $current;
        }

        if (! $this->isActingOnWhiteLabel($user)) {
            return $current;
        }

        // While a white-label is selected, modules / functionalities / role
        // matrix cells (including Power Admin / FinProms) follow that hub.
        if (Hub::isModuleKey($capability)
            || Hub::isFunctionalityKey($capability)
            || Hub::isCapabilityKey($capability)
        ) {
            return $this->actingHub($user);
        }

        return $current;
    }

    /**
     * @throws HttpException|InvalidArgumentException
     */
    public function setActingHub(User $user, ?int $hubId): Hub
    {
        if (! $this->canControl($user)) {
            throw new HttpException(
                403,
                'Enable “Control white labelled hubs” in Capabilities to use the hub switcher.'
            );
        }

        $current = $this->hubs->current();

        if ($hubId === null || $hubId === (int) $current->id) {
            $this->clearActingHub($user);

            return $current->fresh() ?? $current;
        }

        $hub = Hub::query()->findOrFail($hubId);
        $this->assertSelectable($hub);

        $user->forceFill(['acting_hub_id' => $hub->id])->save();
        $this->forgetCache($user);

        return $hub;
    }

    public function clearActingHub(User $user): void
    {
        if ($user->acting_hub_id !== null) {
            $user->forceFill(['acting_hub_id' => null])->save();
        }
        $this->forgetCache($user);
    }

    /**
     * Hubs shown in the switcher (shared + white-labels).
     *
     * @return list<array<string, mixed>>
     */
    public function switcherHubs(): array
    {
        $shared = $this->hubs->current();
        $items = [];

        if ($shared->isShared()) {
            $items[] = [
                'id' => $shared->id,
                'name' => $shared->name,
                'slug' => $shared->slug,
                'type' => Hub::TYPE_SHARED,
                'eligible' => true,
                'label' => $shared->name.' (shared)',
            ];
        }

        foreach (
            Hub::query()
                ->where('type', Hub::TYPE_WHITE_LABEL)
                ->where('is_active', true)
                ->orderBy('name')
                ->get() as $hub
        ) {
            $eligible = $hub->can('receive_content_from_shared') && $hub->hasRemoteDatabaseConfigured();
            $items[] = [
                'id' => $hub->id,
                'name' => $hub->name,
                'slug' => $hub->slug,
                'type' => Hub::TYPE_WHITE_LABEL,
                'eligible' => $eligible,
                'label' => $hub->name.($eligible ? '' : ' (not ready)'),
                'reason' => $eligible ? null : $this->ineligibleReason($hub),
            ];
        }

        return $items;
    }

    /**
     * Effective capabilities for the dashboard while a hub is selected.
     * Hub modules / functionalities / role matrix cells follow the acting
     * tenant. Only `dashboard_control_white_label_hubs` stays on shared.
     *
     * @return array<string, bool>
     */
    public function effectiveCapabilities(User $user): array
    {
        $current = $this->hubs->current();
        $acting = $this->actingHub($user);
        $role = (string) $user->role;

        $flags = array_keys($acting->resolvedChecklist());
        $effective = [];

        foreach ($flags as $flag) {
            $hubForFlag = $this->capabilityHub($user, $flag);
            $effective[$flag] = $this->matrix->roleCan($hubForFlag, $role, $flag);
        }

        // Always expose the control flag from the shared deploy hub.
        $effective[self::CAPABILITY] = $this->matrix->roleCan($current, $role, self::CAPABILITY);

        if ($user->isPowerAdmin()) {
            foreach (app(PowerAdminCapabilitiesService::class)->resolved() as $key => $enabled) {
                $effective[$key] = $enabled;
            }
        }

        return $effective;
    }

    /**
     * Full switcher payload for dashboard / GET /hub.
     *
     * @return array<string, mixed>|null
     */
    public function switcherPayload(?User $user): ?array
    {
        if (! $user || ! $this->canControl($user)) {
            return null;
        }

        $current = $this->hubs->current();
        $acting = $this->actingHub($user);

        return [
            'enabled' => true,
            'capability' => self::CAPABILITY,
            'hubs' => $this->switcherHubs(),
            'acting_hub' => [
                'id' => $acting->id,
                'name' => $acting->name,
                'slug' => $acting->slug,
                'type' => $acting->type,
                'is_white_label' => $acting->isWhiteLabel(),
                'branding' => $acting->brandingPayload(),
            ],
            'is_acting_on_white_label' => $acting->isWhiteLabel(),
            'shared_hub' => [
                'id' => $current->id,
                'name' => $current->name,
                'slug' => $current->slug,
            ],
            'effective_capabilities' => $this->effectiveCapabilities($user),
        ];
    }

    /**
     * Resolve and assert the acting white-label hub for content writes.
     */
    public function requireActingWhiteLabel(User $user): Hub
    {
        if (! $this->canControl($user)) {
            throw new HttpException(
                403,
                'Enable “Control white labelled hubs” in Capabilities to manage white-label content.'
            );
        }

        $hub = $this->actingHub($user);
        if (! $hub->isWhiteLabel()) {
            throw new HttpException(
                422,
                'Select a white-labelled hub in the hub switcher first. Shared hub content uses the normal create APIs.'
            );
        }

        $this->assertSelectable($hub);

        return $hub;
    }

    public function assertSelectable(Hub $hub): void
    {
        if ($hub->isShared()) {
            return;
        }

        if (! $hub->is_active) {
            throw new InvalidArgumentException('That white-label hub is inactive.');
        }
        if (! $hub->can('receive_content_from_shared')) {
            throw new InvalidArgumentException('That hub does not allow content from the shared hub.');
        }
        $this->remoteDb->assertConfigured($hub);
    }

    private function ineligibleReason(Hub $hub): string
    {
        if (! $hub->can('receive_content_from_shared')) {
            return 'receive_content_from_shared is off';
        }
        if (! $hub->hasRemoteDatabaseConfigured()) {
            return 'remote database not configured';
        }

        return 'not ready';
    }

    private function forgetCache(User $user): void
    {
        Cache::forget("acting_hub:{$user->id}");
    }
}
