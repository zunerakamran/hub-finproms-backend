<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Admin-staff "work on behalf of advisor" context (firm-scoped).
 */
class ActingAdvisorService
{
    /**
     * Whether this user may select an advisor to act for.
     */
    public function canActOnBehalf(User $user): bool
    {
        return $user->isAdminStaff();
    }

    /**
     * Advisors in the same firm the admin-staff may work on behalf of.
     *
     * @return Collection<int, User>
     */
    public function eligibleAdvisors(User $staff): Collection
    {
        if (! $this->canActOnBehalf($staff)) {
            return collect();
        }

        if (! $staff->firm_id) {
            return collect();
        }

        return User::query()
            ->where('firm_id', $staff->firm_id)
            ->where('id', '!=', $staff->id)
            ->where(function ($q) {
                $q->where('role', User::ROLE_ADVISOR)
                    ->orWhere('is_advisor', true);
            })
            ->where(function ($q) {
                $q->where('is_suspended', false)->orWhereNull('is_suspended');
            })
            ->where(function ($q) {
                $q->where('is_discontinued', false)->orWhereNull('is_discontinued');
            })
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name', 'email', 'role', 'is_advisor', 'firm_id']);
    }

    public function actingAdvisor(User $staff): ?User
    {
        if (! $this->canActOnBehalf($staff) || ! $staff->acting_advisor_id) {
            return null;
        }

        $advisor = User::query()->find($staff->acting_advisor_id);
        if (! $advisor || ! $this->isEligibleAdvisor($staff, $advisor)) {
            $this->clearActingAdvisor($staff);

            return null;
        }

        return $advisor;
    }

    /**
     * Subject user for compliance / advisor-scoped actions.
     * Admin-staff must have selected an advisor; everyone else acts as themselves.
     */
    public function requireSubject(User $actor): User
    {
        if (! $this->canActOnBehalf($actor)) {
            return $actor;
        }

        $advisor = $this->actingAdvisor($actor);
        if (! $advisor) {
            throw ValidationException::withMessages([
                'acting_advisor_id' => 'Select an advisor to work on behalf of before continuing.',
            ]);
        }

        return $advisor;
    }

    /**
     * Subject for "my requests" listing (null for admin-staff with no selection).
     */
    public function subjectOrNull(User $actor): ?User
    {
        if (! $this->canActOnBehalf($actor)) {
            return $actor;
        }

        return $this->actingAdvisor($actor);
    }

    /**
     * User id to store as on_behalf_by when actor ≠ subject.
     */
    public function onBehalfById(User $actor, User $subject): ?int
    {
        if ((int) $actor->id === (int) $subject->id) {
            return null;
        }

        return (int) $actor->id;
    }

    public function assertCanManageOwnedBy(User $actor, int $ownerUserId): void
    {
        if ((int) $actor->id === $ownerUserId) {
            return;
        }

        if (! $this->canActOnBehalf($actor)) {
            throw ValidationException::withMessages([
                'request' => 'You can only manage your own requests.',
            ]);
        }

        $advisor = $this->actingAdvisor($actor);
        if (! $advisor || (int) $advisor->id !== $ownerUserId) {
            throw ValidationException::withMessages([
                'request' => 'You can only manage requests for the advisor you are working on behalf of.',
            ]);
        }
    }

    /**
     * @throws HttpException|InvalidArgumentException|ValidationException
     */
    public function setActingAdvisor(User $staff, ?int $advisorId): ?User
    {
        if (! $this->canActOnBehalf($staff)) {
            throw new HttpException(403, 'Only Admin-staff can work on behalf of advisors.');
        }

        if ($advisorId === null) {
            $this->clearActingAdvisor($staff);

            return null;
        }

        $advisor = User::query()->find($advisorId);
        if (! $advisor) {
            throw ValidationException::withMessages([
                'acting_advisor_id' => 'Advisor not found.',
            ]);
        }

        if (! $this->isEligibleAdvisor($staff, $advisor)) {
            throw ValidationException::withMessages([
                'acting_advisor_id' => 'You can only work on behalf of advisors in your firm.',
            ]);
        }

        $staff->forceFill(['acting_advisor_id' => $advisor->id])->save();

        return $advisor;
    }

    public function clearActingAdvisor(User $staff): void
    {
        if ($staff->acting_advisor_id !== null) {
            $staff->forceFill(['acting_advisor_id' => null])->save();
        }
    }

    public function isEligibleAdvisor(User $staff, User $advisor): bool
    {
        if (! $staff->firm_id || (int) $staff->firm_id !== (int) $advisor->firm_id) {
            return false;
        }

        if ((int) $staff->id === (int) $advisor->id) {
            return false;
        }

        if (! $advisor->isAdvisor()) {
            return false;
        }

        if ($advisor->isSuspended() || $advisor->isDiscontinued()) {
            return false;
        }

        return true;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function switcherPayload(User $user): ?array
    {
        if (! $this->canActOnBehalf($user)) {
            return null;
        }

        $acting = $this->actingAdvisor($user);
        $advisors = $this->eligibleAdvisors($user);

        return [
            'enabled' => true,
            'role' => User::ROLE_ADMIN_STAFF,
            'acting_advisor' => $acting ? [
                'id' => $acting->id,
                'name' => $acting->name,
                'email' => $acting->email,
            ] : null,
            'advisors' => $advisors->map(fn (User $a) => [
                'id' => $a->id,
                'name' => $a->name,
                'email' => $a->email,
            ])->values()->all(),
        ];
    }

    /**
     * Compact attribution block for API responses.
     *
     * @return array{on_behalf_by: array{id:int,name:string,email:?string}|null, submitted_on_behalf_of: array{id:int,name:string,email:?string}|null, attribution_label: string|null}|null
     */
    public static function attributionPayload(?User $owner, ?User $onBehalfBy): ?array
    {
        if (! $onBehalfBy || ! $owner) {
            return null;
        }

        if ((int) $onBehalfBy->id === (int) $owner->id) {
            return null;
        }

        return [
            'on_behalf_by' => [
                'id' => (int) $onBehalfBy->id,
                'name' => $onBehalfBy->name,
                'email' => $onBehalfBy->email,
            ],
            'submitted_on_behalf_of' => [
                'id' => (int) $owner->id,
                'name' => $owner->name,
                'email' => $owner->email,
            ],
            'attribution_label' => $onBehalfBy->name.' submitted on behalf of '.$owner->name,
        ];
    }

    public static function attributionLabel(?string $ownerName, ?string $onBehalfByName): ?string
    {
        $ownerName = trim((string) $ownerName);
        $onBehalfByName = trim((string) $onBehalfByName);
        if ($ownerName === '' || $onBehalfByName === '' || $ownerName === $onBehalfByName) {
            return null;
        }

        return $onBehalfByName.' submitted on behalf of '.$ownerName;
    }
}
