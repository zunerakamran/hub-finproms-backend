<?php

namespace App\Services;

use App\Models\Firm;
use App\Models\Hub;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Keeps Document Repository headship in sync with network_admin / firm_admin (§3.4).
 * Multiple Network Admins / Firm Admins are allowed per firm.
 * Assign Head UI and multi-role assign are equivalent paths.
 */
class FirmHeadRoleSyncService
{
    private bool $syncing = false;

    public function __construct(
        private readonly HubService $hubs,
    ) {}

    public function isSyncing(): bool
    {
        return $this->syncing;
    }

    public function headRoleForFirm(Firm $firm): string
    {
        return $firm->isCentral() ? User::ROLE_NETWORK_ADMIN : User::ROLE_FIRM_ADMIN;
    }

    /**
     * Validate network_admin / firm_admin against firm membership before role sync.
     *
     * @param  list<string>  $roles
     */
    public function assertRolesAllowed(User $user, array $roles): void
    {
        $hasNetwork = in_array(User::ROLE_NETWORK_ADMIN, $roles, true);
        $hasFirm = in_array(User::ROLE_FIRM_ADMIN, $roles, true);

        if ($hasNetwork && $hasFirm) {
            throw ValidationException::withMessages([
                'roles' => 'A user cannot hold both Network Admin and Firm Admin at the same time.',
            ]);
        }

        if (! $hasNetwork && ! $hasFirm) {
            return;
        }

        if (! $user->firm_id) {
            throw ValidationException::withMessages([
                'roles' => $hasNetwork
                    ? 'Network Admin requires membership of the Central / Network firm.'
                    : 'Firm Admin requires membership of a member firm.',
            ]);
        }

        $firm = Firm::on($user->getConnectionName())->find((int) $user->firm_id);
        if (! $firm) {
            throw ValidationException::withMessages([
                'roles' => 'The user’s firm was not found.',
            ]);
        }

        if ($hasNetwork && ! $firm->isCentral()) {
            throw ValidationException::withMessages([
                'roles' => 'Network Admin can only be assigned to members of the Central / Network firm. Use Firm Admin for member firms.',
            ]);
        }

        if ($hasFirm && $firm->isCentral()) {
            throw ValidationException::withMessages([
                'roles' => 'Firm Admin cannot be assigned to Central / Network firm members. Use Network Admin instead.',
            ]);
        }

        if ($user->isDiscontinued()) {
            throw ValidationException::withMessages([
                'roles' => 'A discontinued user cannot be Network Admin or Firm Admin.',
            ]);
        }
    }

    /**
     * After roles are written: keep head_user_id as a primary contact only
     * (does not revoke other admins — multiple Network/Firm Admins are allowed).
     *
     * @param  list<string>  $before
     * @param  list<string>  $after
     */
    public function afterRolesSynced(
        User $user,
        array $before,
        array $after,
        ?User $actor = null,
        ?Request $request = null,
        ?Hub $hub = null,
    ): void {
        $connection = $user->getConnectionName();
        if ($this->syncing || ! $this->hasHeadColumn($connection)) {
            return;
        }

        $gainedNetwork = in_array(User::ROLE_NETWORK_ADMIN, $after, true)
            && ! in_array(User::ROLE_NETWORK_ADMIN, $before, true);
        $gainedFirm = in_array(User::ROLE_FIRM_ADMIN, $after, true)
            && ! in_array(User::ROLE_FIRM_ADMIN, $before, true);
        $lostNetwork = ! in_array(User::ROLE_NETWORK_ADMIN, $after, true)
            && in_array(User::ROLE_NETWORK_ADMIN, $before, true);
        $lostFirm = ! in_array(User::ROLE_FIRM_ADMIN, $after, true)
            && in_array(User::ROLE_FIRM_ADMIN, $before, true);

        if ($gainedNetwork || $gainedFirm) {
            $this->assertRolesAllowed($user, $after);
            $firm = Firm::on($connection)->find((int) $user->firm_id);
            if (! $firm) {
                return;
            }

            // Do not revoke other admins — only set primary contact when empty.
            $this->ensurePrimaryContact($firm, $user);

            return;
        }

        if ($lostNetwork || $lostFirm) {
            $headed = Firm::on($connection)->where('head_user_id', $user->id)->get();
            foreach ($headed as $firm) {
                $expected = $this->headRoleForFirm($firm);
                $lostExpected = ($expected === User::ROLE_NETWORK_ADMIN && $lostNetwork)
                    || ($expected === User::ROLE_FIRM_ADMIN && $lostFirm);
                if (! $lostExpected) {
                    continue;
                }
                $this->reassignPrimaryContact($firm, excludeUserId: (int) $user->id);
            }
        }
    }

    /**
     * Grant the firm’s head role to a user without removing other admins.
     */
    public function grantHeadRole(
        User $user,
        Firm $firm,
        ?User $actor = null,
        ?Request $request = null,
        ?Hub $hub = null,
    ): void {
        $connection = $firm->getConnectionName();
        if ($user->getConnectionName() !== $connection) {
            $user = User::on($connection)->find($user->id) ?? $user;
        }

        $role = $this->headRoleForFirm($firm);
        $roles = app(UserRolesService::class);
        $keys = $roles->roleKeys($user);
        $otherHead = $role === User::ROLE_NETWORK_ADMIN
            ? User::ROLE_FIRM_ADMIN
            : User::ROLE_NETWORK_ADMIN;
        $keys = array_values(array_filter($keys, fn (string $key) => $key !== $otherHead));
        if (! in_array($role, $keys, true)) {
            $keys[] = $role;
        }

        $wasSyncing = $this->syncing;
        $this->syncing = true;
        try {
            $roles->syncRoles(
                $user,
                $keys,
                log: true,
                actor: $actor,
                request: $request,
                hub: $hub,
                syncFirmHead: false,
            );
        } finally {
            $this->syncing = $wasSyncing;
        }
    }

    /**
     * Revoke the firm’s head role from a user without affecting other admins.
     */
    public function revokeHeadRole(
        User $user,
        Firm $firm,
        ?User $actor = null,
        ?Request $request = null,
        ?Hub $hub = null,
    ): void {
        $connection = $firm->getConnectionName();
        if ($user->getConnectionName() !== $connection) {
            $user = User::on($connection)->find($user->id) ?? $user;
        }

        $role = $this->headRoleForFirm($firm);
        $roles = app(UserRolesService::class);
        $next = array_values(array_filter(
            $roles->roleKeys($user),
            fn (string $key) => $key !== $role
        ));
        if ($next === []) {
            $next = [User::ROLE_USER];
        }

        $wasSyncing = $this->syncing;
        $this->syncing = true;
        try {
            $roles->syncRoles(
                $user,
                $next,
                log: true,
                actor: $actor,
                request: $request,
                hub: $hub,
                syncFirmHead: false,
            );
        } finally {
            $this->syncing = $wasSyncing;
        }
    }

    /**
     * @deprecated Use grantHeadRole / revokeHeadRole / FirmHeadService::syncAdmins.
     * Legacy single-head path: assigning a new head no longer strips other admins.
     */
    public function afterHeadAssigned(
        Firm $firm,
        ?int $previousHeadId,
        ?User $newHead,
        ?User $actor = null,
        ?Request $request = null,
        ?Hub $hub = null,
    ): void {
        $connection = $firm->getConnectionName();
        if ($this->syncing || ! $this->hasHeadColumn($connection)) {
            return;
        }

        if ($newHead) {
            $this->grantHeadRole($newHead, $firm, $actor, $request, $hub);
            $this->ensurePrimaryContact($firm, $newHead);

            return;
        }

        // Clear primary only — revoke role from previous primary if they still hold it.
        // Full “clear all admins” is handled by FirmHeadService::syncAdmins([]).
        if ($previousHeadId) {
            $previous = User::on($connection)->find($previousHeadId);
            if ($previous) {
                $this->revokeHeadRole($previous, $firm, $actor, $request, $hub);
            }
        }
    }

    /**
     * Reject firm membership changes that would leave a mismatched head role.
     */
    public function assertFirmMembershipAllowed(User $user, ?int $newFirmId): void
    {
        $hasNetwork = $user->hasRole(User::ROLE_NETWORK_ADMIN);
        $hasFirm = $user->hasRole(User::ROLE_FIRM_ADMIN);
        if (! $hasNetwork && ! $hasFirm) {
            return;
        }

        if ($newFirmId === null) {
            throw ValidationException::withMessages([
                'firm_id' => 'Remove Network Admin / Firm Admin before clearing firm membership.',
            ]);
        }

        $firm = Firm::on($user->getConnectionName())->find($newFirmId);
        if (! $firm) {
            throw ValidationException::withMessages([
                'firm_id' => 'The selected firm was not found.',
            ]);
        }

        if ($hasNetwork && ! $firm->isCentral()) {
            throw ValidationException::withMessages([
                'firm_id' => 'Network Admin users must belong to the Central / Network firm. Revoke Network Admin first.',
            ]);
        }

        if ($hasFirm && $firm->isCentral()) {
            throw ValidationException::withMessages([
                'firm_id' => 'Firm Admin users cannot belong to the Central / Network firm. Revoke Firm Admin first.',
            ]);
        }

        if ($hasFirm && (int) $user->firm_id !== (int) $newFirmId) {
            throw ValidationException::withMessages([
                'firm_id' => 'Firm Admin is tied to the user’s current firm. Revoke Firm Admin before moving firms.',
            ]);
        }
    }

    private function ensurePrimaryContact(Firm $firm, User $user): void
    {
        if ($firm->head_user_id !== null) {
            return;
        }

        $wasSyncing = $this->syncing;
        $this->syncing = true;
        try {
            $firm->head_user_id = $user->id;
            $firm->save();
        } finally {
            $this->syncing = $wasSyncing;
        }
    }

    private function reassignPrimaryContact(Firm $firm, int $excludeUserId): void
    {
        $connection = $firm->getConnectionName();
        $role = $this->headRoleForFirm($firm);
        $roles = app(UserRolesService::class);

        $replacement = User::on($connection)
            ->where('firm_id', $firm->id)
            ->where('id', '!=', $excludeUserId)
            ->where(function ($q) {
                $q->where('is_discontinued', false)->orWhereNull('is_discontinued');
            })
            ->orderBy('id')
            ->get()
            ->first(fn (User $u) => in_array($role, $roles->roleKeys($u), true));

        $wasSyncing = $this->syncing;
        $this->syncing = true;
        try {
            $firm->head_user_id = $replacement?->id;
            $firm->save();
        } finally {
            $this->syncing = $wasSyncing;
        }
    }

    private function hasHeadColumn(?string $connection): bool
    {
        try {
            return $connection
                ? Schema::connection($connection)->hasColumn('firms', 'head_user_id')
                : Schema::hasColumn('firms', 'head_user_id');
        } catch (\Throwable) {
            return false;
        }
    }
}
