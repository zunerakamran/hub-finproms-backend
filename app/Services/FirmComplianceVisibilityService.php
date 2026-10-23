<?php

namespace App\Services;

use App\Models\Firm;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * Resolves which firms may see / review / report on compliance requests
 * submitted by users of a given firm (Delegated compliance access §2.7.0a).
 *
 * Who is firm-scoped (own firm + delegated authority firms):
 * - Member-firm users with a non-central firm_id — including when they have
 *   "view all" capabilities.
 *
 * Who bypasses firm scope (sees everything their capabilities allow):
 * - power_admin, finproms_admin
 * - Central / Network firm members who hold network_admin, top_management,
 *   auditor, accounts_admin, or approver
 *
 * Settings on the *submitter's firm*:
 * - compliance_visible_to_own → staff in the same firm
 * - firm_compliance_authorities pivot → other firms (incl. Central) that may
 *   review / assign / report / audit (one-way)
 */
class FirmComplianceVisibilityService
{
    /**
     * Roles that, when held by a Central / Network firm member, see hub-wide
     * (all firms) for compliance queues / reports within their capabilities.
     *
     * @var list<string>
     */
    public const NETWORK_HUB_WIDE_ROLES = [
        User::ROLE_NETWORK_ADMIN,
        User::ROLE_TOP_MANAGEMENT,
        User::ROLE_AUDITOR,
        User::ROLE_ACCOUNTS_ADMIN,
        User::ROLE_APPROVER,
    ];

    /**
     * Platform control-plane roles and Central / Network oversight roles.
     */
    public function actorBypassesFirmScope(User $actor): bool
    {
        if ($actor->isPowerAdmin() || $actor->isFinpromsAdmin()) {
            return true;
        }

        if (! $actor->firm_id || ! $actor->hasAnyRole(self::NETWORK_HUB_WIDE_ROLES)) {
            return false;
        }

        $firm = $actor->relationLoaded('firm')
            ? $actor->firm
            : Firm::query()->find((int) $actor->firm_id);

        return $firm !== null && $firm->isCentral();
    }

    /**
     * Whether a viewer (by firm) may see compliance work submitted under $submitterFirm.
     */
    public function viewerFirmCanSee(?int $viewerFirmId, ?Firm $submitterFirm, bool $viewerIsCentral = false): bool
    {
        // No submitter firm → not in firm queues (e.g. public registrants).
        if (! $submitterFirm) {
            return false;
        }

        // Firm-scoped staff must belong to a firm to see firm queues.
        if ($viewerFirmId === null) {
            return false;
        }

        if ($submitterFirm->compliance_visible_to_own && (int) $viewerFirmId === (int) $submitterFirm->id) {
            return true;
        }

        $authorityIds = $submitterFirm->complianceAuthorityFirmIds();
        if (in_array((int) $viewerFirmId, $authorityIds, true)) {
            return true;
        }

        // Legacy fallback when pivot empty but old columns still set.
        if ($authorityIds === []) {
            if ($submitterFirm->compliance_visible_to_central && $viewerIsCentral) {
                return true;
            }

            if ($submitterFirm->compliance_visible_to_firm_id
                && (int) $viewerFirmId === (int) $submitterFirm->compliance_visible_to_firm_id) {
                return true;
            }
        }

        return false;
    }

    public function actorCanSeeSubmitterFirm(User $actor, ?Firm $submitterFirm): bool
    {
        if ($this->actorBypassesFirmScope($actor)) {
            return true;
        }

        $viewerFirmId = $actor->firm_id ? (int) $actor->firm_id : null;
        $viewerIsCentral = false;

        if ($viewerFirmId) {
            $viewerFirm = $actor->relationLoaded('firm')
                ? $actor->firm
                : Firm::query()->find($viewerFirmId);
            $viewerIsCentral = (bool) ($viewerFirm?->is_central);
        }

        return $this->viewerFirmCanSee($viewerFirmId, $submitterFirm, $viewerIsCentral);
    }

    /**
     * Scope a query of compliance requests to firms the actor may see.
     * Always keeps the actor's own submissions and items assigned to them.
     *
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     */
    public function scopeQueryForActor(
        Builder $query,
        User $actor,
        string $userRelation = 'user',
        ?string $assignedColumn = 'assigned_to'
    ): void {
        if ($this->actorBypassesFirmScope($actor)) {
            return;
        }

        $viewerFirmId = $actor->firm_id ? (int) $actor->firm_id : null;
        $submitterKey = $this->qualifySubmitterKey($userRelation);

        // Firm-scoped role with no firm: only own work + assigned to them.
        if ($viewerFirmId === null) {
            $query->where(function (Builder $outer) use ($actor, $assignedColumn, $submitterKey) {
                $outer->where($submitterKey, $actor->id);
                if ($assignedColumn) {
                    $outer->orWhere($assignedColumn, $actor->id);
                }
            });

            return;
        }

        $centralId = Firm::query()->where('is_central', true)->value('id');
        $viewerIsCentral = $centralId !== null && (int) $centralId === $viewerFirmId;
        $hasPivot = Schema::hasTable('firm_compliance_authorities');

        $query->where(function (Builder $outer) use (
            $actor,
            $userRelation,
            $assignedColumn,
            $viewerFirmId,
            $viewerIsCentral,
            $submitterKey,
            $hasPivot
        ) {
            $outer->where($submitterKey, $actor->id);

            if ($assignedColumn) {
                $outer->orWhere($assignedColumn, $actor->id);
            }

            // Requests from firms whose settings allow this actor's firm.
            $outer->orWhereHas($userRelation, function (Builder $userQ) use (
                $viewerFirmId,
                $viewerIsCentral,
                $hasPivot
            ) {
                $userQ->whereNotNull('firm_id')
                    ->whereHas('firm', function (Builder $firmQ) use (
                        $viewerFirmId,
                        $viewerIsCentral,
                        $hasPivot
                    ) {
                        $firmQ->where(function (Builder $vis) use (
                            $viewerFirmId,
                            $viewerIsCentral,
                            $hasPivot
                        ) {
                            $vis->where(function (Builder $own) use ($viewerFirmId) {
                                $own->where('compliance_visible_to_own', true)
                                    ->where('firms.id', $viewerFirmId);
                            });

                            if ($hasPivot) {
                                $vis->orWhereHas('complianceAuthorityFirms', function (Builder $auth) use ($viewerFirmId) {
                                    $auth->where('firms.id', $viewerFirmId);
                                });
                            } else {
                                if ($viewerIsCentral) {
                                    $vis->orWhere('compliance_visible_to_central', true);
                                }
                                $vis->orWhere('compliance_visible_to_firm_id', $viewerFirmId);
                            }
                        });
                    });
            });
        });
    }

    public function actorCanViewRequest(
        User $actor,
        ?int $submitterUserId,
        ?int $submitterFirmId,
        ?int $assignedTo
    ): bool {
        if ($this->actorBypassesFirmScope($actor)) {
            return true;
        }

        if ($submitterUserId && (int) $submitterUserId === (int) $actor->id) {
            return true;
        }

        if ($assignedTo && (int) $assignedTo === (int) $actor->id) {
            return true;
        }

        $submitterFirm = $submitterFirmId
            ? Firm::query()->find($submitterFirmId)
            : null;

        return $this->actorCanSeeSubmitterFirm($actor, $submitterFirm);
    }

    /**
     * Guard assign / review / approve actions for firm-scoped roles.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function assertActorCanActOnRequest(
        User $actor,
        ?int $submitterUserId,
        ?int $submitterFirmId,
        ?int $assignedTo
    ): void {
        if ($this->actorCanViewRequest($actor, $submitterUserId, $submitterFirmId, $assignedTo)) {
            return;
        }

        throw \Illuminate\Validation\ValidationException::withMessages([
            'firm' => 'You do not have permission to act on this request for this firm.',
        ]);
    }

    /**
     * Firm IDs whose staff may be assigned to compliance work from $submitterFirm,
     * based on that firm's visibility / delegated access settings.
     *
     * @return list<int>
     */
    public function assigneeFirmIdsForSubmitterFirm(?Firm $submitterFirm): array
    {
        if (! $submitterFirm) {
            return [];
        }

        $ids = [];

        if ($submitterFirm->compliance_visible_to_own) {
            $ids[] = (int) $submitterFirm->id;
        }

        $authorityIds = $submitterFirm->complianceAuthorityFirmIds();
        foreach ($authorityIds as $authorityId) {
            $ids[] = (int) $authorityId;
        }

        // Legacy fallback when pivot empty.
        if ($authorityIds === []) {
            if ($submitterFirm->compliance_visible_to_central) {
                $centralId = Firm::query()->where('is_central', true)->value('id');
                if ($centralId) {
                    $ids[] = (int) $centralId;
                }
            }

            if ($submitterFirm->compliance_visible_to_firm_id) {
                $ids[] = (int) $submitterFirm->compliance_visible_to_firm_id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Whether $candidate may be assigned to a request from $submitterFirm.
     * Power Admin / FinProms Admin and Central oversight roles are always eligible.
     */
    public function userIsEligibleAssignee(User $candidate, ?Firm $submitterFirm): bool
    {
        if ($this->actorBypassesFirmScope($candidate)) {
            return true;
        }

        $candidateFirmId = $candidate->firm_id ? (int) $candidate->firm_id : null;
        if ($candidateFirmId === null) {
            return false;
        }

        return in_array($candidateFirmId, $this->assigneeFirmIdsForSubmitterFirm($submitterFirm), true);
    }

    /**
     * Filter a collection of candidate assignees for a submitter firm.
     *
     * @param  \Illuminate\Support\Collection<int, User>  $candidates
     * @return \Illuminate\Support\Collection<int, User>
     */
    public function filterAssigneesForSubmitterFirm($candidates, ?Firm $submitterFirm)
    {
        return $candidates
            ->filter(fn (User $user) => $this->userIsEligibleAssignee($user, $submitterFirm))
            ->values();
    }

    private function qualifySubmitterKey(string $userRelation): string
    {
        return match ($userRelation) {
            'user' => 'user_id',
            'editor' => 'editor_id',
            default => $userRelation.'_id',
        };
    }
}
