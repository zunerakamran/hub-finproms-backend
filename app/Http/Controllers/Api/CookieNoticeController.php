<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Hub;
use App\Services\ActingHubService;
use App\Support\CookieNoticeDefaults;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CookieNoticeController extends Controller
{
    public function __construct(
        private readonly ActingHubService $actingHubs
    ) {}

    public function show(Request $request): JsonResponse
    {
        $hub = $this->targetHub($request);
        $cookies = $hub->resolvedCookieNotice();

        return response()->json([
            'hub' => $this->hubPayload($hub),
            'cookies' => [
                'content' => $cookies['content'],
                'version' => $cookies['version'],
                'updated_at' => $cookies['updated_at'],
                'is_custom' => $cookies['is_custom'],
                'essential_only' => true,
                'default_content' => CookieNoticeDefaults::forType((string) $hub->type),
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'content' => ['required', 'string', 'max:100000'],
        ]);

        $content = trim($validated['content']);
        if ($content === '' || strip_tags($content) === '') {
            return response()->json([
                'message' => 'Cookie notice content cannot be empty.',
            ], 422);
        }

        $hub = $this->targetHub($request);
        $current = is_array($hub->cookie_notice) ? $hub->cookie_notice : [];
        $version = max(1, (int) ($current['version'] ?? 1)) + 1;

        $hub->cookie_notice = [
            'content' => $content,
            'version' => $version,
            'updated_at' => now()->toIso8601String(),
        ];
        $hub->save();

        $cookies = $hub->fresh()->resolvedCookieNotice();

        return response()->json([
            'message' => 'Cookie notice updated. Visitors who have not dismissed this version will see the banner again.',
            'hub' => $this->hubPayload($hub),
            'cookies' => [
                'content' => $cookies['content'],
                'version' => $cookies['version'],
                'updated_at' => $cookies['updated_at'],
                'is_custom' => $cookies['is_custom'],
                'essential_only' => true,
            ],
        ]);
    }

    public function reset(Request $request): JsonResponse
    {
        $hub = $this->targetHub($request);
        $current = is_array($hub->cookie_notice) ? $hub->cookie_notice : [];
        $version = max(1, (int) ($current['version'] ?? 1)) + 1;

        $hub->cookie_notice = [
            'content' => CookieNoticeDefaults::forType((string) $hub->type),
            'version' => $version,
            'updated_at' => now()->toIso8601String(),
        ];
        $hub->save();

        $cookies = $hub->fresh()->resolvedCookieNotice();

        return response()->json([
            'message' => 'Cookie notice reset to the default content for this hub type.',
            'hub' => $this->hubPayload($hub),
            'cookies' => [
                'content' => $cookies['content'],
                'version' => $cookies['version'],
                'updated_at' => $cookies['updated_at'],
                'is_custom' => $cookies['is_custom'],
                'essential_only' => true,
                'default_content' => CookieNoticeDefaults::forType((string) $hub->type),
            ],
        ]);
    }

    private function targetHub(Request $request): Hub
    {
        return $this->actingHubs->targetHub($request->user());
    }

    /**
     * @return array{id: int, name: string, slug: string, type: string}
     */
    private function hubPayload(Hub $hub): array
    {
        return [
            'id' => $hub->id,
            'name' => $hub->name,
            'slug' => $hub->slug,
            'type' => $hub->type,
        ];
    }
}
