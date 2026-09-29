<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ContentPush;
use App\Models\Hub;
use App\Models\Post;
use App\Services\ContentPushService;
use App\Services\HubService;
use App\Services\WhiteLabelDatabaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class ContentPushController extends Controller
{
    public function __construct(
        private readonly ContentPushService $pushes,
        private readonly WhiteLabelDatabaseService $remoteDb,
        private readonly HubService $hubs
    ) {}

    public function targets(): JsonResponse
    {
        $this->assertSharedHub();

        return response()->json([
            'hubs' => $this->pushes->eligibleTargetHubs(),
        ]);
    }

    public function posts(Request $request): JsonResponse
    {
        $this->assertSharedHub();

        $perPage = min(100, max(1, (int) $request->integer('per_page', 50)));

        $posts = Post::query()
            ->where('is_active', true)
            ->latest()
            ->paginate($perPage);

        return response()->json($posts);
    }

    public function recent(): JsonResponse
    {
        $this->assertSharedHub();

        $rows = ContentPush::query()
            ->with([
                'post:id,title',
                'targetHub:id,name,slug',
                'actor:id,name,email',
            ])
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn (ContentPush $push) => [
                'id' => $push->id,
                'status' => $push->status,
                'message' => $push->message,
                'remote_post_id' => $push->remote_post_id,
                'entity_type' => $push->entity_type ?: 'post',
                'entity_label' => $push->entity_label ?: $push->post?->title,
                'post' => $push->post ? [
                    'id' => $push->post->id,
                    'title' => $push->post->title,
                ] : null,
                'target_hub' => $push->targetHub ? [
                    'id' => $push->targetHub->id,
                    'name' => $push->targetHub->name,
                    'slug' => $push->targetHub->slug,
                ] : null,
                'pushed_by' => $push->actor?->name,
                'created_at' => $push->created_at,
            ]);

        return response()->json(['pushes' => $rows]);
    }

    public function push(Request $request): JsonResponse
    {
        $this->assertSharedHub();

        $validated = $request->validate([
            'post_ids' => ['required', 'array', 'min:1'],
            'post_ids.*' => ['integer', 'distinct', 'exists:posts,id'],
            'hub_ids' => ['required', 'array', 'min:1'],
            'hub_ids.*' => ['integer', 'distinct', 'exists:hubs,id'],
        ]);

        try {
            $result = $this->pushes->pushPosts(
                array_map('intval', $validated['post_ids']),
                array_map('intval', $validated['hub_ids']),
                $request->user()
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $message = $result['pushed'] > 0
            ? "Pushed {$result['pushed']} post(s)."
            : 'No posts were pushed.';
        if ($result['failed'] > 0) {
            $message .= " {$result['failed']} failed.";
        }

        return response()->json([
            'message' => $message,
            ...$result,
        ]);
    }

    public function testConnection(Hub $hub): JsonResponse
    {
        $this->assertSharedHub();

        if ($hub->isShared()) {
            return response()->json([
                'ok' => false,
                'message' => 'Shared hub does not use a remote database.',
            ], 422);
        }

        $result = $this->remoteDb->test($hub);

        return response()->json($result, $result['ok'] ? 200 : 422);
    }

    private function assertSharedHub(): void
    {
        if (! $this->hubs->current()->isShared()) {
            abort(403, 'Content push is only available on the shared hub.');
        }
    }
}
