<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Hub;
use App\Services\ActingHubService;
use App\Support\TermsAndConditionsDefaults;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TermsController extends Controller
{
    public function __construct(
        private readonly ActingHubService $actingHubs
    ) {}

    public function show(Request $request): JsonResponse
    {
        $hub = $this->targetHub($request);
        $terms = $hub->resolvedTerms();

        return response()->json([
            'hub' => $this->hubPayload($hub),
            'terms' => [
                'content' => $terms['content'],
                'version' => $terms['version'],
                'updated_at' => $terms['updated_at'],
                'is_custom' => $terms['is_custom'],
                'default_content' => TermsAndConditionsDefaults::forType((string) $hub->type),
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
                'message' => 'Terms & Conditions content cannot be empty.',
            ], 422);
        }

        $hub = $this->targetHub($request);
        $current = is_array($hub->terms_and_conditions) ? $hub->terms_and_conditions : [];
        $version = max(1, (int) ($current['version'] ?? 1)) + 1;

        $hub->terms_and_conditions = [
            'content' => $content,
            'version' => $version,
            'updated_at' => now()->toIso8601String(),
        ];
        $hub->save();

        $terms = $hub->fresh()->resolvedTerms();

        return response()->json([
            'message' => 'Terms & Conditions updated. Users who have not accepted this version will be asked on next login.',
            'hub' => $this->hubPayload($hub),
            'terms' => [
                'content' => $terms['content'],
                'version' => $terms['version'],
                'updated_at' => $terms['updated_at'],
                'is_custom' => $terms['is_custom'],
            ],
        ]);
    }

    public function reset(Request $request): JsonResponse
    {
        $hub = $this->targetHub($request);
        $current = is_array($hub->terms_and_conditions) ? $hub->terms_and_conditions : [];
        $version = max(1, (int) ($current['version'] ?? 1)) + 1;

        $hub->terms_and_conditions = [
            'content' => TermsAndConditionsDefaults::forType((string) $hub->type),
            'version' => $version,
            'updated_at' => now()->toIso8601String(),
        ];
        $hub->save();

        $terms = $hub->fresh()->resolvedTerms();

        return response()->json([
            'message' => 'Terms & Conditions reset to the default content for this hub type.',
            'hub' => $this->hubPayload($hub),
            'terms' => [
                'content' => $terms['content'],
                'version' => $terms['version'],
                'updated_at' => $terms['updated_at'],
                'is_custom' => $terms['is_custom'],
                'default_content' => TermsAndConditionsDefaults::forType((string) $hub->type),
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
