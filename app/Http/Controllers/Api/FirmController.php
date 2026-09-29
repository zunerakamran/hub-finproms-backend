<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\CreatesOnActingWhiteLabelHub;
use App\Models\Firm;
use App\Models\User;
use App\Services\WhiteLabelFirmService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class FirmController extends Controller
{
    use CreatesOnActingWhiteLabelHub;

    public function __construct(
        private readonly WhiteLabelFirmService $whiteLabelFirms
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:255'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 25);
        $page = max(1, (int) ($validated['page'] ?? $request->integer('page', 1)));
        $search = isset($validated['q']) ? trim((string) $validated['q']) : '';

        if ($hub = $this->actingWhiteLabelHub($request)) {
            try {
                $listed = $this->whiteLabelFirms->paginate(
                    $hub,
                    $search !== '' ? $search : null,
                    $perPage,
                    $page
                );
            } catch (InvalidArgumentException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            return response()->json(array_merge($listed, [
                'target_hub' => $this->targetHubPayload($hub),
                'acting_on_white_label' => true,
            ]));
        }

        Firm::central();

        $counts = User::query()
            ->whereNotNull('firm_id')
            ->selectRaw('firm_id, COUNT(*) as users_count')
            ->groupBy('firm_id')
            ->pluck('users_count', 'firm_id');

        $query = Firm::query()
            ->with('complianceVisibleToFirm:id,name')
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($search !== '') {
            $query->where('name', 'like', '%'.$search.'%');
        }

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        $firmOptions = Firm::query()
            ->orderBy('name')
            ->get(['id', 'name', 'is_central'])
            ->map(fn (Firm $firm) => [
                'id' => $firm->id,
                'name' => $firm->name,
                'is_central' => $firm->isCentral(),
            ])
            ->values();

        return response()->json([
            'firms' => $paginator->getCollection()
                ->map(fn (Firm $firm) => $firm->toApiArray((int) ($counts[$firm->id] ?? 0)))
                ->values(),
            'firm_options' => $firmOptions,
            'central_firm_id' => Firm::query()->where('is_central', true)->value('id'),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'acting_on_white_label' => false,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if ($hub = $this->actingWhiteLabelHub($request)) {
            $validated = $this->validatedFirmPayload($request, null, skipUnique: true);

            try {
                $firm = $this->whiteLabelFirms->create($hub, $validated);
            } catch (InvalidArgumentException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            return response()->json([
                'message' => 'Firm created successfully.',
                'firm' => $firm,
                'target_hub' => $this->targetHubPayload($hub),
                'acting_on_white_label' => true,
            ], 201);
        }

        $validated = $this->validatedFirmPayload($request);

        $firm = Firm::query()->create([
            'name' => trim($validated['name']),
            'is_central' => false,
            'compliance_visible_to_own' => $validated['compliance_visible_to_own'] ?? true,
            'compliance_visible_to_central' => $validated['compliance_visible_to_central'] ?? false,
            'compliance_visible_to_firm_id' => $validated['compliance_visible_to_firm_id'] ?? null,
        ]);

        return response()->json([
            'message' => 'Firm created successfully.',
            'firm' => $firm->load('complianceVisibleToFirm:id,name')->toApiArray(),
            'acting_on_white_label' => false,
        ], 201);
    }

    public function update(Request $request, int $firm): JsonResponse
    {
        if ($hub = $this->actingWhiteLabelHub($request)) {
            $validated = $this->validatedFirmPayload($request, null, skipUnique: true);

            try {
                $updated = $this->whiteLabelFirms->update($hub, $firm, $validated);
            } catch (InvalidArgumentException $e) {
                $notFound = str_contains(strtolower($e->getMessage()), 'not found');

                return response()->json(['message' => $e->getMessage()], $notFound ? 404 : 422);
            }

            return response()->json([
                'message' => 'Firm updated successfully.',
                'firm' => $updated,
                'target_hub' => $this->targetHubPayload($hub),
                'acting_on_white_label' => true,
            ]);
        }

        $model = Firm::query()->findOrFail($firm);
        $validated = $this->validatedFirmPayload($request, $model);

        if (array_key_exists('compliance_visible_to_firm_id', $validated)
            && $validated['compliance_visible_to_firm_id'] !== null
            && (int) $validated['compliance_visible_to_firm_id'] === (int) $model->id) {
            return response()->json([
                'message' => 'A firm cannot select itself as the other visibility firm. Use “own firm” instead.',
            ], 422);
        }

        $payload = [
            'name' => trim($validated['name']),
        ];

        if (array_key_exists('compliance_visible_to_own', $validated)) {
            $payload['compliance_visible_to_own'] = (bool) $validated['compliance_visible_to_own'];
        }
        if (array_key_exists('compliance_visible_to_central', $validated)) {
            $payload['compliance_visible_to_central'] = (bool) $validated['compliance_visible_to_central'];
        }
        if (array_key_exists('compliance_visible_to_firm_id', $validated)) {
            $payload['compliance_visible_to_firm_id'] = $validated['compliance_visible_to_firm_id'];
        }

        $model->update($payload);

        return response()->json([
            'message' => 'Firm updated successfully.',
            'firm' => $model->fresh()->load('complianceVisibleToFirm:id,name')->toApiArray(),
            'acting_on_white_label' => false,
        ]);
    }

    public function destroy(Request $request, int $firm): JsonResponse
    {
        if ($hub = $this->actingWhiteLabelHub($request)) {
            try {
                $this->whiteLabelFirms->delete($hub, $firm);
            } catch (InvalidArgumentException $e) {
                $notFound = str_contains(strtolower($e->getMessage()), 'not found');

                return response()->json(['message' => $e->getMessage()], $notFound ? 404 : 422);
            }

            return response()->json([
                'message' => 'Firm deleted successfully.',
                'target_hub' => $this->targetHubPayload($hub),
                'acting_on_white_label' => true,
            ]);
        }

        $model = Firm::query()->findOrFail($firm);

        if ($model->isCentral()) {
            return response()->json([
                'message' => 'The Central / Network firm cannot be deleted. You can rename it instead.',
            ], 422);
        }

        $inUse = User::query()->where('firm_id', $model->id)->exists();

        if ($inUse) {
            return response()->json([
                'message' => 'Cannot delete a firm that is assigned to users.',
            ], 422);
        }

        $model->delete();

        return response()->json([
            'message' => 'Firm deleted successfully.',
            'acting_on_white_label' => false,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedFirmPayload(Request $request, ?Firm $firm = null, bool $skipUnique = false): array
    {
        $nameRules = ['required', 'string', 'max:255'];
        if (! $skipUnique) {
            $unique = Rule::unique('firms', 'name');
            if ($firm) {
                $unique = $unique->ignore($firm->id);
            }
            $nameRules[] = $unique;
        }

        $otherFirmRules = ['sometimes', 'nullable', 'integer'];
        if (! $skipUnique) {
            $otherFirmRules[] = Rule::exists('firms', 'id');
        }

        return $request->validate([
            'name' => $nameRules,
            'compliance_visible_to_own' => ['sometimes', 'boolean'],
            'compliance_visible_to_central' => ['sometimes', 'boolean'],
            'compliance_visible_to_firm_id' => $otherFirmRules,
        ]);
    }
}
