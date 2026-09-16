<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Hub;
use App\Services\ActingHubService;
use App\Services\CapabilitiesMatrixService;
use App\Services\HubService;
use App\Services\SubscriberCreditsService;
use App\Services\WhiteLabelHubSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SubscriberCreditsController extends Controller
{
    public function __construct(
        private readonly HubService $hubs,
        private readonly SubscriberCreditsService $subscriberCredits,
        private readonly CapabilitiesMatrixService $matrix,
        private readonly ActingHubService $actingHubs,
        private readonly WhiteLabelHubSyncService $whiteLabelSync
    ) {}

    public function show(Request $request): JsonResponse
    {
        $hub = $this->resolveHub($request);
        $this->assertCanManage($request, $hub);

        return response()->json([
            'hub' => [
                'id' => $hub->id,
                'name' => $hub->name,
                'slug' => $hub->slug,
                'type' => $hub->type,
                'private_invite_only' => $hub->isPrivateInviteOnly(),
            ],
            'subscriber_credits' => $hub->subscriberCreditsConfig(),
            'note' => 'Changes apply to new/reactivated imports immediately; existing subscribers get the new allotment on the next monthly autorenew.',
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $hub = $this->resolveHub($request);
        $this->assertCanManage($request, $hub);

        $validated = $request->validate([
            'subscriber_credits_unlimited' => ['required', 'boolean'],
            'subscriber_credits' => ['nullable', 'integer', 'min:0', 'max:1000000'],
        ]);

        $unlimited = (bool) $validated['subscriber_credits_unlimited'];
        if ($unlimited) {
            $hub = $this->subscriberCredits->updateHubSetting($hub, null);
        } else {
            if (! array_key_exists('subscriber_credits', $validated) || $validated['subscriber_credits'] === null) {
                throw ValidationException::withMessages([
                    'subscriber_credits' => 'Enter the number of credits per subscriber, or choose Unlimited.',
                ]);
            }
            $hub = $this->subscriberCredits->updateHubSetting($hub, (int) $validated['subscriber_credits']);
        }

        $this->hubs->forgetCurrentCache();

        try {
            if ($hub->isWhiteLabel() && $hub->hasRemoteDatabaseConfigured()) {
                $this->whiteLabelSync->pushSettings($hub->fresh());
            }
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Subscriber credits updated. Existing subscribers receive the new allotment on next autorenew.',
            'hub' => [
                'id' => $hub->id,
                'name' => $hub->name,
                'slug' => $hub->slug,
                'type' => $hub->type,
                'private_invite_only' => $hub->isPrivateInviteOnly(),
            ],
            'subscriber_credits' => $hub->subscriberCreditsConfig(),
        ]);
    }

    private function resolveHub(Request $request): Hub
    {
        // Prefer the hub switcher selection; ignore legacy hub_id dropdown.
        $hub = $this->actingHubs->targetHub($request->user());

        if ($hub->isShared() || ! $hub->isPrivateInviteOnly()) {
            abort(response()->json([
                'message' => 'Subscriber credits are only configured for private invite-only hubs. Select a private white-label hub in Control hub.',
            ], 422));
        }

        return $hub;
    }

    private function assertCanManage(Request $request, Hub $hub): void
    {
        $user = $request->user();
        $checkHub = $user
            ? $this->actingHubs->capabilityHub($user, 'dashboard_manage_subscriber_credits')
            : $hub;
        if (! $user || ! $this->matrix->roleCan($checkHub, (string) $user->role, 'dashboard_manage_subscriber_credits')) {
            abort(403, 'Setting subscriber credits is disabled for your role. Enable it in Power Admin → Capabilities.');
        }
    }
}
