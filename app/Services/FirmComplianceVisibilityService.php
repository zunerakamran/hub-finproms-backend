<?php

namespace App\Services;

use App\Models\Firm;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Resolves which firms may see / review / report on compliance requests
 * submitted by users of a given firm, based on that firm's visibility settings.
 *
 * Settings on the *submitter's firm*:
 * - visible_to_own      → staff in the same firm
 * - visible_to_central  → staff in the Central / Network firm
 * - visible_to_firm_id  → staff in one other named firm
 */
class FirmComplianceVisibilityService
{
    /**
     * Whether a viewer (by firm) may see compliance work submitted under $submitterFirm.
     */
    public function viewerFirmCanSee(?int $viewerFirmId, ?Firm $submitterFirm, bool $viewerIsCentral = false): bool
    {
        if (! $submitterFirm) {
            return true;
        }

        if ($viewerFirmId === null) {
            return true;
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
        $viewerFirmId = $actor->firm_id ? (int) $actor->firm_id : null;

        if ($viewerFirmId === null) {
            return;
        }

        $centralId = Firm::query()->where('is_central', true)->value('id');
        $viewerIsCentral = $centralId !== null && (int) $centralId === $viewerFirmId;
        $submitterKey = $this->qualifySubmitterKey($userRelation);

        $query->where(function (Builder $outer) use ($actor, $userRelation, $assignedColumn, $viewerFirmId, $viewerIsCentral, $submitterKey) {
            $outer->where($submitterKey, $actor->id);

            if ($assignedColumn) {
                $outer->orWhere($assignedColumn, $actor->id);
            }

            $outer->orWhereHas($userRelation, function (Builder $userQ) use ($viewerFirmId, $viewerIsCentral) {
                $userQ->where(function (Builder $q) use ($viewerFirmId, $viewerIsCentral) {
                    $q->whereNull('firm_id')
                        ->orWhereHas('firm', function (Builder $firmQ) use ($viewerFirmId, $viewerIsCentral) {
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
        });
    }

    public function actorCanViewRequest(
        User $actor,
        ?int $submitterUserId,
        ?int $submitterFirmId,
        ?int $assignedTo
    ): bool {
        if ($submitterUserId && (int) $submitterUserId === (int) $actor->id) {
            return true;
        }

        if ($assignedTo && (int) $assignedTo === (int) $actor->id) {
            return true;
        }

        if (! $actor->firm_id) {
            return true;
        }

        $submitterFirm = $submitterFirmId
            ? Firm::query()->find($submitterFirmId)
            : null;

        return $this->actorCanSeeSubmitterFirm($actor, $submitterFirm);
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
