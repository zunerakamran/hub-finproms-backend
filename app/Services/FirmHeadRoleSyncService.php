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

        $firm = Firm::query()->find((int) $user->firm_id);
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
     * After roles are written: mirror head_user_id (and strip the head role from a previous holder).
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
        if ($this->syncing || ! Schema::hasColumn('firms', 'head_user_id')) {
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
            $firm = Firm::query()->find((int) $user->firm_id);
            if (! $firm) {
                return;
            }

            $this->setFirmHead($firm, $user, $actor, $request, $hub, clearPreviousRole: true);

            return;
        }

        if ($lostNetwork || $lostFirm) {
            $headed = Firm::query()->where('head_user_id', $user->id)->get();
            foreach ($headed as $firm) {
                $expected = $this->headRoleForFirm($firm);
                $lostExpected = ($expected === User::ROLE_NETWORK_ADMIN && $lostNetwork)
                    || ($expected === User::ROLE_FIRM_ADMIN && $lostFirm);
                if (! $lostExpected) {
                    continue;
                }
                if ((int) $firm->head_user_id === (int) $user->id) {
                    $firm->head_user_id = null;
                    $firm->save();
                }
            }
        }
    }

    /**
     * After Assign Head UI sets/clears head_user_id: mirror network_admin / firm_admin.
     */
    public function afterHeadAssigned(
        Firm $firm,
        ?int $previousHeadId,
        ?User $newHead,
        ?User $actor = null,
        ?Request $request = null,
        ?Hub $hub = null,
    ): void {
        if ($this->syncing || ! Schema::hasColumn('firms', 'head_user_id')) {
            return;
        }

        $this->syncing = true;

        try {
            $role = $this->headRoleForFirm($firm);
            $roles = app(UserRolesService::class);

            if ($previousHeadId && (! $newHead || (int) $previousHeadId !== (int) $newHead->id)) {
                $previous = User::query()->find($previousHeadId);
                if ($previous && $previous->hasRole($role)) {
                    $next = array_values(array_filter(
                        $roles->roleKeys($previous),
                        fn (string $key) => $key !== $role
                    ));
                    if ($next === []) {
                        $next = [User::ROLE_USER];
                    }
                    $roles->syncRoles(
                        $previous,
                        $next,
                        log: true,
                        actor: $actor,
                        request: $request,
                        hub: $hub,
                        syncFirmHead: false,
                    );
                }
            }

            if ($newHead) {
                $keys = $roles->roleKeys($newHead);
                $otherHead = $role === User::ROLE_NETWORK_ADMIN
                    ? User::ROLE_FIRM_ADMIN
                    : User::ROLE_NETWORK_ADMIN;
                $keys = array_values(array_filter($keys, fn (string $key) => $key !== $otherHead));
                if (! in_array($role, $keys, true)) {
                    $keys[] = $role;
                }
                $roles->syncRoles(
                    $newHead,
                    $keys,
                    log: true,
                    actor: $actor,
                    request: $request,
                    hub: $hub,
                    syncFirmHead: false,
                );
            }
        } finally {
            $this->syncing = false;
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

        $firm = Firm::query()->find($newFirmId);
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
                'firm_id' => 'Firm Admin is tied to the user’s current firm. Revoke Firm Admin (or clear Head of Firm) before moving firms.',
            ]);
        }
    }

    private function setFirmHead(
        Firm $firm,
        User $head,
        ?User $actor,
        ?Request $request,
        ?Hub $hub,
        bool $clearPreviousRole,
    ): void {
        $previousHeadId = $firm->head_user_id ? (int) $firm->head_user_id : null;
        if ($previousHeadId === (int) $head->id) {
            return;
        }

        $this->syncing = true;

        try {
            $firm->head_user_id = $head->id;
            $firm->save();

            if ($clearPreviousRole && $previousHeadId) {
                $previous = User::query()->find($previousHeadId);
                $role = $this->headRoleForFirm($firm);
                if ($previous && $previous->hasRole($role)) {
                    $roles = app(UserRolesService::class);
                    $next = array_values(array_filter(
                        $roles->roleKeys($previous),
                        fn (string $key) => $key !== $role
                    ));
                    if ($next === []) {
                        $next = [User::ROLE_USER];
                    }
                    $roles->syncRoles(
                        $previous,
                        $next,
                        log: true,
                        actor: $actor,
                        request: $request,
                        hub: $hub,
                        syncFirmHead: false,
                    );
                }
            }
        } finally {
            $this->syncing = false;
        }
    }
}
