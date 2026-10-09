<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Hub;
use App\Services\ActingHubService;
use App\Support\PrivacyPolicyDefaults;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PrivacyController extends Controller
{
    public function __construct(
        private readonly ActingHubService $actingHubs
    ) {}

    public function show(Request $request): JsonResponse
    {
        $hub = $this->targetHub($request);
        $privacy = $hub->resolvedPrivacyPolicy();

        return response()->json([
            'hub' => $this->hubPayload($hub),
            'privacy' => [
                'content' => $privacy['content'],
                'version' => $privacy['version'],
                'updated_at' => $privacy['updated_at'],
                'is_custom' => $privacy['is_custom'],
                'default_content' => PrivacyPolicyDefaults::forType((string) $hub->type),
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
                'message' => 'Privacy Policy content cannot be empty.',
            ], 422);
        }

        $hub = $this->targetHub($request);
        $current = is_array($hub->privacy_policy) ? $hub->privacy_policy : [];
        $version = max(1, (int) ($current['version'] ?? 1)) + 1;

        $hub->privacy_policy = [
            'content' => $content,
            'version' => $version,
            'updated_at' => now()->toIso8601String(),
        ];
        $hub->save();

        $privacy = $hub->fresh()->resolvedPrivacyPolicy();

        return response()->json([
            'message' => 'Privacy Policy updated. Users who have not acknowledged this version will be asked on next login.',
            'hub' => $this->hubPayload($hub),
            'privacy' => [
                'content' => $privacy['content'],
                'version' => $privacy['version'],
                'updated_at' => $privacy['updated_at'],
                'is_custom' => $privacy['is_custom'],
            ],
        ]);
    }

    public function reset(Request $request): JsonResponse
    {
        $hub = $this->targetHub($request);
        $current = is_array($hub->privacy_policy) ? $hub->privacy_policy : [];
        $version = max(1, (int) ($current['version'] ?? 1)) + 1;

        $hub->privacy_policy = [
            'content' => PrivacyPolicyDefaults::forType((string) $hub->type),
            'version' => $version,
            'updated_at' => now()->toIso8601String(),
        ];
        $hub->save();

        $privacy = $hub->fresh()->resolvedPrivacyPolicy();

        return response()->json([
            'message' => 'Privacy Policy reset to the default content for this hub type.',
            'hub' => $this->hubPayload($hub),
            'privacy' => [
                'content' => $privacy['content'],
                'version' => $privacy['version'],
                'updated_at' => $privacy['updated_at'],
                'is_custom' => $privacy['is_custom'],
                'default_content' => PrivacyPolicyDefaults::forType((string) $hub->type),
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
