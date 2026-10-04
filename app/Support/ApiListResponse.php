<?php

namespace App\Support;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Consistent paginated JSON for concurrent-safe list endpoints.
 */
final class ApiListResponse
{
    public static function perPage(Request $request, int $default = 50, int $max = 100): int
    {
        $perPage = (int) $request->input('per_page', $default);

        return max(1, min($max, $perPage > 0 ? $perPage : $default));
    }

    /**
     * @param  LengthAwarePaginator<int, mixed>  $paginator
     * @param  callable(mixed): mixed|null  $map
     */
    public static function fromPaginator(LengthAwarePaginator $paginator, ?callable $map = null): JsonResponse
    {
        $items = $paginator->getCollection();
        if ($map) {
            $items = $items->map($map)->values();
        } else {
            $items = $items->values();
        }

        return response()->json([
            'data' => $items,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /**
     * @param  Collection<int, mixed>|array<int, mixed>  $items
     */
    public static function fromCollection(Collection|array $items, Request $request, int $default = 50, int $max = 100): JsonResponse
    {
        $collection = $items instanceof Collection ? $items->values() : collect($items)->values();
        $perPage = self::perPage($request, $default, $max);
        $page = max(1, (int) $request->input('page', 1));
        $total = $collection->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $slice = $collection->forPage($page, $perPage)->values();

        return response()->json([
            'data' => $slice,
            'meta' => [
                'current_page' => min($page, $lastPage),
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $total,
            ],
        ]);
    }
}
