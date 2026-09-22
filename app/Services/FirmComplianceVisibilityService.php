<?php

namespace App\Services;

use App\Models\Firm;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Resolves which firms may see / review / report on compliance requests
 * submitted by users of a given firm, based on that firm's visibility settings.
 *
 * Who is firm-scoped:
 * - client_admin, manager, approver, advisor, user (and any other non-control-plane role)
 *   when they have a firm_id — including when they have "view all" capabilities.
 *
 * Who bypasses firm scope (sees everything their capabilities allow):
 * - power_admin, finproms_admin
 *
 * Public self-registered users (no firm) are outside the firm model: their
 * requests are not part of firm queues, and staff without a firm only see
 * their own / assigned items (not other firms' queues).
 *
 * Settings on the *submitter's firm*:
 * - visible_to_own      → staff in the same firm
 * - visible_to_central  → staff in the Central / Network firm
 * - visible_to_firm_id  → staff in one other named firm
 */
class FirmComplianceVisibilityService
{
    /**
     * Platform control-plane roles are not part of the firm model.
     */
    public function actorBypassesFirmScope(User $actor): bool
    {
        return $actor->isPowerAdmin() || $actor->isFinpromsAdmin();
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

        if ($submitterFirm->compliance_visible_to_central && $viewerIsCentral) {
            return true;
        }

        if ($submitterFirm->compliance_visible_to_firm_id
            && (int) $viewerFirmId === (int) $submitterFirm->compliance_visible_to_firm_id) {
            return true;
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

        $query->where(function (Builder $outer) use (
            $actor,
            $userRelation,
            $assignedColumn,
            $viewerFirmId,
            $viewerIsCentral,
            $submitterKey
        ) {
            $outer->where($submitterKey, $actor->id);

            if ($assignedColumn) {
                $outer->orWhere($assignedColumn, $actor->id);
            }

            // Requests from firms whose settings allow this actor's firm.
            $outer->orWhereHas($userRelation, function (Builder $userQ) use ($viewerFirmId, $viewerIsCentral) {
                $userQ->whereNotNull('firm_id')
                    ->whereHas('firm', function (Builder $firmQ) use ($viewerFirmId, $viewerIsCentral) {
                        $firmQ->where(function (Builder $vis) use ($viewerFirmId, $viewerIsCentral) {
                            $vis->where(function (Builder $own) use ($viewerFirmId) {
                                $own->where('compliance_visible_to_own', true)
                                    ->where('firms.id', $viewerFirmId);
                            });

                            if ($viewerIsCentral) {
                                $vis->orWhere('compliance_visible_to_central', true);
                            }

                            $vis->orWhere('compliance_visible_to_firm_id', $viewerFirmId);
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

    private function qualifySubmitterKey(string $userRelation): string
    {
        return match ($userRelation) {
            'user' => 'user_id',
            'editor' => 'editor_id',
            default => $userRelation.'_id',
        };
    }
}
