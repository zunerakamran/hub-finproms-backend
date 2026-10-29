<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Hub;
use App\Models\User;
use App\Services\ActingHubService;
use App\Services\HubService;
use App\Services\LiveChatService;
use App\Support\InputSanitizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LiveChatController extends Controller
{
    public function __construct(
        private readonly LiveChatService $chat,
        private readonly HubService $hubs,
        private readonly ActingHubService $actingHubs,
    ) {}

    private function chatHub(?User $user): Hub
    {
        if ($user) {
            return $this->actingHubs->capabilityHub($user, 'lc_use_chat');
        }

        return $this->hubs->current();
    }

    public function options(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $hub = $this->chatHub($user);
        $this->chat->assertCanUse($hub, $user);

        return response()->json([
            'agents' => $this->chat->availableAgents($hub),
            'is_central_agent' => $this->chat->isCentralAgentContext($hub, $user),
        ]);
    }

    public function unread(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $hub = $this->chatHub($user);

        return response()->json($this->chat->unreadSummary($hub, $user));
    }

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $hub = $this->chatHub($user);

        return response()->json([
            'data' => $this->chat->listConversations($hub, $user),
            'is_central_agent' => $this->chat->isCentralAgentContext($hub, $user),
        ]);
    }

    public function show(Request $request, int $conversation): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $hub = $this->chatHub($user);
        $sourceHubSlug = $request->query('source_hub_slug');

        return response()->json([
            'data' => $this->chat->showConversation(
                $hub,
                $user,
                $conversation,
                is_string($sourceHubSlug) ? $sourceHubSlug : null
            ),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $hub = $this->chatHub($user);

        $validated = $request->validate([
            'central_user_id' => ['required', 'integer', 'min:1'],
            'message' => ['nullable', 'string', 'max:10000'],
        ]);
        $validated = InputSanitizer::plainKeys($validated, ['message']);

        $data = $this->chat->startConversation(
            $hub,
            $user,
            (int) $validated['central_user_id'],
            $validated['message'] ?? null
        );

        return response()->json([
            'message' => 'Conversation started.',
            'data' => $data,
        ], 201);
    }

    public function sendMessage(Request $request, int $conversation): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $hub = $this->chatHub($user);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:10000'],
            'source_hub_slug' => ['nullable', 'string', 'max:100'],
        ]);
        $validated = InputSanitizer::plainKeys($validated, ['body', 'source_hub_slug']);

        $data = $this->chat->sendMessage(
            $hub,
            $user,
            $conversation,
            (string) $validated['body'],
            $validated['source_hub_slug'] ?? null
        );

        return response()->json([
            'message' => 'Message sent.',
            'data' => $data,
        ], 201);
    }

    public function accessIndex(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $hub = $this->actingHubs->capabilityHub($user, 'lc_manage_access');
        $this->chat->assertCanManageAccess($hub, $user);

        return response()->json([
            'data' => $this->chat->accessPickerUsers($hub),
            'agents' => $this->chat->availableAgents($hub),
        ]);
    }

    public function accessUpdate(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $hub = $this->actingHubs->capabilityHub($user, 'lc_manage_access');
        $this->chat->assertCanManageAccess($hub, $user);

        $validated = $request->validate([
            'user_ids' => ['present', 'array'],
            'user_ids.*' => ['integer', 'min:1'],
        ]);

        $agents = $this->chat->saveAccess($hub, $user, $validated['user_ids'] ?? []);

        return response()->json([
            'message' => 'Central contacts updated. Checked Central Hub users are available for Live Chat on this hub.',
            'data' => $this->chat->accessPickerUsers($hub),
            'agents' => $agents,
        ]);
    }
}
