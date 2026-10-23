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

            return response()->json(array_merge($payload, [
                'target_hub' => $this->targetHubPayload($hub),
                'acting_on_white_label' => true,
            ]));
        }

        $model = Firm::query()->findOrFail($firm);

        $payload = $model->load('headUser:id,name,email')->toApiArray();

        return response()->json([
            'firm' => $payload,
            'members' => $this->heads->eligibleMembers($model),
            'admin_role' => $model->isCentral() ? 'network_admin' : 'firm_admin',
            'admin_role_label' => $model->isCentral() ? 'Network Admin' : 'Firm Admin',
            'acting_on_white_label' => false,
        ]);
    }

    public function assign(Request $request, int $firm): JsonResponse
    {
        if (! $request->exists('head_user_id')) {
            return response()->json([
                'message' => 'head_user_id is required (send null to clear).',
            ], 422);
        }

        $headUserId = $request->input('head_user_id');
        $headUserId = $headUserId !== null && $headUserId !== ''
            ? (int) $headUserId
            : null;

        if ($hub = $this->actingWhiteLabelHub($request)) {
            try {
                $previous = null;
                try {
                    $before = $this->whiteLabelFirms->members($hub, $firm);
                    $previous = $before['firm']['head_user_id'] ?? null;
                } catch (InvalidArgumentException) {
                    $previous = null;
                }

                $updated = $this->whiteLabelFirms->assignHead(
                    $hub,
                    $firm,
                    $headUserId,
                    $request->user(),
                    $request,
                );
            } catch (InvalidArgumentException $e) {
                $notFound = str_contains(strtolower($e->getMessage()), 'not found');

                return response()->json(['message' => $e->getMessage()], $notFound ? 404 : 422);
            }

            $this->logRemoteHeadChange($request, $updated, $previous);

            $roleLabel = ! empty($updated['is_central']) ? 'Network Admin' : 'Firm Admin';
            $message = ($updated['head_user_id'] ?? null)
                ? 'Firm admin updated successfully. Selected user now has the '.$roleLabel.' role.'
                : 'Firm admin cleared successfully. The '.$roleLabel.' role was removed from the previous admin.';

            return response()->json([
                'message' => $message,
                'firm' => $updated,
                'assigned_role' => ($updated['head_user_id'] ?? null)
                    ? (! empty($updated['is_central']) ? 'network_admin' : 'firm_admin')
                    : null,
                'target_hub' => $this->targetHubPayload($hub),
                'acting_on_white_label' => true,
            ]);
        }

        $model = Firm::query()->findOrFail($firm);

        if ($headUserId !== null) {
            $request->validate([
                'head_user_id' => ['integer', 'exists:users,id'],
            ]);
        }

        $updated = $this->heads->assign(
            $model,
            $headUserId,
            $request->user(),
            $request,
        );

        $roleKey = $updated->isCentral() ? 'network_admin' : 'firm_admin';
        $roleLabel = $updated->isCentral() ? 'Network Admin' : 'Firm Admin';
        $message = $updated->head_user_id
            ? 'Firm admin updated successfully. Selected user now has the '.$roleLabel.' role.'
            : 'Firm admin cleared successfully. The '.$roleLabel.' role was removed from the previous admin.';

        return response()->json([
            'message' => $message,
            'firm' => $updated->toApiArray(),
            'assigned_role' => $updated->head_user_id ? $roleKey : null,
            'acting_on_white_label' => false,
        ]);
    }

    /**
     * @param  array<string, mixed>  $firm
     */
    private function logRemoteHeadChange(Request $request, array $firm, mixed $previousHeadId): void
    {
        $newHeadId = $firm['head_user_id'] ?? null;
        $prev = $previousHeadId ? (int) $previousHeadId : null;
        $next = $newHeadId ? (int) $newHeadId : null;

        if ($prev === $next) {
            return;
        }

        $roleLabel = ! empty($firm['is_central']) ? 'Network Admin' : 'Firm Admin';

        if ($next === null) {
            $action = 'firm.head.clear';
            $description = 'Cleared firm admin ('.$roleLabel.') for “'.($firm['name'] ?? '').'” (remote hub)';
        } elseif ($prev === null) {
            $action = 'firm.head.assign';
            $description = 'Set '.($firm['head_user']['name'] ?? 'user')
                .' as firm admin ('.$roleLabel.') for “'.($firm['name'] ?? '').'” (remote hub)';
        } else {
            $action = 'firm.head.replace';
            $description = 'Replaced firm admin ('.$roleLabel.') for “'.($firm['name'] ?? '').'” (remote hub)';
        }

        /** @var User|null $actor */
        $actor = $request->user();

        $this->activityLogs->log([
            'action' => $action,
            'description' => $description,
            'user' => $actor,
            'hub' => $this->hubs->current(),
            'request' => $request,
            'status_code' => 200,
            'properties' => [
                'firm_id' => $firm['id'] ?? null,
                'firm_name' => $firm['name'] ?? null,
                'previous_head_user_id' => $prev,
                'head_user_id' => $next,
                'head_user_name' => $firm['head_user']['name'] ?? null,
                'acting_remotely' => true,
            ],
        ]);
    }
}
