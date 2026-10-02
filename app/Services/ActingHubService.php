<?php

namespace App\Services;

use App\Models\Hub;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Central Hub Controller "acting hub" context for remote hub control.
 * Selecting a Shared or White-labelled hub scopes dashboard users and content
 * tools to that hub's own database.
 */
class ActingHubService
{
    public const CAPABILITY = 'dashboard_control_white_label_hubs';

    /**
     * These roles only exist as users on the Central Hub Controller.
     * The hub switcher capability always lives on the control plane.
     * All other hub dashboard / compliance matrix cells for these roles are
     * stored per hub — including each Shared / White-labelled — so the navbar
     * follows the selected hub's Capabilities matrix (see PROJECT_REQUIREMENTS §4.4).
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
        if (! $current->isControlPlane()) {
            return false;
        }

        return $this->matrix->roleCan($current, (string) $user->role, self::CAPABILITY);
    }

    /**
     * Hub the user is currently operating (Central by default; or selected content hub).
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

        // Selecting the control plane itself (or its own registry row) = home.
        if ($hub->isControlPlane() || (int) $hub->id === (int) $current->id) {
            return $current;
        }

        return $hub;
    }

    public function isActingOnWhiteLabel(User $user): bool
    {
        if (! $this->canControl($user)) {
            return false;
        }

        return $this->actingHub($user)->isWhiteLabel();
    }

    /**
     * True when the switcher is on a remote content hub (Shared or White-label).
     */
    public function isActingRemotely(User $user): bool
    {
        if (! $this->canControl($user)) {
            return false;
        }

        $acting = $this->actingHub($user);
        $current = $this->hubs->current();

        return (int) $acting->id !== (int) $current->id && $acting->isContentHub();
    }

    public static function isControlPlaneRole(string $role): bool
    {
        return in_array($role, self::CONTROL_PLANE_ROLES, true);
    }

    /**
     * Hub whose data this request should read/write.
     * Explicit hub_id wins; otherwise the switcher content hub; otherwise this deploy.
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

        if ($user && $this->isActingRemotely($user)) {
            return $this->actingHub($user);
        }

        return $this->hubs->current();
    }

    /**
     * Capability checks for hub-level flags follow the acting content hub.
     * Control-plane-only tools (hub switcher, Central content library) stay on Central.
     */
    public function capabilityHub(User $user, string $capability): Hub
    {
        $current = $this->hubs->current();

        // Hub switcher + Central content library always live on the control plane.
        if ($capability === self::CAPABILITY
            || $capability === 'dashboard_central_content_library'
        ) {
            return $current;
        }

        if (! $this->isActingRemotely($user)) {
            return $current;
        }

        // While a content hub is selected, modules / functionalities / role
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
                'Enable “Control hubs remotely” in Capabilities to use the hub switcher.'
            );
        }

        $current = $this->hubs->current();

        if ($hubId === null || $hubId === (int) $current->id) {
            $this->clearActingHub($user);

            return $current->fresh() ?? $current;
        }

        $hub = Hub::query()->findOrFail($hubId);
        if ($hub->isControlPlane()) {
            $this->clearActingHub($user);

            return $current->fresh() ?? $current;
        }

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
     * Hubs shown in the switcher (Central home + Shared + White-labelleds).
     *
     * @return list<array<string, mixed>>
     */
    public function switcherHubs(): array
    {
        $current = $this->hubs->current();
        $items = [];

        if ($current->isControlPlane()) {
            $items[] = [
                'id' => $current->id,
                'name' => $current->name,
                'slug' => $current->slug,
                'type' => $current->type,
                'eligible' => true,
                'label' => $current->name.' (central)',
            ];
        }

        foreach (
            Hub::query()
                ->whereIn('type', [Hub::TYPE_SHARED, Hub::TYPE_WHITE_LABEL])
                ->where('is_active', true)
                ->orderByRaw('CASE WHEN type = ? THEN 0 ELSE 1 END', [Hub::TYPE_SHARED])
                ->orderBy('name')
                ->get() as $hub
        ) {
            // Skip the current control-plane row if it somehow shares type (legacy).
            if ((int) $hub->id === (int) $current->id) {
                continue;
            }

            $eligible = $hub->can('receive_content_from_shared') && $hub->hasRemoteDatabaseConfigured();
            $typeLabel = $hub->isShared() ? 'shared' : 'white-label';
            $items[] = [
                'id' => $hub->id,
                'name' => $hub->name,
                'slug' => $hub->slug,
                'type' => $hub->type,
                'eligible' => $eligible,
                'label' => $hub->name.($eligible ? " ({$typeLabel})" : ' (not ready)'),
                'reason' => $eligible ? null : $this->ineligibleReason($hub),
            ];
        }

        return $items;
    }

    /**
     * Effective capabilities for the dashboard while a hub is selected.
     * Hub modules / functionalities / role matrix cells follow the acting
     * tenant. Plane-only tools (hub switcher, Central content library) always
     * follow the Central / control-plane matrix — same as hub_can middleware.
     *
     * @return array<string, bool>
     */
    public function effectiveCapabilities(User $user): array
    {
        $current = $this->hubs->current();
        $acting = $this->actingHub($user);

        // Warm request caches once — userCan previously rebuilt the full matrix per flag.
        $this->matrix->resolvedRoleCapabilities($acting);
        if ((int) $current->id !== (int) $acting->id) {
            $this->matrix->resolvedRoleCapabilities($current);
        }

        $flags = array_keys($acting->resolvedChecklist());
        $effective = [];

        foreach ($flags as $flag) {
            $hubForFlag = $this->capabilityHub($user, $flag);
            $effective[$flag] = $this->matrix->userCan($hubForFlag, $user, $flag);
        }

        // Always expose plane-only flags from the control-plane deploy hub
        // (including while the switcher is on a Shared / White-labelled hub).
        $effective[self::CAPABILITY] = $this->matrix->userCan($current, $user, self::CAPABILITY);
        $effective['dashboard_central_content_library'] = $this->matrix->userCan(
            $current,
            $user,
            'dashboard_central_content_library'
        );

        if ($user->isPowerAdmin()) {
            foreach (app(PowerAdminCapabilitiesService::class)->resolved() as $key => $enabled) {
                $effective[$key] = $enabled;
            }
        }

        return $this->mergeFirmDocumentEffectiveCapabilities($user, $effective, $acting);
    }

    /**
     * Head of Firm / member grants override matrix cells for firm document caps
     * so the dashboard nav can show Firm Documents without a role matrix tick.
     *
     * @param  array<string, bool>  $effective
     * @return array<string, bool>
     */
    public function mergeFirmDocumentEffectiveCapabilities(User $user, array $effective, ?Hub $hub = null): array
    {
        $hub = $hub ?? $this->hubs->current();

        try {
            $summary = app(FirmDocumentAccessService::class)->effectiveRightsSummary($user, $hub);
        } catch (\Throwable) {
            return $effective;
        }

        if (! ($summary['functionality_enabled'] ?? false)) {
            $effective['firm_documents_view'] = false;
            $effective['firm_documents_add'] = false;
            $effective['firm_documents_delete'] = false;
            $effective['firm_documents_archive'] = false;

            return $effective;
        }

        // Never downgrade a matrix true; only OR in head / grant rights.
        if ($summary['can_view']) {
            $effective['firm_documents_view'] = true;
        }
        if ($summary['can_add']) {
            $effective['firm_documents_add'] = true;
        }
        if ($summary['can_delete']) {
            $effective['firm_documents_delete'] = true;
        }
        if ($summary['can_archive']) {
            $effective['firm_documents_archive'] = true;
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
        $actingRemotely = $this->isActingRemotely($user);

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
                'is_shared' => $acting->isShared(),
                'is_content_hub' => $acting->isContentHub(),
                'frontend_url' => $acting->frontendBaseUrl(),
                'api_url' => $acting->apiBaseUrl(),
                'media_base_url' => $acting->publicMediaBaseUrl(),
                'branding' => $acting->brandingPayload(),
                'role_labels' => $acting->resolvedRoleLabels(),
                'compliance_status_labels' => $acting->resolvedComplianceStatusLabels(),
            ],
            'is_acting_on_white_label' => $acting->isWhiteLabel(),
            'is_acting_on_shared' => $acting->isShared() && $actingRemotely,
            'is_acting_remotely' => $actingRemotely,
            'control_plane_hub' => [
                'id' => $current->id,
                'name' => $current->name,
                'slug' => $current->slug,
                'type' => $current->type,
            ],
            // Legacy alias for older frontends.
            'shared_hub' => [
                'id' => $current->id,
                'name' => $current->name,
                'slug' => $current->slug,
            ],
            'effective_capabilities' => $this->effectiveCapabilities($user),
            'role_labels' => $acting->resolvedRoleLabels(),
            'compliance_status_labels' => $acting->resolvedComplianceStatusLabels(),
        ];
    }

    /**
     * Resolve and assert the acting content hub for remote writes.
     * Legacy name kept for callers; Shared and White-label are both allowed.
     *
     * @deprecated Use requireActingContentHub()
     */
    public function requireActingWhiteLabel(User $user): Hub
    {
        return $this->requireActingContentHub($user);
    }

    /**
     * Resolve and assert the acting content hub (Shared or White-label) for remote writes.
     */
    public function requireActingContentHub(User $user): Hub
    {
        if (! $this->canControl($user)) {
            throw new HttpException(
                403,
                'Enable “Control hubs remotely” in Capabilities to manage remote hub content.'
            );
        }

        $hub = $this->actingHub($user);
        if (! $this->isActingRemotely($user) || ! $hub->isContentHub()) {
            throw new HttpException(
                422,
                'Select a Shared or White-labelled hub in the hub switcher first. Central Hub is control plane only.'
            );
        }

        $this->assertSelectable($hub);

        return $hub;
    }

    /**
     * White-label–only actions (subscriber credits, advisor billing, etc.).
     */
    public function requireActingWhiteLabelOnly(User $user): Hub
    {
        $hub = $this->requireActingContentHub($user);
        if (! $hub->isWhiteLabel()) {
            throw new HttpException(
                422,
                'Select a white-labelled hub in the hub switcher for this action.'
            );
        }

        return $hub;
    }

    public function assertSelectable(Hub $hub): void
    {
        if ($hub->isControlPlane()) {
            return;
        }

        if (! $hub->isContentHub()) {
            throw new InvalidArgumentException('That hub cannot be controlled remotely.');
        }

        if (! $hub->is_active) {
            throw new InvalidArgumentException('That hub is inactive.');
        }
        if (! $hub->can('receive_content_from_shared')) {
            throw new InvalidArgumentException('That hub does not allow content from Central Hub.');
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
