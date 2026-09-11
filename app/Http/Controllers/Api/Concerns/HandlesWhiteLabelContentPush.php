<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\Hub;
use App\Services\CapabilitiesMatrixService;
use App\Services\ContentPushService;
use App\Services\HubService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

trait HandlesWhiteLabelContentPush
{
    /**
     * @return list<int>
     */
    protected function validatedPushToHubIds(Request $request): array
    {
        if ($request->has('push_to_hub_ids') && is_string($request->input('push_to_hub_ids'))) {
            $decoded = json_decode($request->input('push_to_hub_ids'), true);
            $request->merge([
                'push_to_hub_ids' => (json_last_error() === JSON_ERROR_NONE && is_array($decoded))
                    ? array_values(array_map('intval', $decoded))
                    : [],
            ]);
        }

        if (! $request->filled('push_to_hub_ids')) {
            return [];
        }

        $validated = $request->validate([
            'push_to_hub_ids' => ['array'],
            'push_to_hub_ids.*' => ['integer', 'distinct', 'exists:hubs,id'],
        ]);

        $hubIds = array_values(array_unique(array_map('intval', $validated['push_to_hub_ids'] ?? [])));
        if ($hubIds === []) {
            return [];
        }

        $hubs = app(HubService::class);
        $current = $hubs->current();
        if (! $current->isShared()) {
            throw ValidationException::withMessages([
                'push_to_hub_ids' => ['Pushing to white-label hubs is only available on the shared hub.'],
            ]);
        }

        $actor = $request->user();
        $matrix = app(CapabilitiesMatrixService::class);
        if (! $actor || ! $matrix->roleCan($current, (string) $actor->role, 'dashboard_push_content')) {
            throw new HttpException(
                403,
                'Pushing to white-label hubs is disabled for your role. Enable “Push added content to white-labelled hubs” in Capabilities.'
            );
        }

        $invalid = Hub::query()
            ->whereIn('id', $hubIds)
            ->where(function ($q) {
                $q->where('type', '!=', Hub::TYPE_WHITE_LABEL)
                    ->orWhere('is_active', false);
            })
            ->pluck('name')
            ->all();

        if ($invalid !== []) {
            throw ValidationException::withMessages([
                'push_to_hub_ids' => ['Only active white-label hubs can be selected.'],
            ]);
        }

        return $hubIds;
    }

    /**
     * @param  list<int>  $hubIds
     * @return array<string, mixed>|null
     */
    protected function pushCreatedContent(string $entityType, mixed $model, array $hubIds, Request $request): ?array
    {
        if ($hubIds === []) {
            return null;
        }

        /** @var ContentPushService $pushes */
        $pushes = app(ContentPushService::class);

        return match ($entityType) {
            'post' => $pushes->pushPosts([(int) $model->id], $hubIds, $request->user()),
            'category' => $pushes->pushCategories([(int) $model->id], $hubIds, $request->user()),
            'type' => $pushes->pushContentTypes([(int) $model->id], $hubIds, $request->user()),
            'tag' => $pushes->pushTags([(int) $model->id], $hubIds, $request->user()),
            'bundle' => $pushes->pushBundles([(int) $model->id], $hubIds, $request->user()),
            default => null,
        };
    }
}
