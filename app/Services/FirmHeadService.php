<?php

namespace App\Services;

use App\Models\Firm;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class FirmHeadService
{
    public function __construct(
        private readonly ActivityLogService $activityLogs,
        private readonly HubService $hubs,
        private readonly FirmHeadRoleSyncService $headRoles,
    ) {}

    /**
     * Sync the set of firm admins (Network Admin / Firm Admin) for a firm.
     * Multiple admins are allowed; Document Repository unlock is role-based.
     * `firms.head_user_id` stores a primary contact (first selected / retained).
     *
     * @param  list<int>  $headUserIds
     */
    public function syncAdmins(Firm $firm, array $headUserIds, User $actor, ?Request $request = null): Firm
    {
        $ids = $this->normalizeIds($headUserIds);
        $role = $this->headRoles->headRoleForFirm($firm);
        $roleLabel = $firm->isCentral() ? 'Network Admin' : 'Firm Admin';
        $hub = $this->hubs->current();

        $members = User::query()
            ->where('firm_id', $firm->id)
            ->whereIn('id', $ids === [] ? [0] : $ids)
            ->get()
            ->keyBy('id');

        foreach ($ids as $id) {
            $user = $members->get($id);
            if (! $user) {
                throw ValidationException::withMessages([
                    'head_user_ids' => 'Each firm admin must be a member of this firm.',
                ]);
            }
            if ($user->isDiscontinued()) {
                throw ValidationException::withMessages([
                    'head_user_ids' => 'A discontinued user cannot be firm admin.',
                ]);
            }
        }

        $beforeIds = $this->adminUserIds($firm);
        $toAdd = array_values(array_diff($ids, $beforeIds));
        $toRemove = array_values(array_diff($beforeIds, $ids));

        foreach ($toAdd as $id) {
            $this->headRoles->grantHeadRole($members->get($id), $firm, $actor, $request, $hub);
        }

        if ($toRemove !== []) {
            $removeUsers = User::query()->whereIn('id', $toRemove)->get();
            foreach ($removeUsers as $user) {
                $this->headRoles->revokeHeadRole($user, $firm, $actor, $request, $hub);
            }
        }

        if ($ids === []) {
            $firm->head_user_id = null;
        } elseif ($firm->head_user_id === null
            || ! in_array((int) $firm->head_user_id, $ids, true)
        ) {
            $firm->head_user_id = $ids[0];
        }
        $firm->save();

        if ($toAdd !== [] || $toRemove !== []) {
            $this->activityLogs->log([
                'action' => $ids === [] ? 'firm.head.clear' : 'firm.head.sync',
                'description' => $ids === []
                    ? 'Cleared all '.$roleLabel.' admins for “'.$firm->name.'”'
                    : 'Updated '.$roleLabel.' admins for “'.$firm->name.'” ('.count($ids).')',
                'user' => $actor,
                'hub' => $hub,
                'subject' => $firm,
                'request' => $request,
                'status_code' => 200,
                'properties' => [
                    'firm_id' => $firm->id,
                    'firm_name' => $firm->name,
                    'head_user_ids' => $ids,
                    'added_user_ids' => $toAdd,
                    'removed_user_ids' => $toRemove,
                    'head_role' => $role,
                ],
            ]);
        }

        return $firm->fresh(['headUser:id,name,email']);
    }

    /**
     * @deprecated Prefer syncAdmins(). Maps single id / null onto a full sync.
     * Null clears all admins; a single id sets exactly that one admin (legacy).
     */
    public function assign(Firm $firm, ?int $headUserId, User $actor, ?Request $request = null): Firm
    {
        return $this->syncAdmins(
            $firm,
            $headUserId !== null ? [$headUserId] : [],
            $actor,
            $request,
        );
    }

    /**
     * Members eligible to be firm admin, with is_admin flags.
     *
     * @return list<array{id: int, name: string, email: string, is_admin: bool}>
     */
    public function eligibleMembers(Firm $firm): array
    {
        $adminIds = array_fill_keys($this->adminUserIds($firm), true);

        return User::query()
            ->where('firm_id', $firm->id)
            ->where(function ($q) {
                $q->where('is_discontinued', false)->orWhereNull('is_discontinued');
            })
            ->orderBy('name')
            ->get(['id', 'name', 'email'])
            ->map(fn (User $u) => [
                'id' => (int) $u->id,
                'name' => (string) $u->name,
                'email' => (string) $u->email,
                'is_admin' => isset($adminIds[(int) $u->id]),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<int>
     */
    public function adminUserIds(Firm $firm): array
    {
        $role = $this->headRoles->headRoleForFirm($firm);
        $roles = app(UserRolesService::class);

        return User::query()
            ->where('firm_id', $firm->id)
            ->where(function ($q) {
                $q->where('is_discontinued', false)->orWhereNull('is_discontinued');
            })
            ->get()
            ->filter(function (User $u) use ($roles, $role) {
                return in_array($role, $roles->roleKeys($u), true);
            })
            ->map(fn (User $u) => (int) $u->id)
            ->values()
            ->all();
    }

    /**
     * @param  list<int|string>  $ids
     * @return list<int>
     */
    private function normalizeIds(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0 && ! in_array($id, $out, true)) {
                $out[] = $id;
            }
        }

        return $out;
    }
}
