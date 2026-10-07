<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\CreatesOnActingWhiteLabelHub;
use App\Models\FirmDocument;
use App\Models\FirmDocumentCategory;
use App\Models\Hub;
use App\Models\User;
use App\Services\ActingHubService;
use App\Services\FirmDocumentAccessService;
use App\Services\HubService;
use App\Services\WhiteLabelFirmDocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class FirmDocumentCategoryController extends Controller
{
    use CreatesOnActingWhiteLabelHub;

    public function __construct(
        private readonly HubService $hubs,
        private readonly ActingHubService $actingHubs,
        private readonly FirmDocumentAccessService $access,
        private readonly WhiteLabelFirmDocumentService $whiteLabelDocuments,
    ) {}

    private function capabilityHub(?User $user): Hub
    {
        if ($user) {
            return $this->actingHubs->capabilityHub($user, 'firm_documents_manage_categories');
        }

        return $this->hubs->current();
    }

    private function assertCanManage(User $user, Hub $hub): void
    {
        if (! $hub->hasFirmDocumentsFunctionality()) {
            throw new HttpException(403, 'Firm documents are disabled for this hub. Enable Functionalities → Firm documents first.');
        }

        if (! app(\App\Services\CapabilitiesMatrixService::class)->userCan($hub, $user, 'firm_documents_manage_categories')) {
            throw new HttpException(403, 'You do not have permission to manage firm document categories.');
        }
    }

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($hub = $this->actingWhiteLabelHub($request)) {
            try {
                $categories = $this->whiteLabelDocuments->listCategories($hub);
            } catch (InvalidArgumentException $e) {
                return $this->actingHubNotFoundOrValidation($e);
            }

            return response()->json([
                'categories' => $categories,
                'target_hub' => $this->targetHubPayload($hub),
                'acting_on_white_label' => true,
            ]);
        }

        // Dropdown list: any authenticated user (same pattern as GC content types).
        $categories = FirmDocumentCategory::query()
            ->orderBy('name')
            ->get(['id', 'name', 'slug']);

        $counts = FirmDocument::query()
            ->whereNotNull('category_id')
            ->selectRaw('category_id, COUNT(*) as usage_count')
            ->groupBy('category_id')
            ->pluck('usage_count', 'category_id');

        return response()->json([
            'categories' => $categories->map(fn (FirmDocumentCategory $category) => $category->toApiArray(
                (int) ($counts[$category->id] ?? 0)
            ))->values(),
            'acting_on_white_label' => false,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $hub = $this->capabilityHub($user);
        $this->assertCanManage($user, $hub);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'slug' => ['nullable', 'string', 'max:100'],
        ]);

        if ($remote = $this->actingWhiteLabelHub($request)) {
            try {
                $category = $this->whiteLabelDocuments->createCategory($remote, $validated);
            } catch (InvalidArgumentException $e) {
                $msg = $e->getMessage();
                if (str_contains($msg, 'name is already')) {
                    throw ValidationException::withMessages(['name' => $msg]);
                }
                if (str_contains($msg, 'slug is already')) {
                    throw ValidationException::withMessages(['slug' => $msg]);
                }

                return $this->actingHubNotFoundOrValidation($e);
            }

            return response()->json([
                'message' => 'Category created successfully.',
                'category' => $category,
                'target_hub' => $this->targetHubPayload($remote),
                'acting_on_white_label' => true,
            ], 201);
        }

        $name = trim($validated['name']);
        $slug = trim((string) ($validated['slug'] ?? '')) ?: Str::slug($name);

        if (FirmDocumentCategory::query()->where('name', $name)->exists()) {
            throw ValidationException::withMessages([
                'name' => 'That category name is already in use.',
            ]);
        }
        if (FirmDocumentCategory::query()->where('slug', $slug)->exists()) {
            throw ValidationException::withMessages([
                'slug' => 'That slug is already in use.',
            ]);
        }

        $category = FirmDocumentCategory::query()->create([
            'name' => $name,
            'slug' => $slug,
        ]);

        return response()->json([
            'message' => 'Category created successfully.',
            'category' => $category->toApiArray(),
            'acting_on_white_label' => false,
        ], 201);
    }

    public function update(Request $request, int $firmDocumentCategory): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $hub = $this->capabilityHub($user);
        $this->assertCanManage($user, $hub);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'slug' => ['nullable', 'string', 'max:100'],
        ]);

        if ($remote = $this->actingWhiteLabelHub($request)) {
            try {
                $category = $this->whiteLabelDocuments->updateCategory($remote, $firmDocumentCategory, $validated);
            } catch (InvalidArgumentException $e) {
                $msg = $e->getMessage();
                if (str_contains($msg, 'name is already')) {
                    throw ValidationException::withMessages(['name' => $msg]);
                }
                if (str_contains($msg, 'slug is already')) {
                    throw ValidationException::withMessages(['slug' => $msg]);
                }

                return $this->actingHubNotFoundOrValidation($e);
            }

            return response()->json([
                'message' => 'Category updated successfully.',
                'category' => $category,
                'target_hub' => $this->targetHubPayload($remote),
                'acting_on_white_label' => true,
            ]);
        }

        $model = FirmDocumentCategory::query()->findOrFail($firmDocumentCategory);

        $newName = trim($validated['name']);
        $slug = array_key_exists('slug', $validated) && filled($validated['slug'])
            ? trim($validated['slug'])
            : Str::slug($newName);

        if (FirmDocumentCategory::query()
            ->where('name', $newName)
            ->where('id', '!=', $model->id)
            ->exists()) {
            throw ValidationException::withMessages([
                'name' => 'That category name is already in use.',
            ]);
        }
        if (FirmDocumentCategory::query()
            ->where('slug', $slug)
            ->where('id', '!=', $model->id)
            ->exists()) {
            throw ValidationException::withMessages([
                'slug' => 'That slug is already in use.',
            ]);
        }

        $model->update([
            'name' => $newName,
            'slug' => $slug,
        ]);

        return response()->json([
            'message' => 'Category updated successfully.',
            'category' => $model->fresh()->toApiArray(),
            'acting_on_white_label' => false,
        ]);
    }

    public function destroy(Request $request, int $firmDocumentCategory): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $hub = $this->capabilityHub($user);
        $this->assertCanManage($user, $hub);

        if ($remote = $this->actingWhiteLabelHub($request)) {
            try {
                $this->whiteLabelDocuments->deleteCategory($remote, $firmDocumentCategory);
            } catch (InvalidArgumentException $e) {
                if (str_contains($e->getMessage(), 'Cannot delete')) {
                    return response()->json(['message' => $e->getMessage()], 422);
                }

                return $this->actingHubNotFoundOrValidation($e);
            }

            return response()->json([
                'message' => 'Category deleted successfully.',
                'target_hub' => $this->targetHubPayload($remote),
                'acting_on_white_label' => true,
            ]);
        }

        $model = FirmDocumentCategory::query()->findOrFail($firmDocumentCategory);
        $inUse = FirmDocument::query()->where('category_id', $model->id)->exists();

        if ($inUse) {
            return response()->json([
                'message' => 'Cannot delete a category that is used by firm documents.',
            ], 422);
        }

        $model->delete();

        return response()->json([
            'message' => 'Category deleted successfully.',
            'acting_on_white_label' => false,
        ]);
    }
}
