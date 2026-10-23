<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\CreatesOnActingWhiteLabelHub;
use App\Models\Firm;
use App\Models\User;
use App\Services\WhiteLabelFirmService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
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
            ->with([
                'complianceAuthorityFirms:id,name,is_central',
                'complianceVisibleToFirm:id,name',
                'headUser:id,name,email',
            ])
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
        $authorityIds = $this->resolveAuthorityFirmIds($validated);

        $firm = Firm::query()->create([
            'name' => trim($validated['name']),
            'is_central' => false,
            'compliance_visible_to_own' => $validated['compliance_visible_to_own'] ?? true,
            'compliance_visible_to_central' => false,
            'compliance_visible_to_firm_id' => null,
        ]);

        $firm->syncComplianceAuthorities($authorityIds ?? []);

        return response()->json([
            'message' => 'Firm created successfully.',
            'firm' => $firm->fresh()
                ->load(['complianceAuthorityFirms:id,name,is_central', 'complianceVisibleToFirm:id,name'])
                ->toApiArray(),
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
        $authorityIds = $this->resolveAuthorityFirmIds($validated, $model);

        if ($authorityIds !== null) {
            foreach ($authorityIds as $authorityId) {
                if ((int) $authorityId === (int) $model->id) {
                    return response()->json([
                        'message' => 'A firm cannot select itself under Delegated compliance access. Use “Within the Firm” instead.',
                    ], 422);
                }
            }
        }

        $payload = [
            'name' => trim($validated['name']),
        ];

        if (array_key_exists('compliance_visible_to_own', $validated)) {
            $payload['compliance_visible_to_own'] = (bool) $validated['compliance_visible_to_own'];
        }

        $model->update($payload);

        if ($authorityIds !== null) {
            $model->syncComplianceAuthorities($authorityIds);
        }

        return response()->json([
            'message' => 'Firm updated successfully.',
            'firm' => $model->fresh()
                ->load(['complianceAuthorityFirms:id,name,is_central', 'headUser:id,name,email', 'complianceVisibleToFirm:id,name'])
                ->toApiArray(),
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

        $authorityIdRules = ['integer'];
        if (! $skipUnique) {
            $authorityIdRules[] = Rule::exists('firms', 'id');
        }

        return $request->validate([
            'name' => $nameRules,
            'compliance_visible_to_own' => ['sometimes', 'boolean'],
            'compliance_authority_firm_ids' => ['sometimes', 'array'],
            'compliance_authority_firm_ids.*' => $authorityIdRules,
            // Legacy fields (still accepted; merged into multi-select).
            'compliance_visible_to_central' => ['sometimes', 'boolean'],
            'compliance_visible_to_firm_id' => $otherFirmRules,
        ]);
    }

    /**
     * Build authority firm IDs from new multi-select and/or legacy fields.
     *
     * @param  array<string, mixed>  $validated
     * @return list<int>|null  null = do not change authorities on update
     */
    private function resolveAuthorityFirmIds(array $validated, ?Firm $existing = null): ?array
    {
        if (array_key_exists('compliance_authority_firm_ids', $validated)) {
            return array_values(array_unique(array_map(
                static fn ($id) => (int) $id,
                $validated['compliance_authority_firm_ids'] ?? []
            )));
        }

        $hasLegacy = array_key_exists('compliance_visible_to_central', $validated)
            || array_key_exists('compliance_visible_to_firm_id', $validated);

        if (! $hasLegacy) {
            // Create with no authority fields → empty list; update → leave unchanged.
            return $existing ? null : [];
        }

        $ids = [];
        $wantCentral = array_key_exists('compliance_visible_to_central', $validated)
            ? (bool) $validated['compliance_visible_to_central']
            : (bool) ($existing?->compliance_visible_to_central);

        if ($wantCentral) {
            $centralId = Firm::query()->where('is_central', true)->value('id');
            if ($centralId) {
                $ids[] = (int) $centralId;
            }
        }

        $peerId = array_key_exists('compliance_visible_to_firm_id', $validated)
            ? $validated['compliance_visible_to_firm_id']
            : $existing?->compliance_visible_to_firm_id;

        if ($peerId !== null && $peerId !== '') {
            $ids[] = (int) $peerId;
        }

        // When only one legacy field is patched, keep other authorities from existing pivot.
        if ($existing && Schema::hasTable('firm_compliance_authorities')) {
            $current = $existing->complianceAuthorityFirmIds();
            $centralId = Firm::query()->where('is_central', true)->value('id');
            $centralId = $centralId ? (int) $centralId : null;

            if (! array_key_exists('compliance_visible_to_central', $validated) && $centralId) {
                foreach ($current as $id) {
                    if ((int) $id === $centralId) {
                        $ids[] = $centralId;
                    }
                }
            }
            if (! array_key_exists('compliance_visible_to_firm_id', $validated)) {
                foreach ($current as $id) {
                    if ($centralId === null || (int) $id !== $centralId) {
                        $ids[] = (int) $id;
                    }
                }
            }
        }

        return array_values(array_unique(array_filter($ids)));
    }
}
