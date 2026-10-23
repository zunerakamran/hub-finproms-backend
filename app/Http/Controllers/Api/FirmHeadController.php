<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\CreatesOnActingWhiteLabelHub;
use App\Models\Firm;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\FirmHeadService;
use App\Services\HubService;
use App\Services\WhiteLabelFirmService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class FirmHeadController extends Controller
{
    use CreatesOnActingWhiteLabelHub;

    public function __construct(
        private readonly FirmHeadService $heads,
        private readonly WhiteLabelFirmService $whiteLabelFirms,
        private readonly ActivityLogService $activityLogs,
        private readonly HubService $hubs,
    ) {}

    public function members(Request $request, int $firm): JsonResponse
    {
        if ($hub = $this->actingWhiteLabelHub($request)) {
            try {
                $payload = $this->whiteLabelFirms->members($hub, $firm);
            } catch (InvalidArgumentException $e) {
                $notFound = str_contains(strtolower($e->getMessage()), 'not found');

                return response()->json(['message' => $e->getMessage()], $notFound ? 404 : 422);
            }

            $isCentral = ! empty($payload['firm']['is_central']);

            return response()->json(array_merge($payload, [
                'admin_user_ids' => $payload['admin_user_ids'] ?? [],
                'admin_role' => $isCentral ? 'network_admin' : 'firm_admin',
                'admin_role_label' => $isCentral ? 'Network Admin' : 'Firm Admin',
                'target_hub' => $this->targetHubPayload($hub),
                'acting_on_white_label' => true,
            ]));
        }

        $model = Firm::query()->findOrFail($firm);
        $members = $this->heads->eligibleMembers($model);
        $adminUserIds = array_values(array_map(
            fn (array $m) => $m['id'],
            array_filter($members, fn (array $m) => ! empty($m['is_admin']))
        ));

        $payload = $model->load('headUser:id,name,email')->toApiArray();

        return response()->json([
            'firm' => $payload,
            'members' => $members,
            'admin_user_ids' => $adminUserIds,
            'admin_role' => $model->isCentral() ? 'network_admin' : 'firm_admin',
            'admin_role_label' => $model->isCentral() ? 'Network Admin' : 'Firm Admin',
            'acting_on_white_label' => false,
        ]);
    }

    public function assign(Request $request, int $firm): JsonResponse
    {
        $ids = $this->resolveAdminIds($request);
        if ($ids === null) {
            return response()->json([
                'message' => 'head_user_ids (array) or head_user_id is required (send [] / null to clear all admins).',
            ], 422);
        }

        if ($hub = $this->actingWhiteLabelHub($request)) {
            try {
                $updated = $this->whiteLabelFirms->syncAdmins(
                    $hub,
                    $firm,
                    $ids,
                    $request->user(),
                    $request,
                );
            } catch (InvalidArgumentException $e) {
                $notFound = str_contains(strtolower($e->getMessage()), 'not found');

                return response()->json(['message' => $e->getMessage()], $notFound ? 404 : 422);
            }

            $this->logRemoteHeadSync($request, $updated, $ids);

            $roleLabel = ! empty($updated['is_central']) ? 'Network Admin' : 'Firm Admin';
            $count = count($updated['admin_user_ids'] ?? $ids);
            $message = $count === 0
                ? 'All '.$roleLabel.' roles cleared for this firm.'
                : ($count === 1
                    ? 'Firm admin updated. 1 user now has the '.$roleLabel.' role.'
                    : 'Firm admins updated. '.$count.' users now have the '.$roleLabel.' role.');

            return response()->json([
                'message' => $message,
                'firm' => $updated,
                'admin_user_ids' => $updated['admin_user_ids'] ?? $ids,
                'assigned_role' => $count > 0
                    ? (! empty($updated['is_central']) ? 'network_admin' : 'firm_admin')
                    : null,
                'target_hub' => $this->targetHubPayload($hub),
                'acting_on_white_label' => true,
            ]);
        }

        $model = Firm::query()->findOrFail($firm);

        $request->validate([
            'head_user_ids' => ['sometimes', 'array'],
            'head_user_ids.*' => ['integer', 'exists:users,id'],
            'head_user_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
        ]);

        $updated = $this->heads->syncAdmins(
            $model,
            $ids,
            $request->user(),
            $request,
        );

        $adminIds = $this->heads->adminUserIds($updated);
        $roleKey = $updated->isCentral() ? 'network_admin' : 'firm_admin';
        $roleLabel = $updated->isCentral() ? 'Network Admin' : 'Firm Admin';
        $count = count($adminIds);
        $message = $count === 0
            ? 'All '.$roleLabel.' roles cleared for this firm.'
            : ($count === 1
                ? 'Firm admin updated. 1 user now has the '.$roleLabel.' role.'
                : 'Firm admins updated. '.$count.' users now have the '.$roleLabel.' role.');

        return response()->json([
            'message' => $message,
            'firm' => $updated->toApiArray(),
            'admin_user_ids' => $adminIds,
            'assigned_role' => $count > 0 ? $roleKey : null,
            'acting_on_white_label' => false,
        ]);
    }

    /**
     * @return list<int>|null  null when neither field was provided
     */
    private function resolveAdminIds(Request $request): ?array
    {
        if ($request->exists('head_user_ids')) {
            $raw = $request->input('head_user_ids');
            if (! is_array($raw)) {
                return null;
            }

            $ids = [];
            foreach ($raw as $id) {
                if ($id === null || $id === '') {
                    continue;
                }
                $id = (int) $id;
                if ($id > 0 && ! in_array($id, $ids, true)) {
                    $ids[] = $id;
                }
            }

            return $ids;
        }

        if ($request->exists('head_user_id')) {
            $headUserId = $request->input('head_user_id');

            return ($headUserId !== null && $headUserId !== '')
                ? [(int) $headUserId]
                : [];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $firm
     * @param  list<int>  $ids
     */
    private function logRemoteHeadSync(Request $request, array $firm, array $ids): void
    {
        $roleLabel = ! empty($firm['is_central']) ? 'Network Admin' : 'Firm Admin';
        $count = count($ids);

        /** @var User|null $actor */
        $actor = $request->user();

        $this->activityLogs->log([
            'action' => $count === 0 ? 'firm.head.clear' : 'firm.head.sync',
            'description' => $count === 0
                ? 'Cleared all '.$roleLabel.' admins for “'.($firm['name'] ?? '').'” (remote hub)'
                : 'Updated '.$roleLabel.' admins for “'.($firm['name'] ?? '').'” ('.$count.', remote hub)',
            'user' => $actor,
            'hub' => $this->hubs->current(),
            'request' => $request,
            'status_code' => 200,
            'properties' => [
                'firm_id' => $firm['id'] ?? null,
                'firm_name' => $firm['name'] ?? null,
                'head_user_ids' => $ids,
                'head_user_id' => $firm['head_user_id'] ?? null,
                'acting_remotely' => true,
            ],
        ]);
    }
}
