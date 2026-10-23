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
     * Appoint, replace, or clear the firm admin (Head of Firm).
     * Pass null head_user_id to clear.
     * Syncs network_admin (Central / Network) or firm_admin (member firm).
     */
    public function assign(Firm $firm, ?int $headUserId, User $actor, ?Request $request = null): Firm
    {
        $previousHeadId = $firm->head_user_id ? (int) $firm->head_user_id : null;
        $headRole = $this->headRoles->headRoleForFirm($firm);
        $roleLabel = $firm->isCentral() ? 'Network Admin' : 'Firm Admin';

        if ($headUserId === null) {
            if ($previousHeadId === null) {
                return $firm;
            }

            $firm->head_user_id = null;
            $firm->save();

            $this->headRoles->afterHeadAssigned(
                $firm,
                $previousHeadId,
                null,
                $actor,
                $request,
                $this->hubs->current(),
            );

            $this->activityLogs->log([
                'action' => 'firm.head.clear',
                'description' => 'Cleared firm admin ('.$roleLabel.') for “'.$firm->name.'”',
                'user' => $actor,
                'hub' => $this->hubs->current(),
                'subject' => $firm,
                'request' => $request,
                'status_code' => 200,
                'properties' => [
                    'firm_id' => $firm->id,
                    'firm_name' => $firm->name,
                    'previous_head_user_id' => $previousHeadId,
                    'head_role' => $headRole,
                ],
            ]);

            return $firm->fresh(['headUser:id,name,email']);
        }

        $head = User::query()->find($headUserId);
        if (! $head) {
            throw ValidationException::withMessages([
                'head_user_id' => 'The selected user was not found.',
            ]);
        }

        if ($head->firm_id === null || (int) $head->firm_id !== (int) $firm->id) {
            throw ValidationException::withMessages([
                'head_user_id' => 'The firm admin must be a member of this firm.',
            ]);
        }

        if ($head->isDiscontinued()) {
            throw ValidationException::withMessages([
                'head_user_id' => 'A discontinued user cannot be firm admin.',
            ]);
        }

        if ($previousHeadId === (int) $head->id) {
            // Ensure role is present even if head_user_id was already set (legacy).
            $this->headRoles->afterHeadAssigned(
                $firm,
                $previousHeadId,
                $head,
                $actor,
                $request,
                $this->hubs->current(),
            );

            return $firm->load('headUser:id,name,email');
        }

        $firm->head_user_id = $head->id;
        $firm->save();

        $this->headRoles->afterHeadAssigned(
            $firm,
            $previousHeadId,
            $head,
            $actor,
            $request,
            $this->hubs->current(),
        );

        $action = $previousHeadId ? 'firm.head.replace' : 'firm.head.assign';
        $description = $previousHeadId
            ? 'Replaced firm admin ('.$roleLabel.') for “'.$firm->name.'” with '.$head->name
            : 'Set '.$head->name.' as firm admin ('.$roleLabel.') for “'.$firm->name.'”';

        $this->activityLogs->log([
            'action' => $action,
            'description' => $description,
            'user' => $actor,
            'hub' => $this->hubs->current(),
            'subject' => $firm,
            'request' => $request,
            'status_code' => 200,
            'properties' => [
                'firm_id' => $firm->id,
                'firm_name' => $firm->name,
                'previous_head_user_id' => $previousHeadId,
                'head_user_id' => (int) $head->id,
                'head_user_name' => $head->name,
                'head_user_email' => $head->email,
                'head_role' => $headRole,
            ],
        ]);

        return $firm->fresh(['headUser:id,name,email']);
    }

    /**
     * Members eligible to become firm admin (Network Admin / Firm Admin).
     *
     * @return list<array{id: int, name: string, email: string}>
     */
    public function eligibleMembers(Firm $firm): array
    {
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
            ])
            ->values()
            ->all();
    }
}
