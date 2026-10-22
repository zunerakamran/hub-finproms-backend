<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Multi-role separation of duties (§2.7.2).
 * Submitter / on-behalf actor must not assign or decide on their own work.
 */
class ComplianceSeparationService
{
    public const MESSAGE = 'You cannot review or decide on your own request. Another reviewer must handle it.';

    public const ASSIGN_MESSAGE = 'This request cannot be assigned to its submitter or the staff who submitted it on their behalf.';

    /**
     * User ids that are conflicted for decisioning on a request.
     *
     * @return list<int>
     */
    public function conflictUserIds(?int $ownerUserId, ?int $onBehalfByUserId = null): array
    {
        $ids = [];
        if ($ownerUserId !== null && $ownerUserId > 0) {
            $ids[] = (int) $ownerUserId;
        }
        if ($onBehalfByUserId !== null && $onBehalfByUserId > 0) {
            $ids[] = (int) $onBehalfByUserId;
        }

        return array_values(array_unique($ids));
    }

    public function actorIsConflicted(
        User $actor,
        ?int $ownerUserId,
        ?int $onBehalfByUserId = null,
        ?int $actorTenantUserId = null,
    ): bool {
        $conflicted = $this->conflictUserIds($ownerUserId, $onBehalfByUserId);
        if ($conflicted === []) {
            return false;
        }

        // Check the logged-in actor and optional tenant/acting-as subject
        // (admin-staff as advisor A must not decide on A's own requests).
        $candidates = [(int) $actor->id];
        if ($actorTenantUserId !== null && (int) $actorTenantUserId > 0) {
            $candidates[] = (int) $actorTenantUserId;
        }

        foreach (array_unique($candidates) as $id) {
            if ($id > 0 && in_array($id, $conflicted, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @throws ValidationException
     */
    public function assertActorNotConflicted(
        User $actor,
        ?int $ownerUserId,
        ?int $onBehalfByUserId = null,
        ?int $actorTenantUserId = null,
        string $message = self::MESSAGE,
    ): void {
        if ($this->actorIsConflicted($actor, $ownerUserId, $onBehalfByUserId, $actorTenantUserId)) {
            throw ValidationException::withMessages([
                'separation_of_duties' => $message,
            ]);
        }
    }

    /**
     * HTTP-style assert for WC controllers/services.
     */
    public function assertActorNotConflictedHttp(
        User $actor,
        ?int $ownerUserId,
        ?int $onBehalfByUserId = null,
        ?int $actorTenantUserId = null,
        string $message = self::MESSAGE,
    ): void {
        if ($this->actorIsConflicted($actor, $ownerUserId, $onBehalfByUserId, $actorTenantUserId)) {
            throw new HttpException(403, $message);
        }
    }

    /**
     * @throws ValidationException
     */
    public function assertAssigneeNotConflicted(
        User $assignee,
        ?int $ownerUserId,
        ?int $onBehalfByUserId = null,
        ?int $assigneeTenantUserId = null,
    ): void {
        $assigneeId = (int) ($assigneeTenantUserId ?? $assignee->id);
        if ($assigneeId > 0 && in_array($assigneeId, $this->conflictUserIds($ownerUserId, $onBehalfByUserId), true)) {
            throw ValidationException::withMessages([
                'assigned_to' => self::ASSIGN_MESSAGE,
            ]);
        }
    }

    /**
     * @param  Collection<int, User>  $reviewers
     * @return Collection<int, User>
     */
    public function excludeConflictedReviewers(
        Collection $reviewers,
        ?int $ownerUserId,
        ?int $onBehalfByUserId = null,
    ): Collection {
        $blocked = $this->conflictUserIds($ownerUserId, $onBehalfByUserId);
        if ($blocked === []) {
            return $reviewers;
        }

        return $reviewers->filter(
            fn (User $user) => ! in_array((int) $user->id, $blocked, true)
        )->values();
    }

    /**
     * Restrict a review/pickup query so conflicted own items are not offered for pickup.
     * Own submissions still appear when the caller also ORs a view-own branch.
     *
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     */
    public function scopeExcludeConflictedFromPickup(
        Builder $query,
        User $actor,
        string $ownerColumn = 'user_id',
        ?string $onBehalfColumn = 'on_behalf_by_user_id',
        ?int $actorTenantUserId = null,
    ): void {
        $actorId = (int) ($actorTenantUserId ?? $actor->id);
        if ($actorId <= 0) {
            return;
        }

        $query->where($ownerColumn, '!=', $actorId);
        if ($onBehalfColumn !== null && $onBehalfColumn !== '') {
            $query->where(function (Builder $q) use ($onBehalfColumn, $actorId): void {
                $q->whereNull($onBehalfColumn)->orWhere($onBehalfColumn, '!=', $actorId);
            });
        }
    }

    /**
     * Users who hold any of the given roles (primary column or user_roles pivot).
     *
     * @param  list<string>  $roles
     * @param  list<string>  $columns
     * @return Builder<\App\Models\User>
     */
    public function usersHoldingRolesQuery(array $roles, array $columns = ['id', 'name', 'email', 'role', 'firm_id']): Builder
    {
        return User::query()
            ->with('firm:id,name,is_central')
            ->where(function (Builder $q) use ($roles): void {
                $q->whereIn('role', $roles)
                    ->orWhereHas('roleAssignments', fn (Builder $r) => $r->whereIn('role', $roles));
            })
            ->where(function (Builder $q): void {
                $q->where('is_suspended', false)->orWhereNull('is_suspended');
            })
            ->where(function (Builder $q): void {
                $q->where('is_discontinued', false)->orWhereNull('is_discontinued');
            })
            ->orderBy('name')
            ->select($columns);
    }
}
