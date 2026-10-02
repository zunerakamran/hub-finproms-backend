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

        return response()->json([
            'firm' => $model->load('headUser:id,name,email')->toApiArray(),
            'members' => $this->heads->eligibleMembers($model),
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

                $updated = $this->whiteLabelFirms->assignHead($hub, $firm, $headUserId);
            } catch (InvalidArgumentException $e) {
                $notFound = str_contains(strtolower($e->getMessage()), 'not found');

                return response()->json(['message' => $e->getMessage()], $notFound ? 404 : 422);
            }

            $this->logRemoteHeadChange($request, $updated, $previous);

            $message = ($updated['head_user_id'] ?? null)
                ? 'Head of Firm updated successfully.'
                : 'Head of Firm cleared successfully.';

            return response()->json([
                'message' => $message,
                'firm' => $updated,
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

        $message = $updated->head_user_id
            ? 'Head of Firm updated successfully.'
            : 'Head of Firm cleared successfully.';

        return response()->json([
            'message' => $message,
            'firm' => $updated->toApiArray(),
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

        if ($next === null) {
            $action = 'firm.head.clear';
            $description = 'Cleared Head of Firm for “'.($firm['name'] ?? '').'” (remote hub)';
        } elseif ($prev === null) {
            $action = 'firm.head.assign';
            $description = 'Appointed '.($firm['head_user']['name'] ?? 'user')
                .' as Head of Firm for “'.($firm['name'] ?? '').'” (remote hub)';
        } else {
            $action = 'firm.head.replace';
            $description = 'Replaced Head of Firm for “'.($firm['name'] ?? '').'” (remote hub)';
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
